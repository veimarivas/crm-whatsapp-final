<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'user_id', 'shortcut', 'content'])]
class QuickReply extends Model
{
    use BelongsToAccount, HasUuids;

    /**
     * Autor de la plantilla; null = compartida con todo el equipo.
     *
     * Faltaba, y el listado de Ajustes la carga con `with('user:id,name')`:
     * con cero plantillas Eloquent ni intenta resolverla, pero en cuanto
     * existía una sola la pantalla devolvía 500.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Substituye variables del contacto en el contenido. */
    public function render(Contact $contact): string
    {
        return strtr($this->content, [
            '{name}' => $contact->name ?? '',
            '{phone}' => $contact->phone ?? '',
            '{email}' => $contact->email ?? '',
            '{company}' => $contact->company ?? '',
        ]);
    }
}
