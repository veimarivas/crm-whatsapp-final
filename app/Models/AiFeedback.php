<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'account_id', 'conversation_id', 'external_ref', 'source', 'reporter',
    'rating', 'ai_text', 'question', 'correction',
    'status', 'reviewed_by', 'reviewed_at', 'document_id',
])]
class AiFeedback extends Model
{
    use BelongsToAccount, HasUuids;

    protected $table = 'ai_feedback';

    public const UP = 'up';

    public const DOWN = 'down';

    public const PENDING = 'pending';

    public const APPLIED = 'applied';

    public const DISMISSED = 'dismissed';

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    /**
     * Lo que hay que revisar: pulgar abajo todavía sin resolver.
     *
     * Un pulgar arriba no entra en la cola — es señal para la métrica, no
     * trabajo pendiente. Mezclarlos convertiría la cola en una bandeja que
     * nadie vacía.
     */
    public function scopePendingReview(Builder $query): Builder
    {
        return $query->where('rating', self::DOWN)->where('status', self::PENDING);
    }
}
