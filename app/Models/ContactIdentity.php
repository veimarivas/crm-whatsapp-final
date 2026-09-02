<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use App\Services\Channels\ChannelRules;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cómo se llama una persona en cada canal.
 *
 * **Es lo que permite que un contacto deje de ser un teléfono.** Hasta F0 el
 * identificador de un contacto ERA su `phone_normalized`, así que un mensaje
 * de Telegram —que no trae teléfono— no tenía forma de existir. Con
 * identidades, el mismo humano puede escribir por WhatsApp y por Telegram y
 * tener un solo historial.
 *
 * `external_id` es el identificador **en el sistema del canal**: el teléfono
 * normalizado en WhatsApp, el `from.id` en Telegram, el PSID en Messenger. El
 * unique `(cuenta, canal, external_id)` es lo que hace idempotente la entrada.
 */
#[Fillable([
    'account_id', 'contact_id', 'channel', 'external_id',
    'display_name', 'profile_data', 'is_primary',
])]
class ContactIdentity extends Model
{
    use BelongsToAccount, HasUuids;

    protected function casts(): array
    {
        return [
            'profile_data' => 'array',
            'is_primary' => 'boolean',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Alta idempotente de la identidad de un contacto en un canal.
     *
     * Se usa `firstOrCreate` sobre la misma tripleta del índice único, así que
     * dos webhooks simultáneos del mismo remitente no crean dos filas — que es
     * exactamente el caso que se da cuando alguien manda tres mensajes
     * seguidos.
     */
    public static function registrar(
        Contact $contact,
        string $channel,
        string $externalId,
        ?string $displayName = null,
    ): self {
        $identity = static::firstOrCreate(
            [
                'account_id' => $contact->account_id,
                'channel' => $channel,
                'external_id' => $externalId,
            ],
            [
                'contact_id' => $contact->id,
                'display_name' => $displayName,
                // La primera identidad de un contacto es la principal: es de
                // donde sale su nombre cuando no hay otra cosa.
                'is_primary' => ! static::where('contact_id', $contact->id)->exists(),
            ],
        );

        // El nombre del perfil puede llegar después del primer mensaje (Meta no
        // siempre lo manda). Se completa, pero no se pisa uno que ya había:
        // alguien pudo corregirlo a mano.
        if ($displayName && ! $identity->display_name) {
            $identity->update(['display_name' => $displayName]);
        }

        return $identity;
    }

    /** El contacto detrás de un identificador de canal, o null si es nuevo. */
    public static function resolverContacto(string $accountId, string $channel, string $externalId): ?Contact
    {
        return static::where('account_id', $accountId)
            ->where('channel', $channel)
            ->where('external_id', $externalId)
            ->first()?->contact;
    }

    public function esDeWhatsapp(): bool
    {
        return $this->channel === ChannelRules::WHATSAPP;
    }
}
