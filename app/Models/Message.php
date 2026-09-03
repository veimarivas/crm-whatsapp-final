<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'conversation_id', 'channel', 'external_message_id',
    'sender_type', 'sender_id', 'content_type', 'transcript',
    'content_text', 'media_url', 'media_path', 'media_mime', 'referral', 'template_name', 'message_id',
    'interactive_reply_id', 'reply_to_message_id', 'status',
])]
class Message extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'referral' => 'array',
        ];
    }

    public const SENDER_CUSTOMER = 'customer';

    public const SENDER_AGENT = 'agent';

    public const SENDER_BOT = 'bot';

    protected static function booted(): void
    {
        // Si contesta un humano, la IA se calla en esa conversación.
        //
        // Es la regla que promete la UI de Ajustes ("Si un agente responde, el
        // bot se apaga en esa conversación") y que estaba sin implementar: el
        // test que la cubre venía fallando. Sin esto el bot seguía respondiendo
        // por encima del agente que ya había tomado el chat.
        //
        // Va en el modelo y no en Messenger porque hay tres caminos de envío
        // (el Inbox arma el Message a mano, la API pública y Messenger) y solo
        // uno pasaba por el servicio.
        static::created(function (Message $message) {
            if ($message->sender_type !== self::SENDER_AGENT) {
                return;
            }

            $conversation = Conversation::whereKey($message->conversation_id)
                ->where('ai_autoreply_disabled', false)
                ->first();

            if (! $conversation) {
                return;
            }

            // Con el motivo escrito: «la IA no responde» y «la apagaste vos al
            // contestar» se ven idénticos desde afuera, y esa ambigüedad es la
            // que hace perder una tarde buscando un fallo que no existe.
            $agente = $message->sender_id
                ? User::whereKey($message->sender_id)->value('name')
                : null;

            $conversation->setAiEnabled(false, $agente
                ? "Se apagó sola cuando {$agente} respondió manualmente"
                : 'Se apagó sola cuando un agente respondió manualmente');
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_message_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    /** Autor del mensaje cuando sender_type=agent (bot=IA no tiene user). */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
