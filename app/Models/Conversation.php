<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'account_id', 'contact_id', 'status', 'assigned_agent_id', 'entry_ad_id',
    'last_message_text', 'last_message_at', 'unread_count',
    'ai_autoreply_disabled', 'ai_reply_count', 'ai_pending', 'ai_limit_notified_at', 'ai_paused_until',
    'ai_failure_count', 'ai_disabled_reason', 'ai_disabled_at',
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
        ];
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
