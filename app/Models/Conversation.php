<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'account_id', 'contact_id', 'channel', 'channel_conversation_id',
    'status', 'assigned_agent_id', 'entry_ad_id',
    'last_message_text', 'last_message_at', 'unread_count',
    'ai_autoreply_disabled', 'ai_reply_count', 'ai_pending', 'ai_limit_notified_at', 'ai_paused_until',
    'ai_failure_count', 'ai_disabled_reason', 'ai_disabled_at', 'ai_pending_at',
])]
class Conversation extends Model
{
    use BelongsToAccount, HasUuids;

    public const STATUS_OPEN = 'open';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CLOSED = 'closed';

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'ai_autoreply_disabled' => 'boolean',
            'ai_pending' => 'boolean',
            'ai_limit_notified_at' => 'datetime',
            'ai_paused_until' => 'datetime',
            'ai_disabled_at' => 'datetime',
            'ai_pending_at' => 'datetime',
        ];
    }

    /**
     * Enciende o apaga la IA en esta conversación, dejando dicho por qué y
     * avisando al CRM externo.
     *
     * Todos los caminos que tocan `ai_autoreply_disabled` pasan por acá — son
     * cuatro (agente que responde, toggle del Inbox, API de Komo, fallo del
     * bot) y antes cada uno escribía la bandera por su cuenta. Eso dejaba dos
     * agujeros que costaron una tarde de diagnóstico:
     *
     *  - No quedaba registro de QUIÉN la apagó, así que una IA muda podía ser
     *    un fallo del modelo o un agente que contestó a mano, y no había forma
     *    de distinguirlo.
     *  - Komo no se enteraba: su lead seguía mostrando «✨ IA activa» mientras
     *    acá estaba apagada. El usuario ve el toggle encendido, escribe, y no
     *    contesta nadie.
     */
    public function setAiEnabled(bool $enabled, ?string $reason = null): void
    {
        $antes = (bool) $this->ai_autoreply_disabled;

        $this->update([
            'ai_autoreply_disabled' => ! $enabled,
            'ai_disabled_reason' => $enabled ? null : $reason,
            'ai_disabled_at' => $enabled ? null : now(),
            // Reactivar limpia el historial: si el agente la vuelve a
            // encender, es para que lo intente de nuevo desde cero.
            'ai_failure_count' => $enabled ? 0 : $this->ai_failure_count,
            'ai_paused_until' => $enabled ? null : $this->ai_paused_until,
            'ai_reply_count' => $enabled ? 0 : $this->ai_reply_count,
        ]);

        if ($antes === ! $enabled) {
            return; // no cambió nada: no hay que avisar
        }

        // Best-effort: que el webhook falle no puede impedir el cambio local.
        rescue(fn () => app(\App\Services\Webhooks\Dispatcher::class)->dispatch(
            $this->account_id,
            'ai.mode_changed',
            [
                'conversation_id' => $this->id,
                'ai_enabled' => $enabled,
                'reason' => $enabled ? null : $reason,
            ],
        ), report: false);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }
}
