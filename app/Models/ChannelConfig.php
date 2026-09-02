<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Credenciales y ajustes de un canal, **por cuenta**.
 *
 * Es multi-tenant a propósito y no un `.env`: un `TELEGRAM_BOT_TOKEN` en el
 * entorno sería un solo bot para todas las cuentas de la instalación. Cada
 * cuenta conecta el suyo.
 *
 * También es el reemplazo de `WhatsappConfig` como **resolvedor de cuenta en la
 * entrada**: hoy la cuenta se deduce del `phone_number_id` que manda Meta, y
 * eso no existe en ningún otro canal.
 *
 * Las credenciales van cifradas en reposo (`encrypted`) y ocultas al
 * serializar: un bot token da control total sobre las conversaciones de la
 * institución, y basta con que una vez viaje a una vista para que quede en el
 * historial del navegador.
 */
#[Fillable(['account_id', 'channel', 'is_enabled', 'credentials', 'settings', 'connected_at'])]
#[Hidden(['credentials'])]
class ChannelConfig extends Model
{
    use BelongsToAccount, HasUuids;

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'is_enabled' => 'boolean',
            'connected_at' => 'datetime',
        ];
    }

    /** Configuración activa de un canal para la cuenta, o null. */
    public static function activa(string $accountId, string $channel): ?self
    {
        return static::forAccount($accountId)
            ->where('channel', $channel)
            ->where('is_enabled', true)
            ->first();
    }

    /**
     * Una credencial suelta, sin exponer el resto.
     *
     * Los llamadores piden `credential('bot_token')` en vez de leer el array
     * completo: así un `dd()` o un log accidental no arrastra todas las claves
     * del canal.
     */
    public function credential(string $key): ?string
    {
        return ($this->credentials ?? [])[$key] ?? null;
    }
}
