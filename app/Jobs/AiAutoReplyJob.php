<?php

namespace App\Jobs;

use App\Models\AiConfig;
use App\Models\Conversation;
use App\Models\FlowRun;
use App\Models\Message;
use App\Models\WhatsappConfig;
use App\Services\Ai\ReplyGenerator;
use App\Services\WhatsApp\MetaApi;
use App\Services\WhatsApp\Messenger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Bot de auto-respuesta IA. Reglas:
 *  - Solo si la config está activa y auto_reply_enabled.
 *  - Respeta el apagado por conversación (ai_autoreply_disabled).
 *  - Tope de respuestas por conversación.
 *  - No interfiere con un flow activo.
 *
 * Ronda 14 (2026-07-23):
 *  - Marca conversation.ai_pending=true al arrancar (UI muestra "IA pensando...").
 *  - Envía typing indicator a WhatsApp para que el cliente vea "escribiendo...".
 *  - Si Ollama tarda >120s o falla, envía mensaje fallback al cliente y notifica
 *    al agente responsable (o al owner si no hay asignado).
 */
class AiAutoReplyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 130; // 10s de margen sobre los 120s del Ollama Client

    private const FALLBACK_TEXT = 'Un asesor te atenderá en breve. Gracias por tu paciencia. 🙏';

    public function __construct(public readonly string $conversationId)
    {
    }

    public function handle(ReplyGenerator $generator, Messenger $messenger): void
    {
        $conversation = Conversation::find($this->conversationId);

        if (! $conversation || $conversation->ai_autoreply_disabled) {
            return;
        }

        $config = AiConfig::forAccount($conversation->account_id)
            ->where('is_active', true)
            ->where('auto_reply_enabled', true)
            ->first();

        if (! $config || $conversation->ai_reply_count >= $config->auto_reply_max_per_conversation) {
            return;
        }

        $flowActive = FlowRun::where('conversation_id', $conversation->id)
            ->where('status', FlowRun::STATUS_ACTIVE)
            ->exists();

        if ($flowActive) {
            return;
        }

        // Fuera de horario de atención: enviar mensaje configurado (una vez
        // por día por conversación para no spamear) y NO consumir Ollama.
        if (! $config->isWithinBusinessHours() && $config->after_hours_message) {
            $todayKey = now()->toDateString();
            $lastSent = cache()->get("after_hours_sent:{$conversation->id}:{$todayKey}");
            if (! $lastSent) {
                try {
                    $messenger->sendText($conversation, $config->after_hours_message);
                    cache()->put("after_hours_sent:{$conversation->id}:{$todayKey}", true, now()->endOfDay());
                } catch (\Throwable $e) {
                    Log::warning('After-hours message falló', ['conv_id' => $conversation->id, 'error' => $e->getMessage()]);
                }
            }
            return;
        }

        // Enciendo el flag efímero: la UI del Inbox pintará una burbuja
        // "IA pensando..." mientras dure este job.
        $conversation->update(['ai_pending' => true]);
        $this->broadcastPending($conversation, true);

        // Typing indicator al cliente: le llega "escribiendo..." real de WA.
        // Dura ~25s. Best-effort: si falla no bloquea al bot.
        $this->sendTypingToCustomer($conversation);

        try {
            $reply = $generator->generate($config, $conversation);

            if ($reply === '') {
                $conversation->update(['ai_pending' => false]);
            $this->broadcastPending($conversation, false);
                return;
            }

            $messenger->sendText($conversation, $reply);
            $conversation->increment('ai_reply_count');
            $conversation->update(['ai_pending' => false]);
            $this->broadcastPending($conversation, false);
        } catch (\Throwable $e) {
            Log::warning('Auto-respuesta IA falló, activando fallback', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            $conversation->update(['ai_pending' => false]);
            $this->broadcastPending($conversation, false);
            $this->deliverFallback($conversation, $messenger);
        }
    }

    /**
     * Al fallar la IA: envía un mensaje al cliente para que no quede en el aire,
     * apaga la IA en esa conversación (evita loop de fallos), y notifica al
     * agente responsable (o al owner) para que un humano tome la conversación.
     */
    private function deliverFallback(Conversation $conversation, Messenger $messenger): void
    {
        // 1. Mensaje automático al cliente por WhatsApp.
        try {
            $messenger->sendText($conversation, self::FALLBACK_TEXT);
        } catch (\Throwable $e) {
            Log::error('Fallback de IA también falló al enviar por WhatsApp', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
            // Aunque el envío falle, seguimos con la notificación al agente.
        }

        // 2. Apaga la IA en esta conversación: evita reintentos que fallen igual.
        //    El agente que la tome puede reactivar el toggle IA/Humano manualmente.
        $conversation->update(['ai_autoreply_disabled' => true]);

        // 3. Notifica al agente responsable (o al owner si no hay asignado).
        $recipientId = $conversation->assigned_agent_id
            ?? \App\Models\User::where('account_id', $conversation->account_id)
                ->where('account_role', \App\Models\User::ROLE_OWNER)
                ->value('id');

        if ($recipientId) {
            \App\Models\Notification::create([
                'account_id' => $conversation->account_id,
                'user_id' => $recipientId,
                'type' => 'ai_fallback',
                'conversation_id' => $conversation->id,
                'contact_id' => $conversation->contact_id,
                'title' => 'La IA no pudo responder',
                'body' => 'Fallo en la IA para '.($conversation->contact->name ?? $conversation->contact->phone).'. Se envió un mensaje automático al cliente. Tomá la conversación.',
            ]);
        }
    }

    /**
     * Notifica a integraciones (Komo) que la IA cambió su estado de "pensando".
     * Komo lo usa para mostrar la misma burbuja violeta que el Inbox de wacrm.
     * Best-effort: si falla no bloquea al bot.
     */
    private function broadcastPending(Conversation $conversation, bool $pending): void
    {
        try {
            app(\App\Services\Webhooks\Dispatcher::class)->dispatch(
                $conversation->account_id,
                'ai.pending_changed',
                ['conversation_id' => $conversation->id, 'pending' => $pending]
            );
        } catch (\Throwable $e) {
            Log::warning('Webhook ai.pending_changed falló', ['error' => $e->getMessage()]);
        }
    }

    /** Envía el typing indicator a WhatsApp usando el último wamid del cliente. */
    private function sendTypingToCustomer(Conversation $conversation): void
    {
        $lastCustomerWamid = Message::where('conversation_id', $conversation->id)
            ->where('sender_type', Message::SENDER_CUSTOMER)
            ->whereNotNull('message_id')
            ->latest('created_at')
            ->value('message_id');

        if (! $lastCustomerWamid) {
            return;
        }

        $config = WhatsappConfig::forAccount($conversation->account_id)
            ->where('status', 'connected')
            ->first();

        if ($config) {
            MetaApi::for($config)->sendTypingIndicator($lastCustomerWamid);
        }
    }
}
