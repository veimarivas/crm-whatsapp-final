<?php

namespace App\Services\Channels;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\WhatsApp\Messenger;

/**
 * WhatsApp, envuelto.
 *
 * **`Messenger` no se reescribe ni se toca: se delega.** Tiene el manejo de
 * errores de Meta, el guardado del mensaje, la actualización de la
 * conversación y el disparo de los webhooks de salida, todo ya probado. Y
 * además se usa directo desde el composer del Inbox y desde la API pública de
 * media: reemplazarlo habría sido reescribir código que funciona para no ganar
 * nada.
 *
 * Lo que aporta el adapter es que **el motor deje de nombrarlo**. Cuando la IA
 * o una automatización responden, hoy llaman a `Messenger` —o sea, a WhatsApp—
 * y para agregar Telegram habría que meter un `if` en cada punto de salida.
 */
final class WhatsAppAdapter implements ChannelAdapter
{
    public function __construct(private readonly Messenger $messenger) {}

    public function channel(): string
    {
        return ChannelRules::WHATSAPP;
    }

    public function sendText(Conversation $conversation, string $text, string $senderType = Message::SENDER_BOT, ?string $senderId = null): Message
    {
        return $this->messenger->sendText($conversation, $text, $senderType, $senderId);
    }

    public function sendMedia(Conversation $conversation, string $contents, string $mimeType, string $filename, ?string $caption = null, string $senderType = Message::SENDER_BOT, ?string $senderId = null): Message
    {
        return $this->messenger->sendMedia($conversation, $contents, $mimeType, $filename, $caption, $senderType, $senderId);
    }
}
