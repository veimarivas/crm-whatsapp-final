<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'account_id', 'name', 'body_type', 'body_text', 'body_media_path',
    'template_name', 'template_language', 'template_variables',
    'header_media_url', 'audience_filter', 'scheduled_at', 'status', 'total_recipients',
    'sent_count', 'delivered_count', 'read_count', 'replied_count', 'failed_count',
])]
class Broadcast extends Model
{
    use BelongsToAccount, HasUuids;

    /** Plantilla aprobada de Meta: sirve fuera de la ventana y se factura. */
    public const BODY_TEMPLATE = 'template';

    /** Mensaje de sesión: texto libre gratis, solo dentro de la ventana. */
    public const BODY_TEXT = 'text';

    public function isText(): bool
    {
        return $this->body_type === self::BODY_TEXT;
    }

    protected function casts(): array
    {
        return [
            'template_variables' => 'array',
            'audience_filter' => 'array',
            'scheduled_at' => 'datetime',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class);
    }
}
