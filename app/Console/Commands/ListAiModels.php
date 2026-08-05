<?php

namespace App\Console\Commands;

use App\Models\AiConfig;
use App\Services\Ai\Client;
use Illuminate\Console\Command;

/**
 * Modelos que el proveedor ofrece ahora mismo.
 *
 * Los catálogos cambian seguido —sobre todo en los planes gratuitos— y un
 * nombre de modelo equivocado no falla al guardarlo: falla cuando un cliente
 * escribe y la IA no contesta. Mejor elegir de una lista real.
 *
 *   php artisan wacrm:ai-models
 *   php artisan wacrm:ai-models --provider=groq --key=gsk_...
 */
class ListAiModels extends Command
{
    protected $signature = 'wacrm:ai-models
        {--account= : UUID de la cuenta}
        {--provider= : Probar otro proveedor sin guardarlo (groq, gemini, openrouter, openai, ollama)}
        {--key= : API key a usar con --provider}
        {--filter= : Mostrar solo los que contengan este texto (ej: free)}';

    protected $description = 'Lista los modelos disponibles del proveedor de IA';

    public function handle(): int
    {
        $config = AiConfig::query()
            ->when($this->option('account'), fn ($q, $id) => $q->where('account_id', $id))
            ->first();

        // Con --provider se prueba una clave nueva ANTES de guardarla: si el
        // catálogo llega, la clave sirve.
        if ($proveedor = $this->option('provider')) {
            $config = new AiConfig([
                'provider' => $proveedor,
                'api_key' => $this->option('key'),
                'model' => '',
                'base_url' => $proveedor === 'ollama' ? 'http://127.0.0.1:11434' : null,
            ]);
        }

        if (! $config) {
            $this->error('No hay IA configurada. Usá --provider=groq --key=... para probar una clave.');

            return self::FAILURE;
        }

        $this->line('Proveedor: '.$config->provider);

        try {
            $modelos = Client::for($config)->availableModels();
        } catch (\Throwable $e) {
            $this->error('No se pudo consultar el catálogo: '.$e->getMessage());

            return self::FAILURE;
        }

        if (empty($modelos)) {
            $this->warn('El proveedor no devolvió modelos. Revisá la API key.');

            return self::FAILURE;
        }

        $total = count($modelos);

        // OpenRouter devuelve cientos: sin filtro la lista es ilegible y no
        // sirve para lo que existe este comando, que es elegir uno.
        if ($filtro = $this->option('filter')) {
            $modelos = array_values(array_filter(
                $modelos,
                fn ($m) => str_contains(mb_strtolower($m), mb_strtolower($filtro)),
            ));

            if (empty($modelos)) {
                $this->warn("Ninguno de los {$total} modelos contiene «{$filtro}».");

                return self::FAILURE;
            }
        }

        $this->newLine();
        foreach ($modelos as $modelo) {
            $actual = $modelo === $config->model ? '  <fg=green>(en uso)</>' : '';
            $this->line('  · '.$modelo.$actual);
        }

        if (count($modelos) < $total) {
            $this->newLine();
            $this->line('  ('.count($modelos)." de {$total}; sin --filter se listan todos)");
        }

        if ($config->provider === 'openrouter' && ! $filtro) {
            $this->newLine();
            $this->line('  Para ver solo los gratuitos: --filter=free');
        }

        $this->newLine();
        $this->line('Se elige en Ajustes → IA, campo «Modelo».');

        return self::SUCCESS;
    }
}
