<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['account_id', 'external_id', 'name', 'color'])]
class Tag extends Model
{
    use BelongsToAccount, HasUuids;

    public const UPDATED_AT = null;

    /**
     * Una etiqueta sin `external_id` es LOCAL: la creó este proyecto (o quedó
     * huérfana al borrarla en Komo estando en uso) y el sync no la toca.
     */
    public function isManagedByKomo(): bool
    {
        return $this->external_id !== null;
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_tags');
    }
}
