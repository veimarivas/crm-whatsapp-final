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
    'knowledge_synced_at',
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
            'knowledge_synced_at' => 'datetime',
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

        $current = $now->format('H:i');

        foreach ($hours[$dayKey] ?? [] as $range) {
            [$start, $end] = $range;

            if ($start === $end) {
                continue; // rango vacío: no abre
            }

            if ($start < $end) {
                if ($current >= $start && $current < $end) {
                    return true;
                }

                continue;
            }

            // Rango que cruza la medianoche (18:00–02:00): antes no entraba
            // nunca, porque "01:00 >= 18:00" es falso. Vale si estamos después
            // del inicio o antes del fin.
            if ($current >= $start || $current < $end) {
                return true;
            }
        }

        // Un tramo del día anterior que cruzó la medianoche sigue vigente en
        // la madrugada de hoy: el jueves 23:30 dentro de "miércoles 18:00–02:00"
        // ya está cubierto arriba, pero el jueves 01:00 pertenece al miércoles.
        $ayer = substr(strtolower($now->copy()->subDay()->englishDayOfWeek), 0, 3);

        foreach ($hours[$ayer] ?? [] as $range) {
            [$start, $end] = $range;

            if ($start > $end && $current < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * Próximo momento en que vuelve a atender, para poder decírselo al cliente
     * ("te respondemos mañana a las 08:00") en lugar de un genérico "estamos
     * fuera de horario".
     */
    public function nextOpeningAt(?\DateTimeInterface $when = null): ?Carbon
    {
        $hours = $this->business_hours;

        if (empty($hours)) {
            return null; // 24/7: no hay reapertura que anunciar
        }

        $tz = $this->timezone ?: 'America/La_Paz';
        $cursor = $when ? Carbon::instance($when)->setTimezone($tz) : Carbon::now($tz);

        // Se buscan 7 días: si en una semana no abre nunca, no hay respuesta
        // que dar y devolver null es más honesto que una fecha inventada.
        for ($i = 0; $i <= 7; $i++) {
            $dia = $cursor->copy()->addDays($i);
            $clave = substr(strtolower($dia->englishDayOfWeek), 0, 3);

            $inicios = collect($hours[$clave] ?? [])
                ->map(fn ($r) => $r[0])
                ->filter()
                ->sort()
                ->values();

            foreach ($inicios as $inicio) {
                [$h, $m] = array_pad(explode(':', $inicio), 2, '0');
                $momento = $dia->copy()->setTime((int) $h, (int) $m);

                if ($momento->greaterThan($cursor)) {
                    return $momento;
                }
            }
        }

        return null;
    }

    public function hasSemanticSearch(): bool
    {
        return ! empty($this->embeddings_api_key);
    }
}
