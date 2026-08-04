<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'created_by', 'title', 'content', 'is_pinned'])]
class AiKnowledgeDocument extends Model
{
    use BelongsToAccount, HasUuids;

    protected function casts(): array
    {
        return [
            // Documento fijo: va completo en cada prompt, sin pasar por la
            // búsqueda. Lo usa el catálogo de programas vigentes.
            'is_pinned' => 'boolean',
        ];
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(AiKnowledgeChunk::class, 'document_id')->orderBy('chunk_index');
    }
}
