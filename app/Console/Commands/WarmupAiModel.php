<?php

namespace App\Console\Commands;

use App\Models\AiConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mantiene el modelo de Ollama cargado en memoria.
 *
 * Ollama descarga el modelo tras unos minutos sin uso, y volver a cargar
 * qwen2.5:7b tarda decenas de segundos. Eso significa que **el cliente que
 * escribe después de un rato de calma es justo el que se come el timeout**:
 * la IA falla con el primer mensaje de la conversación, que es el peor
 * momento posible para fallar.
 *
 * Se manda un request vacío con `keep_alive`: Ollama lo interpreta como
 * "cargá el modelo y dejalo residente", sin generar tokens ni gastar nada.
 */
class WarmupAiModel extends Command
{
    protected $signature = 'wacrm:ai-warmup {--account=}';

    protected $description = 'Precarga el modelo de Ollama para que no se enfríe entre conversaciones';

    /** Cuánto pedirle a Ollama que lo deje en memoria. */
    private const KEEP_ALIVE = '30m';

    public function handle(): int
    {
        $configs = AiConfig::query()
            ->where('provider', 'ollama')
            ->where('is_active', true)
            ->when($this->option('account'), fn ($q, $id) => $q->where('account_id', $id))
            ->get();

        if ($configs->isEmpty()) {
            $this->line('Ninguna cuenta usa Ollama: nada que precargar.');

            return self::SUCCESS;
        }

        $fallos = 0;

        // Una sola vez por (base_url, modelo): varias cuentas suelen compartir
        // la misma instancia y el mismo modelo.
        foreach ($configs->unique(fn ($c) => $c->base_url.'|'.$c->model) as $config) {
            $base = rtrim($config->base_url ?: 'http://127.0.0.1:11434', '/');

            try {
                $response = Http::timeout(180) // cargar un 7B puede tardar
                    ->post($base.'/api/generate', [
                        'model' => $config->model,
                        'prompt' => '',
                        'keep_alive' => self::KEEP_ALIVE,
                    ]);

                if ($response->successful()) {
                    $this->info("✓ {$config->model} listo en {$base} (residente ".self::KEEP_ALIVE.').');
                } else {
                    $fallos++;
                    $this->error("✗ {$config->model} en {$base}: HTTP {$response->status()}");
                }
            } catch (\Throwable $e) {
                $fallos++;
                $this->error("✗ {$config->model} en {$base}: ".$e->getMessage());
                Log::warning('ai-warmup falló', ['base_url' => $base, 'error' => $e->getMessage()]);
            }
        }

        return $fallos > 0 ? self::FAILURE : self::SUCCESS;
    }
}
