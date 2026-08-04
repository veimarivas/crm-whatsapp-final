<?php

use App\Jobs\SendBroadcastJob;
use App\Models\Broadcast;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Encola los broadcasts cuya hora programada ya llegó. Reemplaza los
// endpoints cron del original: aquí lo dispara el scheduler de Laravel
// (requiere `php artisan schedule:work` o el cron de schedule:run).
Artisan::command('broadcasts:process-scheduled', function () {
    $due = Broadcast::where('status', 'scheduled')
        ->where('scheduled_at', '<=', now())
        ->get();

    foreach ($due as $broadcast) {
        $broadcast->update(['status' => 'sending']);
        SendBroadcastJob::dispatch($broadcast->id);
        $this->info("Broadcast {$broadcast->id} ({$broadcast->name}) encolado.");
    }

    $this->info("Procesados: {$due->count()}");
})->purpose('Encola los broadcasts programados que ya vencieron');

Schedule::command('broadcasts:process-scheduled')->everyMinute();

// Reanuda automatizaciones detenidas en un paso "wait" cuya hora venció.
Artisan::command('automations:process-pending', function (\App\Services\Automations\Engine $engine) {
    $due = \App\Models\AutomationPendingExecution::due()->limit(100)->get();

    foreach ($due as $pending) {
        $engine->resume($pending);
    }

    $this->info("Reanudadas: {$due->count()}");
})->purpose('Reanuda las esperas de automatizaciones vencidas');

Schedule::command('automations:process-pending')->everyMinute();

// Cierra runs de flows abandonados (cliente dejó de responder).
Artisan::command('flows:process-timeouts', function (\App\Services\Flows\Runner $runner) {
    $this->info('Runs cerrados por timeout: '.$runner->sweepTimeouts());
})->purpose('Cierra runs de flows que superaron el timeout de su política');

Schedule::command('flows:process-timeouts')->everyFiveMinutes();

// Cierra conversaciones abiertas sin actividad en 7 días. Se ejecuta 1×/día
// a las 3 AM (poca carga). Se reabren automáticamente si el cliente escribe.
Schedule::command('wacrm:auto-close-inactive --days=7')->dailyAt('03:00');

// Mantiene el modelo cargado en memoria. Ollama lo descarga tras unos minutos
// sin uso y recargarlo tarda decenas de segundos: sin esto, el cliente que
// escribe después de un rato de calma es el que se come el timeout — y la IA
// falla justo en el primer mensaje de la conversación.
//
// Cada 10 minutos con `keep_alive` de 30: alcanza de sobra y no molesta.
Schedule::command('wacrm:ai-warmup')
    ->everyTenMinutes()
    ->withoutOverlapping();

// Refresca la oferta académica que responde la IA: 08:00 antes de abrir,
// 14:00 después del mediodía y 18:00 para la tarde/noche.
//
// Tres veces al día y no en cada mensaje a propósito: consultar la BD
// académica por consulta multiplica la latencia de cada respuesta y da
// resultados distintos a dos personas que preguntan lo mismo. Acá se redacta
// una vez y todos leen el mismo texto.
//
// `withoutOverlapping` porque con muchos programas la corrida tarda y dos
// pasadas encimadas se pisan el borrado/regenerado.
Schedule::command('wacrm:sync-oferta-academica')
    ->dailyAt('08:00')
    ->withoutOverlapping();

Schedule::command('wacrm:sync-oferta-academica')
    ->dailyAt('14:00')
    ->withoutOverlapping();

Schedule::command('wacrm:sync-oferta-academica')
    ->dailyAt('18:00')
    ->withoutOverlapping();
