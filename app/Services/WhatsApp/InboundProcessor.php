<?php

namespace App\Services\WhatsApp;

use App\Models\BroadcastRecipient;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\WhatsappConfig;
use App\Services\Channels\ChannelRules;
use App\Services\Channels\InboundMessage;
use App\Services\Channels\Ingestor;
use Illuminate\Support\Facades\Log;

/**
 * Procesa el payload del webhook de Meta: mensajes entrantes y
 * actualizaciones de estado. Equivalente al POST de
 * src/app/api/whatsapp/webhook/route.ts del original.
 */
class InboundProcessor
{
    public function __construct(private readonly Ingestor $ingestor) {}

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

    /**
     * Traduce el mensaje del sobre de Meta a un `InboundMessage` y se lo pasa
     * al ingestor. **Todo lo que hacía de `DB::transaction` para abajo vive
     * ahora en `Services\Channels\Ingestor`** (F0/T0.2b): era igual para
     * cualquier canal, y dejarlo acá obligaba a cada canal nuevo a copiarlo.
     *
     * Lo que queda es lo único genuinamente específico de WhatsApp: los nombres
     * de los campos de Meta, el `profile.name` que viaja aparte del mensaje, y
     * las reacciones, que no crean fila de mensaje.
     */
    private function handleInboundMessage(WhatsappConfig $config, array $message, array $waContacts): void
    {
        // Las reacciones no crean fila de mensaje: actualizan message_reactions.
        // Es un concepto de Meta y se queda de este lado.
        if (($message['type'] ?? '') === 'reaction') {
            $this->handleReaction($message);

            return;
        }

        $from = $message['from'];
        [$contentType, $contentText, $mediaId, $interactiveReplyId] = $this->parseContent($message);

        $this->ingestor->handle(new InboundMessage(
            accountId: $config->account_id,
            channel: ChannelRules::WHATSAPP,
            // En WhatsApp el identificador del remitente ES su teléfono
            // normalizado. En otros canales no lo es, y por eso el teléfono
            // viaja aparte.
            senderExternalId: Contact::normalizePhone($from),
            senderName: collect($waContacts)->firstWhere('wa_id', $from)['profile']['name'] ?? null,
            threadExternalId: Contact::normalizePhone($from),
            contentType: $contentType,
            contentText: $contentText,
            // media id de Meta; se resuelve vía el proxy /whatsapp/media/{id}
            mediaRef: $mediaId,
            externalMessageId: $message['id'] ?? null,
            // Atribución Click-to-WhatsApp: Meta adjunta `referral` cuando el
            // usuario llegó tocando un anuncio.
            referral: $message['referral'] ?? null,
            replyToExternalId: $message['context']['id'] ?? null,
            interactiveReplyId: $interactiveReplyId,
            phone: $from,
        ));
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
            'interactive' => $this->parseInteractive($message['interactive'] ?? []),
            'button' => ['interactive', $message['button']['text'] ?? '', null, $message['button']['payload'] ?? null],
            'contacts' => ['contacts', collect($message['contacts'] ?? [])->pluck('name')->filter()->unique()->implode(', ') ?: null, null, null],
            'unsupported' => ['text', null, null, null],
            // Cualquier otro tipo llega aquí. Guardamos el nombre del tipo real
            // (no un placeholder genérico) para poder identificarlo y añadir su
            // soporte sin perder el dato.
            default => ['text', "[Tipo de mensaje no soportado: {$type}]", null, null],
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

}
