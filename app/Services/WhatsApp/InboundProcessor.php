<?php

namespace App\Services\WhatsApp;

use App\Jobs\AiAutoReplyJob;
use App\Jobs\ProcessAutomationEventJob;
use App\Jobs\ProcessFlowMessageJob;
use App\Jobs\TranscribeAudioJob;
use App\Models\AutoTagRule;
use App\Models\BroadcastRecipient;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Deal;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\Pipeline;
use App\Models\WhatsappConfig;
use App\Services\Webhooks\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Procesa el payload del webhook de Meta: mensajes entrantes y
 * actualizaciones de estado. Equivalente al POST de
 * src/app/api/whatsapp/webhook/route.ts del original.
 */
class InboundProcessor
{
    public function process(array $payload): void
    {
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? '') !== 'messages') {
                    continue;
                }

                $value = $change['value'] ?? [];
                $config = WhatsappConfig::where('phone_number_id', $value['metadata']['phone_number_id'] ?? '')->first();

                if (! $config) {
                    Log::warning('Webhook WhatsApp: phone_number_id desconocido', [
                        'phone_number_id' => $value['metadata']['phone_number_id'] ?? null,
                    ]);

                    continue;
                }

                foreach ($value['messages'] ?? [] as $message) {
                    $this->handleInboundMessage($config, $message, $value['contacts'] ?? []);
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    $this->handleStatusUpdate($status);
                }
            }
        }
    }

    private function handleInboundMessage(WhatsappConfig $config, array $message, array $waContacts): void
    {
        $wamid = $message['id'] ?? null;

        // Idempotencia: Meta reintenta entregas — un wamid ya guardado se ignora.
        if ($wamid && Message::where('message_id', $wamid)->exists()) {
            return;
        }

        // Las reacciones no crean fila de mensaje: actualizan message_reactions.
        if (($message['type'] ?? '') === 'reaction') {
            $this->handleReaction($message);

            return;
        }

        $from = $message['from'];
        $profileName = collect($waContacts)->firstWhere('wa_id', $from)['profile']['name'] ?? null;

        [$contact, $conversation, $storedMessage, $isNewContact] = DB::transaction(function () use ($config, $message, $from, $profileName, $wamid) {
            $contact = Contact::firstOrCreate(
                [
                    'account_id' => $config->account_id,
                    'phone_normalized' => Contact::normalizePhone($from),
                ],
                ['phone' => $from, 'name' => $profileName],
            );
            $isNewContact = $contact->wasRecentlyCreated;

            if ($profileName && ! $contact->name) {
                $contact->update(['name' => $profileName]);
            }

            $conversation = Conversation::firstOrCreate(
                [
                    'account_id' => $config->account_id,
                    'contact_id' => $contact->id,
                ],
                ['status' => Conversation::STATUS_OPEN],
            );

            [$contentType, $contentText, $mediaId, $interactiveReplyId] = $this->parseContent($message);

            $replyTo = null;
            if ($contextId = $message['context']['id'] ?? null) {
                $replyTo = Message::where('message_id', $contextId)->value('id');
            }

            // Atribución Click-to-WhatsApp: Meta adjunta `referral` cuando el
            // usuario llegó tocando un anuncio ({source_id: AD_ID, headline, source_url…}).
            $referral = $message['referral'] ?? null;

            $storedMessage = Message::create([
                'conversation_id' => $conversation->id,
                'sender_type' => Message::SENDER_CUSTOMER,
                'content_type' => $contentType,
                'content_text' => $contentText,
                'media_url' => $mediaId, // media id de Meta; se resuelve vía el proxy /whatsapp/media/{id}
                'referral' => $referral,
                'message_id' => $wamid,
                'interactive_reply_id' => $interactiveReplyId,
                'reply_to_message_id' => $replyTo,
                'status' => 'delivered',
            ]);

            // El ad de ENTRADA se conserva: solo se escribe si la conversación
            // aún no tiene uno (preserva la atribución original).
            if (($referral['source_id'] ?? null) && ! $conversation->entry_ad_id) {
                $conversation->entry_ad_id = $referral['source_id'];
            }

            $conversation->update([
                'last_message_text' => $contentText ?? "[{$contentType}]",
                'last_message_at' => now(),
                'unread_count' => $conversation->unread_count + 1,
                'status' => Conversation::STATUS_OPEN,
                // OJO: acá NO se reinicia `ai_reply_count`. Antes se ponía a 0
                // con cada mensaje entrante, lo que volvía inalcanzable el
                // "máximo N respuestas por conversación" de Ajustes — el tope
                // no limitaba nada. Ahora el contador se acumula y lo reinicia
                // `AiAutoReplyJob` cuando vence la pausa (`ai_paused_until`).
            ]);

            // Correlaciona respuestas con broadcasts (replied tracking).
            BroadcastRecipient::where('contact_id', $contact->id)
                ->whereNotNull('sent_at')
                ->whereNull('replied_at')
                ->get()
                ->each(function (BroadcastRecipient $recipient) {
                    $recipient->update(['status' => 'replied', 'replied_at' => now()]);
                    $recipient->broadcast?->increment('replied_count');
                });

            return [$contact, $conversation, $storedMessage, $isNewContact];
        });

        $text = $storedMessage->content_text;

        // Auto-tagging: aplica reglas de keywords al contacto según el contenido.
        // Se hace ANTES del webhook para que Komo reciba los tags actualizados.
        if ($text) {
            $this->applyAutoTags($contact, $text, $isNewContact);
        }

        $dispatcher = app(Dispatcher::class);
        $contactData = $contact->only(['id', 'phone', 'name', 'email', 'company']);

        // ORDEN IMPORTANTE — la cola es FIFO. Priorizamos:
        //   1. Webhooks a integraciones (Komo, etc.): ligeros, deben salir YA
        //      para que el CRM externo vea el mensaje casi en tiempo real.
        //   2. Flows y automatizaciones: rápidos, procesan reglas locales.
        //   3. AiAutoReplyJob al final: puede tardar 30-60s con Qwen y no debe
        //      bloquear los webhooks. Si se pusiera antes, Komo esperaría a
        //      que Ollama termine para recibir el mensaje del cliente.

        if ($isNewContact) {
            $dispatcher->dispatch($config->account_id, 'contact.created', ['contact' => $contactData]);
        }

        $dispatcher->dispatch($config->account_id, 'message.received', [
            'conversation_id' => $conversation->id,
            'contact' => $contactData,
            'message' => [
                'id' => $storedMessage->id,
                'type' => $storedMessage->content_type,
                'text' => $storedMessage->content_text,
                'wamid' => $storedMessage->message_id,
                'media_id' => $storedMessage->media_url, // Meta media_id — Komo lo usa para el proxy /leads/media/{id}
                'referral' => $storedMessage->referral, // atribución de anuncio (komo la guarda como source_ref)
            ],
        ]);

        // Si es un audio, encolamos la transcripción (asincrónico, no bloquea nada).
        if ($storedMessage->content_type === 'audio' && $storedMessage->media_url) {
            TranscribeAudioJob::dispatch($storedMessage->id);
        }

        // Ahora sí, dispatch de flow + automations + AI (en ese orden).
        // Todo esto pasa DESPUÉS que el webhook a Komo ya está encolado,
        // así el mensaje aparece en Komo en 1-2s aunque Ollama tarde 60s.
        ProcessFlowMessageJob::dispatch($contact->id, $conversation->id, $storedMessage->id);

        if ($isNewContact) {
            $this->createLeadDeal($contact, $conversation);
            ProcessAutomationEventJob::dispatch('new_contact', $contact->id, $conversation->id, $text);
        }

        ProcessAutomationEventJob::dispatch('inbound_message', $contact->id, $conversation->id, $text);

        if ($text) {
            ProcessAutomationEventJob::dispatch('keyword', $contact->id, $conversation->id, $text);
        }

        // Bot IA al final: es el job más lento (30-60s con Qwen). Se abstiene
        // si hay un flow activo (el chatbot estructurado tiene prioridad).
        // Para audios NO se encola acá: la respuesta se difiere hasta que la
        // transcripción esté lista. La encola TranscribeAudioJob al guardar el
        // transcript, así la IA nunca contesta a un audio que no escuchó.
        if ($storedMessage->content_type !== 'audio') {
            AiAutoReplyJob::dispatch($conversation->id);
        }
    }

    /** @return array{0:string,1:?string,2:?string,3:?string} [content_type, content_text, media_id, interactive_reply_id] */
    private function parseContent(array $message): array
    {
        $type = $message['type'] ?? 'unknown';

        return match ($type) {
            'text' => ['text', $message['text']['body'] ?? '', null, null],
            'image' => ['image', $message['image']['caption'] ?? null, $message['image']['id'] ?? null, null],
            'video' => ['video', $message['video']['caption'] ?? null, $message['video']['id'] ?? null, null],
            'audio' => ['audio', null, $message['audio']['id'] ?? null, null],
            'sticker' => ['sticker', null, $message['sticker']['id'] ?? null, null],
            'document' => ['document', $message['document']['filename'] ?? null, $message['document']['id'] ?? null, null],
            'location' => [
                'location',
                trim(($message['location']['name'] ?? '').' '.($message['location']['latitude'] ?? '').','.($message['location']['longitude'] ?? '')),
                null,
                null,
            ],
            'interactive' => $this->parseInteractive($message['interactive'] ?? []),
            'button' => ['interactive', $message['button']['text'] ?? '', null, $message['button']['payload'] ?? null],
            default => ['text', '[Tipo de mensaje no soportado]', null, null],
        };
    }

    private function parseInteractive(array $interactive): array
    {
        $reply = $interactive['button_reply'] ?? $interactive['list_reply'] ?? [];

        return ['interactive', $reply['title'] ?? '', null, $reply['id'] ?? null];
    }

    private function handleReaction(array $message): void
    {
        $targetWamid = $message['reaction']['message_id'] ?? null;
        $emoji = $message['reaction']['emoji'] ?? null;

        $target = $targetWamid ? Message::where('message_id', $targetWamid)->first() : null;

        if (! $target) {
            return;
        }

        if ($emoji === null || $emoji === '') {
            // Reacción retirada.
            MessageReaction::where('message_id', $target->id)
                ->where('actor_type', 'customer')
                ->delete();

            return;
        }

        MessageReaction::updateOrCreate(
            ['message_id' => $target->id, 'actor_type' => 'customer', 'actor_id' => null],
            ['conversation_id' => $target->conversation_id, 'emoji' => $emoji],
        );
    }

    private function handleStatusUpdate(array $status): void
    {
        $wamid = $status['id'] ?? null;
        $newStatus = $status['status'] ?? null; // sent | delivered | read | failed

        if (! $wamid || ! in_array($newStatus, ['sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }

        // Los estados solo avanzan (sent → delivered → read), nunca retroceden.
        $rank = ['sending' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];

        $message = Message::where('message_id', $wamid)->first();
        if ($message && ($rank[$newStatus] ?? 0) > ($rank[$message->status] ?? 0)) {
            $message->update(['status' => $newStatus]);
        }

        $recipient = BroadcastRecipient::where('whatsapp_message_id', $wamid)->first();
        if ($recipient) {
            $this->advanceBroadcastRecipient($recipient, $newStatus, $status);
        }
    }

    private function advanceBroadcastRecipient(BroadcastRecipient $recipient, string $newStatus, array $status): void
    {
        $rank = ['pending' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'replied' => 4, 'failed' => 5];

        if (($rank[$newStatus] ?? 0) <= ($rank[$recipient->status] ?? 0)) {
            return;
        }

        $updates = ['status' => $newStatus];
        $counter = null;

        switch ($newStatus) {
            case 'delivered':
                $updates['delivered_at'] = now();
                $counter = 'delivered_count';
                break;
            case 'read':
                $updates['read_at'] = now();
                $counter = 'read_count';
                break;
            case 'failed':
                $updates['error_message'] = $status['errors'][0]['message'] ?? 'Delivery failed';
                $counter = 'failed_count';
                break;
        }

        $recipient->update($updates);

        if ($counter) {
            $recipient->broadcast?->increment($counter);
        }
    }

    /**
     * Aplica reglas de auto-tagging al contacto según keywords en el mensaje.
     * Cada regla que matchea agrega su tag (silencioso si ya lo tenía).
     * Si la regla tiene first_message_only=true, solo aplica cuando el contacto
     * es nuevo (evita spam de tags en cada mensaje subsecuente).
     */
    private function applyAutoTags(Contact $contact, string $text, bool $isNewContact): void
    {
        $textLower = mb_strtolower($text);

        $rules = AutoTagRule::forAccount($contact->account_id)
            ->where('is_active', true)
            ->when(! $isNewContact, fn ($q) => $q->where('first_message_only', false))
            ->get();

        $tagIds = [];
        foreach ($rules as $rule) {
            if (str_contains($textLower, mb_strtolower($rule->keyword))) {
                $tagIds[] = $rule->tag_id;
            }
        }

        if (! empty($tagIds)) {
            // syncWithoutDetaching: solo agrega los tags nuevos, no toca los existentes.
            $contact->tags()->syncWithoutDetaching(array_unique($tagIds));
        }
    }

    /**
     * Crea un deal en la primera etapa del pipeline por defecto de la cuenta
     * al recibir un lead nuevo desde WhatsApp. Silencioso si la cuenta no
     * tiene pipelines configurados o si el contacto ya tiene un deal abierto.
     */
    private function createLeadDeal(Contact $contact, Conversation $conversation): void
    {
        // Evita duplicados: si el contacto ya tiene un deal abierto, no crea otro.
        if (Deal::where('contact_id', $contact->id)->where('status', 'open')->exists()) {
            return;
        }

        // El pipeline por defecto (espejo del default del Komo) o el primero.
        $pipeline = Pipeline::forAccount($contact->account_id)
            ->with(['stages' => fn ($q) => $q->orderBy('position')])
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->first();

        // Primera etapa abierta (nunca una terminal como Ganado/Perdido).
        $firstStage = $pipeline?->stages->where('stage_type', 'open')->first() ?? $pipeline?->stages->first();

        if (! $pipeline || ! $firstStage) {
            return;
        }

        Deal::create([
            'account_id' => $contact->account_id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $firstStage->id,
            'contact_id' => $contact->id,
            'conversation_id' => $conversation->id,
            'title' => $contact->name ?: $contact->phone,
            'status' => 'open',
        ]);
    }
}
