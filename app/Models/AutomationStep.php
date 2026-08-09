<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'automation_id', 'parent_step_id', 'branch', 'step_type', 'step_config',
    // `position` = orden de ejecución en la rama. `position_x/y` = dónde quedó
    // la tarjeta en el lienzo. Son cosas distintas a propósito.
    'position', 'position_x', 'position_y',
])]
class AutomationStep extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['step_config' => 'array'];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(AutomationStep::class, 'parent_step_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(AutomationStep::class, 'parent_step_id')->orderBy('position');
    }
}
