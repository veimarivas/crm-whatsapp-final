<?php

namespace App\Services\Channels;

/**
 * Un mensaje entrante, ya despegado del formato de su canal.
 *
 * Es la frontera de F0: de acá para afuera cada canal habla su idioma (el
 * sobre `entry/changes/value` de Meta, el `update` de Telegram, un webhook de
 * Twilio); de acá para adentro **el motor no sabe de canales**.
 *
 * Los nombres son deliberadamente neutros: `senderExternalId` y no `phone`,
 * `externalMessageId` y no `wamid`. Un nombre que arrastra el canal es un
 * nombre que después obliga a leer código para saber si el valor sirve para
 * otro — que es exactamente el problema que tenía este proyecto.
 */
final readonly class InboundMessage
{
    /**
     * @param  string  $accountId  la cuenta la resuelve el borde, no el ingestor
     * @param  string  $senderExternalId  teléfono normalizado | chat_id | PSID
     * @param  string|null  $threadExternalId  id del hilo EN EL SISTEMA DEL CANAL
     * @param  array<string, mixed>|null  $referral  atribución publicitaria, si el canal la trae
     */
    public function __construct(
        public string $accountId,
        public string $channel,
        public string $senderExternalId,
        public ?string $senderName = null,
        public ?string $threadExternalId = null,
        public string $contentType = 'text',
        public ?string $contentText = null,
        public ?string $mediaRef = null,
        public ?string $externalMessageId = null,
        public ?array $referral = null,
        public ?string $replyToExternalId = null,
        public ?string $interactiveReplyId = null,
        /**
         * Teléfono en crudo, SOLO si el canal lo trae.
         *
         * No es lo mismo que `senderExternalId`: en WhatsApp coinciden, pero en
         * Telegram el remitente tiene un `chat_id` y no tiene teléfono. Va
         * aparte para que el ingestor no tenga que adivinar si el identificador
         * del canal sirve como número.
         */
        public ?string $phone = null,
    ) {}

    /** El nombre con el que registrar al contacto si todavía no existe. */
    public function displayName(): ?string
    {
        return $this->senderName;
    }

    public function isAudio(): bool
    {
        return $this->contentType === 'audio';
    }
}
