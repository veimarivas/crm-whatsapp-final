<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'account_id', 'created_by', 'provider', 'model', 'base_url', 'api_key', 'embeddings_api_key',
    'system_prompt', 'is_active', 'auto_reply_enabled', 'auto_reply_max_per_conversation',
    'business_hours', 'after_hours_message', 'timezone', 'auto_reply_cooldown_hours',
])]
class AiConfig extends Model
{
    use BelongsToAccount, HasUuids;

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',            // clave BYO del proveedor
            'embeddings_api_key' => 'encrypted', // opcional, habilita búsqueda semántica
            'is_active' => 'boolean',
            'auto_reply_enabled' => 'boolean',
            'business_hours' => 'array',
        ];
    }

    /**
     * Determina si el momento actual está dentro del horario de atención
     * configurado. Sin business_hours seteado → siempre true (24/7).
     * business_hours es un array como:
     *   {"mon":[["08:00","19:00"]], "tue":[["08:00","19:00"]], ...}
     * Días ausentes o vacíos = cerrado ese día.
     */
    public function isWithinBusinessHours(?\DateTimeInterface $when = null): bool
    {
        $hours = $this->business_hours;
        if (empty($hours)) {
            return true; // sin config → 24/7
        }

        $tz = $this->timezone ?: 'America/La_Paz';
        $now = $when ? Carbon::instance($when)->setTimezone($tz) : Carbon::now($tz);
        $dayKey = strtolower($now->englishDayOfWeek); // monday, tuesday...
        $dayKey = substr($dayKey, 0, 3); // mon, tue, wed...

        $ranges = $hours[$dayKey] ?? [];
        if (empty($ranges)) {
            return false;
        }

        $current = $now->format('H:i');
        foreach ($ranges as $range) {
            [$start, $end] = $range;
            if ($current >= $start && $current < $end) {
                return true;
            }
        }

        return false;
    }

    public function hasSemanticSearch(): bool
    {
        return ! empty($this->embeddings_api_key);
    }
}
