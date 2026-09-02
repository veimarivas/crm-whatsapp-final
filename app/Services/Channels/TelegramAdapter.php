<?php

namespace App\Services\Channels;

use App\Models\ChannelConfig;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Telegram\TelegramApi;
use RuntimeException;

/**
 * Telegram como canal de salida.
 *
 * A diferencia de WhatsApp no hay ventana que vencer ni plantillas que aprobar
 * (`ChannelRules` lo declara), pero **solo se le puede escribir a quien inició
 * la conversación**: un bot no alcanza a nadie que no le haya hablado primero.
 * Eso ya está en `ChannelRules::supportsOutboundFirst()` y es lo que va a
 * limitar la audiencia de un envío masivo por este canal.
 *
 * El adapter guarda la fila del mensaje **igual que `Messenger`**: el motor
 * espera que enviar deje rastro en `messages` y actualice la conversación, y
 * quien lea el historial no tiene por qué saber por dónde salió.
 */
final class TelegramAdapter implements ChannelAdapter
{
    public function channel(): string
    {
        return ChannelRules::TELEGRAM;
    }

    public function sendText(Conversation $conversation, string $text, string $senderType = Message::SENDER_BOT, ?string $senderId = null): Message
    {
        $api = TelegramApi::for($this->config($conversation));

        // El id del chat en Telegram. Es lo que `channel_conversation_id`
        // guarda desde F0 — el identificador del hilo EN EL SISTEMA DEL CANAL.
        $chatId = $conversation->channel_conversation_id
            ?? throw new RuntimeException('La conversación de Telegram no tiene chat_id.');

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'channel' => ChannelRules::TELEGRAM,
            'sender_type' => $senderType,
            'sender_id' => $senderId,
            'content_type' => 'text',
            'content_text' => $text,
            'status' => 'sending',
        ]);

        try {
            $result = $api->sendMessage($chatId, $text);
        } catch (\Throwable $e) {
            // La fila queda como `failed` y no se borra: que se haya intentado
            // es parte del historial, y sin ella «no le llegó» y «no se mandó»
            // se ven igual.
            $message->update(['status' => 'failed']);

            throw new RuntimeException($e->getMessage(), previous: $e);
        }

        $message->update([
            'external_message_id' => isset($result['message_id']) ? (string) $result['message_id'] : null,
            'status' => 'sent',
        ]);

        $conversation->update([
            'last_message_text' => $text,
            'last_message_at' => now(),
        ]);

        return $message->fresh();
    }

    /**
     * Todavía no.
     *
     * Mandar un archivo por Telegram es fácil; **recibirlo** no, y las dos
     * mitades tienen que diseñarse juntas: Telegram entrega un `file_id` cuyo
     * link caduca y **lleva el bot token adentro**, así que no se puede
     * guardar ni exponer como se hace con Meta —que se resuelve por proxy en
     * vivo— sino que hay que descargarlo a almacenamiento propio. Media una
     * decisión de almacenamiento que no corresponde improvisar acá.
     */
    public function sendMedia(Conversation $conversation, string $contents, string $mimeType, string $filename, ?string $caption = null, string $senderType = Message::SENDER_BOT, ?string $senderId = null): Message
    {
        throw new RuntimeException('Todavía no se pueden enviar archivos por Telegram: por ahora, solo texto.');
    }

    private function config(Conversation $conversation): ChannelConfig
    {
        return ChannelConfig::activa($conversation->account_id, ChannelRules::TELEGRAM)
            ?? throw new RuntimeException('Telegram no está conectado en esta cuenta.');
    }
}
