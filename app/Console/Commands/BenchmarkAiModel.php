<?php

namespace App\Console\Commands;

use App\Models\AiConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Mide dónde se va el tiempo de Ollama.
 *
 * `/api/tags` puede responder al instante y `/api/chat` morirse en el timeout:
 * el proceso está vivo, lo que no termina es la inferencia. Pero "tarda mucho"
 * son tres cosas distintas y se arreglan distinto:
 *
 *  - **Carga del modelo** (`load_duration`): el modelo estaba frío. Lo
 *    resuelve el warmup.
 *  - **Evaluación del prompt** (`prompt_eval_duration`): el prompt es
 *    demasiado grande para este hardware. Se arregla achicando el contexto.
 *  - **Generación** (`eval_duration`): la máquina es lenta para este modelo.
 *    Se arregla con un modelo más chico o menos tokens de respuesta.
 *
 * Ollama devuelve los tres números en cada respuesta; nadie los estaba mirando.
 */
class BenchmarkAiModel extends Command
{
    protected $signature = 'wacrm:ai-benchmark {--account=} {--timeout=300}';

    protected $description = 'Mide carga, evaluación de prompt y generación de Ollama para saber qué está lento';

    public function handle(): int
    {
        $config = AiConfig::query()
            ->when($this->option('account'), fn ($q, $id) => $q->where('account_id', $id))
            ->where('provider', 'ollama')
            ->first();

        if (! $config) {
            $this->error('Ninguna cuenta usa Ollama.');

            return self::FAILURE;
        }

        $base = rtrim($config->base_url ?: 'http://127.0.0.1:11434', '/');
        $timeout = (int) $this->option('timeout');

        $this->line("Modelo: {$config->model} · {$base}");
        $this->newLine();

        // 1) Prompt mínimo: aísla la carga del modelo y la velocidad base.
        $this->line('<options=bold>1. Prompt mínimo (¿cuánto tarda en arrancar?)</>');
        $corto = $this->medir($base, $config->model, 'Responde solo: ok', 16, $timeout);

        if (! $corto) {
            $this->newLine();
            $this->error('Con un prompt de 4 palabras tampoco responde.');
            $this->line('→ El problema no es el tamaño del prompt: es Ollama o la máquina.');
            $this->line('  Revisá memoria (free -h) y que el modelo esté descargado (ollama list).');

            return self::FAILURE;
        }

        // 2) Prompt grande: reproduce el peso real del catálogo + historial.
        $this->newLine();
        $this->line('<options=bold>2. Prompt grande (~6.000 caracteres, como el real)</>');
        $relleno = str_repeat('Programa de prueba con módulos, docentes y horarios. ', 120);
        $largo = $this->medir($base, $config->model, $relleno."\n\nResponde solo: ok", 16, $timeout);

        $this->newLine();
        $this->line('<options=bold>Veredicto</>');

        if (! $largo) {
            $this->error('Con el prompt grande no termina, con el chico sí.');
            $this->line('→ El cuello de botella es la EVALUACIÓN DEL PROMPT: demasiado texto para esta máquina.');
            $this->line('  Achicá el contexto: AI_PINNED_BUDGET y AI_HISTORY_MESSAGES en el .env (ver más abajo).');

            return self::FAILURE;
        }

        if ($corto['carga'] > 30) {
            $this->warn('La carga del modelo se lleva '.$corto['carga'].'s.');
            $this->line('→ Mantenelo caliente: el scheduler ya corre wacrm:ai-warmup cada 10 min.');
            $this->line('  Verificá que el cron de schedule:run esté vivo: crontab -l | grep schedule:run');
        }

        if ($largo['prompt_tps'] > 0 && $largo['prompt_tps'] < 40) {
            $this->warn('Evaluación de prompt lenta: '.$largo['prompt_tps'].' tokens/s.');
            $this->line('→ Un prompt de 6.000 tokens tardaría ~'.round(6000 / max(1, $largo['prompt_tps'])).'s solo en leerse.');
            $this->line('  Bajá AI_PINNED_BUDGET (catálogo) y AI_HISTORY_MESSAGES (historial) en el .env.');
        }

        if ($largo['gen_tps'] > 0 && $largo['gen_tps'] < 5) {
            $this->warn('Generación lenta: '.$largo['gen_tps'].' tokens/s.');
            $this->line('→ Con esa velocidad, 300 tokens de respuesta son '.round(300 / max(1, $largo['gen_tps'])).'s.');
            $this->line('  Considerá un modelo más chico (qwen2.5:3b) o bajar la respuesta máxima.');
        }

        $this->newLine();
        $this->line('Perillas disponibles en el .env (sin tocar código):');
        $this->line('  OLLAMA_TIMEOUT=180        · segundos que se espera a Ollama');
        $this->line('  OLLAMA_KEEP_ALIVE=30m     · cuánto queda el modelo en memoria');
        $this->line('  AI_PINNED_BUDGET=6000     · caracteres del catálogo que entran al prompt');
        $this->line('  AI_HISTORY_MESSAGES=10    · mensajes de historial que se le pasan');

        return self::SUCCESS;
    }

    /**
     * Una llamada con los tiempos que reporta el propio Ollama.
     *
     * @return array{carga: float, prompt_tps: float, gen_tps: float, total: float}|null
     */
    private function medir(string $base, string $model, string $prompt, int $maxTokens, int $timeout): ?array
    {
        $inicio = microtime(true);

        try {
            $response = Http::timeout($timeout)->post($base.'/api/generate', [
                'model' => $model,
                'prompt' => $prompt,
                'stream' => false,
                'keep_alive' => config('services.ollama.keep_alive', '30m'),
                'options' => ['num_predict' => $maxTokens],
            ]);
        } catch (\Throwable $e) {
            $this->error('  ✗ '.round(microtime(true) - $inicio).'s: '.$e->getMessage());

            return null;
        }

        if ($response->failed()) {
            $this->error('  ✗ HTTP '.$response->status().': '.($response->json('error') ?? ''));

            return null;
        }

        $d = $response->json();
        $ns = fn ($k) => round(($d[$k] ?? 0) / 1_000_000_000, 1); // nanosegundos → s

        $carga = $ns('load_duration');
        $promptTokens = (int) ($d['prompt_eval_count'] ?? 0);
        $promptSeg = $ns('prompt_eval_duration');
        $genTokens = (int) ($d['eval_count'] ?? 0);
        $genSeg = $ns('eval_duration');
        $total = $ns('total_duration');

        $promptTps = $promptSeg > 0 ? round($promptTokens / $promptSeg, 1) : 0;
        $genTps = $genSeg > 0 ? round($genTokens / $genSeg, 1) : 0;

        $this->info("  ✓ total {$total}s");
        $this->line("    carga del modelo: {$carga}s");
        $this->line("    leer el prompt:   {$promptSeg}s ({$promptTokens} tokens → {$promptTps} tok/s)");
        $this->line("    generar:          {$genSeg}s ({$genTokens} tokens → {$genTps} tok/s)");

        return ['carga' => $carga, 'prompt_tps' => $promptTps, 'gen_tps' => $genTps, 'total' => $total];
    }
}
