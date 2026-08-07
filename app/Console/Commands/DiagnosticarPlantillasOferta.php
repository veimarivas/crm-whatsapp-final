<?php

namespace App\Console\Commands;

use App\Services\Academico\Plantillas;
use App\Services\Ai\OfertaAcademica;
use App\Services\Automations\Recipes as AutomationRecipes;
use App\Services\Flows\Recipes as FlowRecipes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Por qué `/automations` y `/flows` no muestran plantillas con la oferta.
 *
 * Recorre el MISMO camino que la petición web, paso a paso, y dice en
 * cuál se pierde. Existe porque `wacrm:sync-oferta-academica --dry-run`
 * no sirve para descartar: ese comando consulta la BD directo, y la web
 * pasa además por el caché y por el generador de plantillas — que es
 * justo donde se puede romper sin que la consulta falle.
 */
class DiagnosticarPlantillasOferta extends Command
{
    protected $signature = 'wacrm:diagnosticar-plantillas {--fresh : Limpia los cachés de la oferta antes de medir}';

    protected $description = 'Explica por qué no aparecen las plantillas generadas con la oferta académica';

    public function handle(OfertaAcademica $oferta, Plantillas $plantillas): int
    {
        if ($this->option('fresh')) {
            Cache::forget('esam_datos:disponible');
            Cache::forget('esam_datos:programas');
            $this->warn('Cachés de la oferta limpiados.');
            $this->newLine();
        }

        // 1. Conexión
        $this->line('<options=bold>1. Conexión a esam_datos</>');
        try {
            DB::connection('esam_datos')->select('SELECT 1');
            $this->info('   OK — la base responde.');
        } catch (\Throwable $e) {
            $this->error('   FALLA: '.$e->getMessage());
            $this->line('   → Revisa ESAM_DB_* en el .env y que el usuario tenga SELECT en esa base.');

            return self::FAILURE;
        }

        // 2. Consulta directa (lo que mide --dry-run)
        $this->newLine();
        $this->line('<options=bold>2. Consulta directa de programas</>');
        try {
            $directo = collect($oferta->programas());
            $this->info("   {$directo->count()} programas con inscripciones abiertas (estado_id = ".OfertaAcademica::ESTADO_INSCRIPCIONES.').');

            if ($directo->isEmpty()) {
                $this->warn('   → Sin programas en inscripciones no hay nada que generar. La IA tampoco tendría qué contestar.');

                return self::SUCCESS;
            }

            $conArea = $directo->filter(fn ($p) => trim($p->area_nombre ?? '') !== '')->count();
            $this->line("   Con área asignada: {$conArea} de {$directo->count()}.");
        } catch (\Throwable $e) {
            $this->error('   FALLA: '.$e->getMessage());

            return self::FAILURE;
        }

        // 3. La misma consulta, pero por el caché — que es por donde pasa la web.
        $this->newLine();
        $this->line('<options=bold>3. Consulta cacheada (la que usa la web)</>');
        try {
            $cacheado = collect($oferta->programasCacheadas());
            $this->info("   {$cacheado->count()} programas.");

            if ($cacheado->count() !== $directo->count()) {
                $this->warn('   → El caché no coincide con la consulta directa: hay una entrada vieja.');
                $this->line('   → Ejecuta este mismo comando con --fresh.');
            }
        } catch (\Throwable $e) {
            $this->error('   FALLA: '.$e->getMessage());

            return self::FAILURE;
        }

        // 4. El resumen que se manda a la pantalla
        $this->newLine();
        $this->line('<options=bold>4. Lo que recibe la pantalla</>');
        $resumen = $plantillas->resumen();

        foreach ($resumen as $clave => $valor) {
            $this->line("   {$clave}: ".var_export($valor, true));
        }

        if (! empty($resumen['error'])) {
            $this->newLine();
            $this->error('   La generación falló: '.$resumen['error']);
            $this->line('   → Ese mismo texto sale en el banner ámbar de /automations.');

            return self::FAILURE;
        }

        // 5. Recetas generadas
        $this->newLine();
        $this->line('<options=bold>5. Plantillas generadas</>');

        $automatizaciones = $plantillas->automatizaciones();
        $flows = $plantillas->flows();

        $this->line('   Automatizaciones: '.count($automatizaciones));
        foreach ($automatizaciones as $r) {
            $this->line("     · [{$r['slug']}] {$r['title']}");
        }

        $this->line('   Chatbots: '.count($flows));
        foreach ($flows as $r) {
            $this->line("     · [{$r['slug']}] {$r['title']} (".count($r['nodes']).' nodos)');
        }

        // 6. Lo que ve la galería, que es lo que se pinta
        $this->newLine();
        $this->line('<options=bold>6. Galería (lo que se pinta en pantalla)</>');

        $galeriaAuto = collect(AutomationRecipes::gallery());
        $galeriaFlow = collect(FlowRecipes::gallery());

        $this->line('   /automations → '.$galeriaAuto->where('source', 'oferta')->count().' de oferta + '
            .$galeriaAuto->where('source', 'base')->count().' genéricas.');
        $this->line('   /flows       → '.$galeriaFlow->where('source', 'oferta')->count().' de oferta + '
            .$galeriaFlow->where('source', 'base')->count().' genéricas.');

        $this->newLine();

        if ($galeriaAuto->where('source', 'oferta')->isEmpty()) {
            $this->error('Las plantillas NO se están generando. El paso que falló está arriba.');

            return self::FAILURE;
        }

        $this->info('✅ El backend SÍ está generando las plantillas.');
        $this->line('Si aun así no se ven en el navegador, el problema es el bundle de JS:');
        $this->line('   cd /var/www/crm-whatsapp && npm run build');
        $this->line('y recarga con Ctrl+Shift+R (el navegador cachea el JS viejo).');

        return self::SUCCESS;
    }
}
