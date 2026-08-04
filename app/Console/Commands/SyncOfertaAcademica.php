<?php

namespace App\Console\Commands;

use App\Models\AiConfig;
use App\Models\AiKnowledgeDocument;
use App\Services\Ai\Chunker;
use App\Services\Ai\OfertaAcademica;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Refresca la base de conocimiento de la IA con la oferta académica vigente
 * de `esam_datos`.
 *
 * Corre en horarios fijos (08:00, 14:00 y 18:00, ver el scheduler): la IA NO
 * consulta la BD académica en cada mensaje. Lo que queda guardado es texto ya
 * redactado, y sobre ese texto se contesta.
 *
 * Genera dos cosas por cuenta:
 *  - UN documento FIJO con el catálogo completo (entra en todos los prompts).
 *  - UN documento por programa con sus módulos, docentes y horarios.
 *
 * Cada corrida borra los documentos `[OFERTA] ` anteriores y los regenera, así
 * un programa que salió de inscripciones desaparece del conocimiento en la
 * siguiente pasada en vez de quedar contestándose para siempre.
 *
 * Uso:
 *   php artisan wacrm:sync-oferta-academica              # todas las cuentas con IA
 *   php artisan wacrm:sync-oferta-academica --account=UUID
 *   php artisan wacrm:sync-oferta-academica --dry-run
 */
class SyncOfertaAcademica extends Command
{
    protected $signature = 'wacrm:sync-oferta-academica {--account=} {--dry-run : Solo mostrar qué se importaría}';

    protected $description = 'Refresca la base de conocimiento IA con los programas ESAM en inscripciones';

    public function handle(Chunker $chunker, OfertaAcademica $oferta): int
    {
        // Sin --account se sincronizan TODAS las cuentas que tienen IA
        // configurada. Antes tomaba "la primera por created_at", que es una
        // trampa real: si esa no era la cuenta productiva, la IA se quedaba
        // sin conocimiento y contestaba inventando, sin ningún error visible.
        $accountIds = $this->option('account')
            ? [$this->option('account')]
            : AiConfig::query()->pluck('account_id')->unique()->values()->all();

        if (empty($accountIds)) {
            $this->error('Ninguna cuenta tiene IA configurada: no hay dónde guardar el conocimiento.');

            return self::FAILURE;
        }

        try {
            $programas = $oferta->programas();
        } catch (\Throwable $e) {
            // La BD académica es externa: si no responde, lo peor que se
            // puede hacer es borrar el conocimiento y dejar a la IA muda.
            $this->error('No se pudo leer la BD académica: '.$e->getMessage());
            $this->warn('Se conserva el conocimiento anterior — no se borró nada.');
            Log::error('sync-oferta-academica: BD académica inaccesible', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $this->info("Programas en inscripciones: {$programas->count()}");

        if ($this->option('dry-run')) {
            foreach ($programas as $p) {
                $this->line("  · [{$p->codigo}] {$p->nombre} ({$p->tipo_nombre})");
            }
            $this->newLine();
            $this->line('Cuentas que se actualizarían: '.count($accountIds));

            return self::SUCCESS;
        }

        // Cero programas con la BD respondiendo SÍ es un dato: significa que
        // no hay inscripciones abiertas y la IA debe decir exactamente eso.
        // Por eso acá sí se regenera (el catálogo lo dice con todas las letras).
        foreach ($accountIds as $accountId) {
            $this->newLine();
            $this->line("<options=bold>Cuenta {$accountId}</>");

            $borrados = AiKnowledgeDocument::forAccount($accountId)
                ->where('title', 'like', OfertaAcademica::DOC_PREFIX.'%')
                ->delete();
            $this->line("  Documentos anteriores eliminados: {$borrados}");

            // 1. Catálogo fijo.
            $catalogo = AiKnowledgeDocument::create([
                'account_id' => $accountId,
                'title' => OfertaAcademica::DOC_CATALOGO,
                'content' => $oferta->catalogo($programas),
                'is_pinned' => true,
            ]);
            $chunker->reindex($catalogo);
            $this->line('  Catálogo fijo regenerado.');

            // 2. Un documento de detalle por programa.
            $bar = $this->output->createProgressBar($programas->count());
            $bar->start();

            foreach ($programas as $p) {
                $doc = AiKnowledgeDocument::create([
                    'account_id' => $accountId,
                    // Título limpio (sin código): es lo que la IA usa para
                    // reconocer de qué programa habla el cliente.
                    'title' => OfertaAcademica::DOC_PREFIX.$p->nombre,
                    'content' => $oferta->programa($p),
                ]);
                $chunker->reindex($doc);
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();

            AiConfig::forAccount($accountId)->update(['knowledge_synced_at' => now()]);
            $this->info("  ✅ {$programas->count()} programas + catálogo indexados.");
        }

        return self::SUCCESS;
    }
}
