<?php

namespace App\Services\Channels;

use App\Models\Conversation;
use RuntimeException;

/**
 * Quién sabe hablar cada canal.
 *
 * Los puntos de salida —la IA, las automatizaciones, los flows, el composer del
 * Inbox— piden `forConversation($c)` y reciben con qué enviar. Ninguno vuelve a
 * nombrar a WhatsApp.
 *
 * Se registra como singleton en `AppServiceProvider`: el registro de adapters
 * es configuración de arranque, no algo que se rearme en cada envío.
 */
class ChannelRouter
{
    /** @var array<string, ChannelAdapter> */
    private array $adapters = [];

    public function register(ChannelAdapter $adapter): self
    {
        $this->adapters[$adapter->channel()] = $adapter;

        return $this;
    }

    public function supports(string $channel): bool
    {
        return isset($this->adapters[$channel]);
    }

    /**
     * **Lanza en vez de caer a WhatsApp.** Un canal sin adapter es un error de
     * configuración, y hacerlo silencioso significaría mandarle el mensaje por
     * WhatsApp a alguien que escribió por Telegram — a un teléfono que puede no
     * existir, o peor, al de otra persona.
     */
    public function adapter(string $channel): ChannelAdapter
    {
        return $this->adapters[$channel]
            ?? throw new RuntimeException("No hay forma de enviar por el canal «{$channel}».");
    }

    public function forConversation(Conversation $conversation): ChannelAdapter
    {
        return $this->adapter($conversation->channel ?? ChannelRules::DEFAULT);
    }

    /** @return array<int, string> */
    public function registered(): array
    {
        return array_keys($this->adapters);
    }
}
