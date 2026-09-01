<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'external_id', 'field_name', 'field_type', 'field_options'])]
class CustomField extends Model
{
    use BelongsToAccount, HasUuids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['field_options' => 'array'];
    }

    public function values(): HasMany
    {
        return $this->hasMany(ContactCustomValue::class);
    }
}
