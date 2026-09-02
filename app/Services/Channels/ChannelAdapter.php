<?php

namespace App\Services\Channels;

use App\Models\Conversation;
use App\Models\Message;

/**
 * Cómo se le habla a un canal.
 *
 * Es el espejo de salida del `Ingestor`: allá el motor deja de saber por dónde
 * ENTRÓ un mensaje, acá deja de saber por dónde SALE. En el medio —flows,
 * automatizaciones, IA, broadcasts— nadie menciona WhatsApp.
 *
 * **Deliberadamente corto.** El plan lista además `sendInteractive` y
 * `sendTypingIndicator`; no están todavía porque hoy hay **una sola
 * implementación**, y una interfaz diseñada contra un único caso se equivoca de
 * forma en los métodos que nadie ejerce. Entran cuando el adapter de Telegram
 * los pida, que es cuando se sabrá qué forma tienen que tener de verdad.
 */
interface ChannelAdapter
{
    public function channel(): string;

    /**
     * Envía texto. Guarda la fila del mensaje y devuelve el resultado.
     *
     * @throws \RuntimeException si el proveedor rechaza el envío
     */
    public function sendText(Conversation $conversation, string $text, string $senderType = Message::SENDER_BOT, ?string $senderId = null): Message;

    /** Envía un archivo con pie de foto opcional. */
    public function sendMedia(Conversation $conversation, string $contents, string $mimeType, string $filename, ?string $caption = null, string $senderType = Message::SENDER_BOT, ?string $senderId = null): Message;
}
