<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'user_id', 'phone', 'phone_normalized', 'name', 'email', 'company', 'avatar_url'])]
class Contact extends Model
{
    use BelongsToAccount, HasUuids;

    protected static function booted(): void
    {
        static::saving(function (Contact $contact) {
            $contact->phone_normalized = self::normalizePhone($contact->phone);
        });
    }

    /** Solo dígitos — clave de deduplicación por cuenta (unique en BD). */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return $digits === '' ? null : $digits;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'contact_tags')->withPivot('created_at');
    }

    public function customValues(): HasMany
    {
        return $this->hasMany(ContactCustomValue::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ContactNote::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Cómo se llama esta persona en cada canal (F0).
     *
     * Es lo que permite que un contacto exista sin teléfono: hasta acá el
     * identificador ERA `phone_normalized`.
     */
    public function identities(): HasMany
    {
        return $this->hasMany(ContactIdentity::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }
}
