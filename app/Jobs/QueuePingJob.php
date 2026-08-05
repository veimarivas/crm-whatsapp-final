<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Job de prueba: solo deja una marca en caché.
 *
 * Sirve para saber si el worker está VIVO. «Jobs en cola: 0» no lo prueba —
 * puede significar que el worker los consume al instante o que nadie está
 * encolando nada. Este se encola, y si en unos segundos aparece la marca es
 * porque hay alguien del otro lado procesando.
 */
class QueuePingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $token) {}

    public static function cacheKey(string $token): string
    {
        return "queue_ping:{$token}";
    }

    public function handle(): void
    {
        Cache::put(self::cacheKey($this->token), now()->toIso8601String(), 300);
    }
}
