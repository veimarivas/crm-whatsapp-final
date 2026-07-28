<?php

namespace App\Jobs;

use App\Models\AiConfig;
use App\Models\Conversation;
use App\Models\FlowRun;
use App\Models\Message;
use App\Models\Notification;
use App\Models\User;
use App\Models\WhatsappConfig;
use App\Services\Ai\ReplyGenerator;
use App\Services\Webhooks\Dispatcher;
use App\Services\WhatsApp\Messenger;
use App\Services\WhatsApp\MetaApi;
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
 *  - Si Ollama tarda >120s o falla, NO le manda nada al cliente: apaga la IA
 *    en esa conversación y avisa al agente responsable (o al owner) para que
 *    conteste un humano. Lo mismo cuando se agota el tope de respuestas.
 */
class AiAutoReplyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 130; // 10s de margen sobre los 120s del Ollama Client

    /**
     * Horas de pausa al agotar el tope, si la cuenta no configuró otra cosa.
     *
     * Tres es el equilibrio: suficiente para que un cliente confundido no
     * siga rebotando contra el bot (que es para lo que existe el tope), y lo
     * bastante corto como para que quien vuelve el mismo día encuentre
     * respuesta. Además entra holgado en la ventana de servicio de 24 h, así
     * que reactivarse no cuesta plata.
     */
    private const DEFAULT_COOLDOWN_HOURS = 3;

    public function __construct(public readonly string $conversationId) {}

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

        if (! $config) {
            return;
        }

        // Si la pausa por tope ya venció, el contador vuelve a cero y la IA
        // retoma sola. Es lo que evita que un tope alcanzado deje muerta la
        // conversación para siempre.
        if ($conversation->ai_paused_until && $conversation->ai_paused_until->isPast()) {
            $conversation->update([
                'ai_reply_count' => 0,
                'ai_paused_until' => null,
                'ai_limit_notified_at' => null,
            ]);
            $conversation->refresh();
        }

        // En pausa: se calla sin volver a avisar. El aviso ya salió cuando se
        // alcanzó el tope; repetirlo en cada mensaje sería ruido.
        if ($conversation->ai_paused_until) {
            return;
        }

        // Tope agotado: el cliente sigue escribiendo y el bot ya no contesta.
        // Es exactamente el momento en que un humano tiene que entrar, así que
        // se avisa (una sola vez) y se programa la reactivación.
        if ($conversation->ai_reply_count >= $config->auto_reply_max_per_conversation) {
            $horas = max(1, (int) ($config->auto_reply_cooldown_hours ?: self::DEFAULT_COOLDOWN_HOURS));
            $reanuda = now()->addHours($horas);

            $conversation->update(['ai_paused_until' => $reanuda]);

            $this->notifyHumanNeeded(
                $conversation,
                'limit_reached',
                'La IA llegó a su tope en esta conversación',
                'La IA ya respondió '.$conversation->ai_reply_count.' veces a '
                    .$this->contactLabel($conversation).'. Vuelve a responder a las '
                    .$reanuda->format('H:i').'; hasta entonces seguí vos.',
            );

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
            $this->deliverFallback($conversation);
        }
    }

    /**
     * Al fallar la IA: NO se le manda nada al cliente.
     *
     * Antes se enviaba un "Un asesor te atenderá en breve": delataba que
     * detrás había un bot y dejaba al cliente con una respuesta de relleno
     * en vez de una de verdad. Ahora la conversación queda tal cual y el
     * responsable se entera para contestar él.
     *
     * También apaga la IA en esa conversación: evita reintentos que fallarían
     * igual. El agente que la tome puede reactivar el toggle IA/Humano.
     */
    private function deliverFallback(Conversation $conversation): void
    {
        $conversation->update(['ai_autoreply_disabled' => true]);

        $this->notifyHumanNeeded(
            $conversation,
            'failed',
            'La IA no pudo responder',
            'Falló la IA con '.$this->contactLabel($conversation)
                .'. Al cliente no se le envió nada: contestale vos.',
        );
    }

    /**
     * Avisa que esta conversación necesita un humano — al agente asignado, o
     * al owner si todavía no tiene responsable — y reenvía el evento al CRM
     * externo (Komo) para que le llegue también al responsable del lead.
     *
     * `limit_reached` se avisa una sola vez por conversación: el tope se sigue
     * superando en cada mensaje nuevo y no queremos repetir el aviso.
     */
    private function notifyHumanNeeded(Conversation $conversation, string $reason, string $title, string $body): void
    {
        if ($reason === 'limit_reached') {
            if ($conversation->ai_limit_notified_at) {
                return;
            }
            $conversation->update(['ai_limit_notified_at' => now()]);
        }

        $recipientId = $conversation->assigned_agent_id
            ?? User::where('account_id', $conversation->account_id)
                ->where('account_role', User::ROLE_OWNER)
                ->value('id');

        if ($recipientId) {
            Notification::create([
                'account_id' => $conversation->account_id,
                'user_id' => $recipientId,
                'type' => 'ai_fallback',
                'conversation_id' => $conversation->id,
                'contact_id' => $conversation->contact_id,
                'title' => $title,
                'body' => $body,
            ]);
        }

        try {
            app(Dispatcher::class)->dispatch(
                $conversation->account_id,
                'ai.unavailable',
                [
                    'conversation_id' => $conversation->id,
                    'reason' => $reason, // 'failed' | 'limit_reached'
                    'title' => $title,
                    'body' => $body,
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('Webhook ai.unavailable falló', ['error' => $e->getMessage()]);
        }
    }

    private function contactLabel(Conversation $conversation): string
    {
        return $conversation->contact->name ?: $conversation->contact->phone;
    }

    /**
     * Notifica a integraciones (Komo) que la IA cambió su estado de "pensando".
     * Komo lo usa para mostrar la misma burbuja violeta que el Inbox de wacrm.
     * Best-effort: si falla no bloquea al bot.
     */
    private function broadcastPending(Conversation $conversation, bool $pending): void
    {
        try {
            app(Dispatcher::class)->dispatch(
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
