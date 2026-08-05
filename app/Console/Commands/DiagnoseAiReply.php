<?php

namespace App\Console\Commands;

use App\Jobs\AiAutoReplyJob;
use App\Jobs\QueuePingJob;
use App\Models\Account;
use App\Models\AiConfig;
use App\Models\AiReplyAttempt;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\FlowRun;
use App\Models\Message;
use App\Services\Ai\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Por qué la IA no contestó esta conversación.
 *
 * «El toggle dice IA activa y no responde» tiene SIETE causas distintas, y
 * ninguna deja rastro en pantalla: el job simplemente hace `return`. Este
 * comando recorre las mismas puertas en el mismo orden que `AiAutoReplyJob` y
 * dice cuál es la que la frena.
 *
 * Uso:
 *   php artisan wacrm:ai-doctor                      # estado general de la cuenta
 *   php artisan wacrm:ai-doctor --phone=59171234567  # una conversación concreta
 *   php artisan wacrm:ai-doctor --conversation=UUID
 */
class DiagnoseAiReply extends Command
{
    protected $signature = 'wacrm:ai-doctor
        {--conversation= : UUID de la conversación}
        {--phone= : Teléfono del contacto (se normaliza solo)}
        {--account= : UUID de la cuenta (por defecto, la primera)}
        {--reactivate : Vuelve a encender la IA en esa conversación}
        {--reactivate-all : Vuelve a encender la IA en TODAS las conversaciones apagadas por fallas}
        {--skip-worker : No probar la cola (la prueba tarda unos segundos)}';

    protected $description = 'Diagnostica por qué la IA no responde: config, horario, tope, pausa, flows y cola';

    public function handle(): int
    {
        // Se prefiere una cuenta que tenga IA configurada: en un servidor con
        // varias, diagnosticar la que no la usa no dice nada.
        $accountId = $this->option('account')
            ?? AiConfig::query()->value('account_id')
            ?? Conversation::query()->value('account_id')
            ?? Account::query()->orderBy('created_at')->value('id');

        if (! $accountId) {
            $this->error('No hay ninguna cuenta en este wacrm.');

            return self::FAILURE;
        }

        $this->line('');
        $this->line('<options=bold>1. Configuración de IA de la cuenta</>');

        $config = AiConfig::forAccount($accountId)->first();

        if (! $config) {
            $this->error('  ✗ La cuenta no tiene IA configurada. Ajustes → IA.');

            return self::FAILURE;
        }

        $problemas = 0;

        // Las dos banderas que el job exige para siquiera empezar.
        $this->estado($config->is_active, 'Asistente activo (is_active)', 'Está apagado: Ajustes → IA → activar.', $problemas);
        $this->estado($config->auto_reply_enabled, 'Auto-respuesta activada (auto_reply_enabled)', 'Solo genera borradores; no contesta sola. Ajustes → IA.', $problemas);

        $this->line("  · Proveedor: {$config->provider} / modelo: {$config->model}");
        $this->line('  · Tope por conversación: '.$config->auto_reply_max_per_conversation
            .' · pausa al agotarlo: '.($config->auto_reply_cooldown_hours ?: 3).' h');

        // El proveedor: "configurado" no es "responde".
        $alcanzable = Client::for($config)->isReachable();
        $this->estado($alcanzable, 'El proveedor responde', 'Ollama caído. En el servidor: systemctl status ollama', $problemas);

        $this->line('');
        $this->line('<options=bold>2. Horario de atención</>');

        if (empty($config->business_hours)) {
            $this->info('  ✓ Sin horario configurado: atiende 24/7.');
        } elseif ($config->isWithinBusinessHours()) {
            $this->info('  ✓ Ahora estamos dentro del horario.');
        } else {
            $proxima = $config->nextOpeningAt();
            // OJO: fuera de horario solo FRENA a la IA si además hay mensaje
            // configurado. Sin mensaje, el bot responde igual.
            if ($config->after_hours_message) {
                $this->warn('  ✗ Fuera de horario: en vez de responder envía el mensaje de ausencia.');
                $this->line('    Vuelve a atender: '.($proxima?->format('d/m/Y H:i') ?? 'nunca — revisá la grilla de días'));
                $problemas++;
            } else {
                $this->line('  · Fuera de horario, pero sin mensaje de ausencia configurado: responde igual.');
            }
        }

        $this->line('');
        $this->line('<options=bold>3. Base de conocimiento</>');
        $this->line('  · Última sincronización de la oferta: '
            .($config->knowledge_synced_at?->format('d/m/Y H:i') ?? 'NUNCA — corré wacrm:sync-oferta-academica'));

        $this->line('');
        $this->line('<options=bold>4. Cola de trabajos</>');

        // Sin worker el job ni siquiera se ejecuta: es el primer sospechoso
        // cuando "no pasa nada" y todo lo demás está bien.
        $pendientes = DB::table('jobs')->count();
        $fallidos = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

        $this->line("  · Jobs en cola: {$pendientes}");
        $this->line("  · Fallidos en las últimas 24 h: {$fallidos}");

        // La trampa que se lleva puestas las respuestas: si la cola da por
        // abandonado un job antes de que termine, lo vuelve a repartir MIENTRAS
        // SIGUE CORRIENDO. Resultado: el cliente recibe la respuesta dos veces
        // y el job muere con MaxAttemptsExceeded.
        $conexion = config('queue.default');
        // `sync` no tiene `retry_after`: el job corre en el acto y nadie lo
        // puede repartir dos veces. Solo aplica a las colas de verdad.
        $retryAfter = (int) config("queue.connections.{$conexion}.retry_after", 0);
        $jobTimeout = (int) config('services.ollama.timeout', 180) + 30;

        if ($retryAfter > 0 && $retryAfter <= $jobTimeout) {
            $this->error("  ✗ queue.connections.{$conexion}.retry_after = {$retryAfter}s, y la IA puede tardar {$jobTimeout}s.");
            $this->line('    La cola reparte el job de nuevo mientras el primero sigue trabajando:');
            $this->line('    el cliente recibe dos respuestas y el job muere por "attempted too many times".');
            $this->line('    Arreglo: DB_QUEUE_RETRY_AFTER=600 en el .env (debe superar al timeout de la IA).');
            $problemas++;
        }

        // Contarlos no sirve de nada: lo que importa es POR QUÉ fallaron. Un
        // job que revienta fuera del try/catch no apaga la IA ni deja motivo
        // en la conversación — se ve como «sin bloqueos» y aun así el cliente
        // no recibe nada.
        if ($fallidos > 0) {
            $this->newLine();
            $this->warn('  Últimos fallos (esto es lo que hay que mirar):');

            $ultimos = DB::table('failed_jobs')
                ->orderByDesc('failed_at')
                ->limit(3)
                ->get(['payload', 'exception', 'failed_at']);

            foreach ($ultimos as $fila) {
                $clase = json_decode($fila->payload, true)['displayName'] ?? 'job';
                // La primera línea de la excepción es el mensaje; el resto es
                // el stack trace y acá no aporta.
                $mensaje = trim(strtok((string) $fila->exception, "\n"));

                $this->line('    · '.$fila->failed_at.'  '.class_basename($clase));
                $this->line('      '.mb_substr($mensaje, 0, 200));
            }

            $this->line('');
            $this->line('    Detalle completo: php artisan queue:failed');
            $this->line('    Reintentar todos:  php artisan queue:retry all');
        }

        if ($pendientes > 20) {
            $this->warn('  ✗ Hay muchos jobs encolados: el worker puede estar caído.');
            $this->line('    systemctl status crm-whatsapp-queue.service');
            $problemas++;
        }

        // «0 jobs en cola» no prueba nada: puede ser un worker que los consume
        // al instante o que nadie encola. Este ping lo distingue de verdad, y
        // sin worker la IA no responde por más que todo lo demás esté bien.
        if (! $this->option('skip-worker')) {
            $problemas += $this->checkWorker();
        }

        if ($this->option('reactivate-all')) {
            $this->reactivarTodas($accountId);

            return self::SUCCESS;
        }

        $conversation = $this->resolveConversation($accountId);

        if ($conversation) {
            $problemas += $this->diagnoseConversation($conversation, $config);
        } else {
            $this->line('');
            $this->line('Para revisar una conversación puntual: --phone=59171234567');
        }

        $this->line('');
        if ($problemas === 0) {
            $this->info('✅ Sin bloqueos detectados: la IA debería responder el próximo mensaje del cliente.');
        } else {
            $this->error("Se encontraron {$problemas} motivo(s) por los que la IA no responde (ver arriba).");
        }

        return $problemas === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * ¿Hay alguien procesando la cola? Se encola un job trivial y se espera
     * unos segundos a que deje su marca.
     */
    private function checkWorker(): int
    {
        $token = (string) Str::uuid();
        QueuePingJob::dispatch($token);

        $this->line('  · Probando el worker…');

        for ($i = 0; $i < 12; $i++) { // hasta ~12s
            if (Cache::has(QueuePingJob::cacheKey($token))) {
                Cache::forget(QueuePingJob::cacheKey($token));
                $this->info('  ✓ El worker está procesando la cola.');

                return 0;
            }

            sleep(1);
        }

        // Antes de acusar al worker de muerto: puede estar VIVO y ocupado.
        // Procesa un job a la vez, y una respuesta de la IA lo tiene tomado
        // hasta dos minutos — el ping espera detrás. Confundir «ocupado» con
        // «caído» manda a reiniciar un servicio que estaba trabajando bien.
        $enCurso = DB::table('jobs')->whereNotNull('reserved_at')->count();

        if ($enCurso > 0) {
            $this->warn('  ⏳ El worker está VIVO pero ocupado: hay '.$enCurso.' job(s) en curso.');
            $this->line('    Una respuesta de la IA lo toma hasta 2 minutos y el resto espera detrás.');
            $this->line('    Si pasa seguido, conviene un worker aparte solo para la IA (ver abajo).');
            $this->colaDedicada();

            return 0;
        }

        $this->error('  ✗ El worker NO procesó el job de prueba en 12s.');
        $this->line('    Sin worker, la IA no responde nunca: el job se encola y se queda ahí.');
        $this->line('    sudo systemctl status crm-whatsapp-queue.service');
        $this->line('    sudo systemctl restart crm-whatsapp-queue.service');

        return 1;
    }

    /**
     * Reenciende la IA en todo lo que se apagó solo por fallas.
     *
     * Después de cambiar de proveedor —o de arreglar el que estaba lento— las
     * conversaciones que se apagaron por timeouts siguen apagadas: nadie las
     * vuelve a encender. Una por una es inviable, y mientras tanto esos
     * clientes escriben y no les contesta nadie.
     *
     * No toca las que apagó una persona a propósito: ahí la decisión fue de
     * alguien y no de un error.
     */
    private function reactivarTodas(string $accountId): void
    {
        $this->newLine();
        $this->line('<options=bold>Reactivando conversaciones apagadas por fallas</>');

        $apagadas = Conversation::forAccount($accountId)
            ->where('ai_autoreply_disabled', true)
            ->where(fn ($q) => $q->where('ai_failure_count', '>', 0)->orWhereNull('ai_disabled_reason'))
            ->get();

        if ($apagadas->isEmpty()) {
            $this->info('  No hay ninguna apagada por fallas.');

            return;
        }

        $respondidas = 0;

        foreach ($apagadas as $conversation) {
            $conversation->setAiEnabled(true);

            $contacto = $conversation->contact?->name ?: $conversation->contact?->phone ?: $conversation->id;
            $this->line("  ✓ {$contacto}");

            // Si el último mensaje es del cliente, quedó una pregunta sin
            // responder: reactivar sin contestarla la deja esperando hasta que
            // vuelva a escribir, y puede no volver.
            $ultimo = Message::where('conversation_id', $conversation->id)
                ->latest('created_at')
                ->first(['sender_type']);

            if ($ultimo?->sender_type === Message::SENDER_CUSTOMER) {
                AiAutoReplyJob::dispatch($conversation->id);
                $respondidas++;
            }
        }

        $this->newLine();
        $this->info($apagadas->count().' conversación(es) reactivada(s).');

        if ($respondidas > 0) {
            $this->line("  {$respondidas} tenían una pregunta sin responder: se encoló la respuesta.");
        }
    }

    /** Cómo sacar la IA de la cola general, si está tapando al resto. */
    private function colaDedicada(): void
    {
        if (config('services.ai_context.queue')) {
            $this->line('    (Ya tenés cola dedicada: '.config('services.ai_context.queue').')');

            return;
        }

        $this->line('');
        $this->line('    Para que la IA no tape los webhooks ni los envíos:');
        $this->line('      1) En el .env:  AI_QUEUE=ia');
        $this->line('      2) Un servicio aparte que consuma esa cola:');
        $this->line('         php artisan queue:work --queue=ia --sleep=1 --tries=1 --max-time=3600');
        $this->line('      El worker actual sigue con lo demás y deja de esperar a la IA.');
    }

    private function resolveConversation(string $accountId): ?Conversation
    {
        if ($id = $this->option('conversation')) {
            return Conversation::find($id);
        }

        if ($phone = $this->option('phone')) {
            $normalizado = preg_replace('/\D+/', '', $phone);

            $contact = Contact::forAccount($accountId)
                ->where(fn ($q) => $q->where('phone_normalized', 'like', "%{$normalizado}%")
                    ->orWhere('phone', 'like', "%{$normalizado}%"))
                ->first();

            if (! $contact) {
                $this->warn("No hay ningún contacto con el teléfono {$phone} en esta cuenta.");

                return null;
            }

            return Conversation::forAccount($accountId)
                ->where('contact_id', $contact->id)
                ->latest('last_message_at')
                ->first();
        }

        return null;
    }

    /** Las puertas que evalúa el job, en el mismo orden. */
    private function diagnoseConversation(Conversation $conversation, AiConfig $config): int
    {
        $problemas = 0;

        $this->line('');
        $this->line('<options=bold>5. La conversación</>');
        $this->line('  · ID: '.$conversation->id);
        $this->line('  · Contacto: '.($conversation->contact?->name ?: $conversation->contact?->phone ?: '—'));
        $this->line('  · Último mensaje: '.($conversation->last_message_at?->diffForHumans() ?? '—'));

        if ($conversation->ai_autoreply_disabled) {
            $this->error('  ✗ IA APAGADA en esta conversación (ai_autoreply_disabled).');

            if ($conversation->ai_disabled_at) {
                $this->line('    Se apagó sola el '.$conversation->ai_disabled_at->format('d/m/Y H:i')
                    .' tras fallar '.$conversation->ai_failure_count.' vez/veces seguidas.');
            } else {
                $this->line('    Se apagó sola al fallar la IA, o alguien usó el toggle IA/Humano.');
            }

            if ($conversation->ai_disabled_reason) {
                $this->line('    Motivo: '.$conversation->ai_disabled_reason);
            } elseif ($conversation->ai_reply_count === 0) {
                // El caso que más desconcierta: nunca respondió y ya está
                // apagada. Casi siempre es el modelo frío en el primer intento.
                $this->line('    <fg=yellow>Nunca llegó a responder en esta conversación (contador en 0).</>');
                $this->line('    Suele ser el modelo frío: la primera consulta lo carga en memoria y se pasa del timeout.');
                $this->line('    Revisá el error exacto: grep "Auto-respuesta IA falló" storage/logs/laravel.log | tail -5');
            }

            $this->line('    Reactivar: el toggle IA/Humano del chat, o `wacrm:ai-doctor --conversation='
                .$conversation->id.' --reactivate`');

            if ($this->option('reactivate')) {
                // `setAiEnabled` además limpia pausa y contadores, y avisa a
                // Komo para que su toggle no quede mostrando lo contrario.
                $conversation->setAiEnabled(true);
                $this->info('    ✓ Reactivada.');

                // Si el último mensaje es del cliente, hay una pregunta sin
                // responder: reactivar y no contestarla la deja esperando a
                // que el cliente insista, y puede no insistir.
                $ultimo = Message::where('conversation_id', $conversation->id)
                    ->latest('created_at')
                    ->first(['sender_type']);

                if ($ultimo?->sender_type === Message::SENDER_CUSTOMER) {
                    AiAutoReplyJob::dispatch($conversation->id);
                    $this->info('    ✓ Quedaba una pregunta sin responder: se encoló la respuesta.');
                }

                return 0;
            }

            $problemas++;
        } else {
            $this->info('  ✓ La IA está habilitada en esta conversación.');

            if ($conversation->ai_failure_count > 0) {
                $this->warn('    Atención: lleva '.$conversation->ai_failure_count
                    .' falla(s) seguida(s). A la 2ª se apaga sola.');
            }
        }

        // La causa más confusa de todas: el toggle sigue diciendo "IA activa"
        // porque `ai_autoreply_disabled` es false, pero la conversación está
        // en pausa por haber agotado el tope. Se ve encendida y no contesta.
        if ($conversation->ai_paused_until && $conversation->ai_paused_until->isFuture()) {
            $this->error('  ✗ EN PAUSA hasta '.$conversation->ai_paused_until->format('d/m/Y H:i')
                .' ('.$conversation->ai_paused_until->diffForHumans().').');
            $this->line('    Agotó el tope de '.$config->auto_reply_max_per_conversation.' respuestas.');
            $this->line('    El toggle igual muestra «IA activa»: por eso parece que está encendida y muda.');
            $this->line('    Se reactiva sola al vencer, o a mano con el toggle IA/Humano.');
            $problemas++;
        }

        if (! $conversation->ai_paused_until && $conversation->ai_reply_count >= $config->auto_reply_max_per_conversation) {
            $this->warn('  ✗ Contador en '.$conversation->ai_reply_count.'/'.$config->auto_reply_max_per_conversation
                .': el próximo mensaje del cliente la manda a pausa en vez de responder.');
            $problemas++;
        } else {
            $this->line('  · Respuestas de IA en esta conversación: '.$conversation->ai_reply_count
                .'/'.$config->auto_reply_max_per_conversation);
        }

        // Burbuja «Pensando respuesta…» encendida hace rato: el job murió sin
        // poder limpiar (timeout del worker, OOM, reinicio). Desde la pantalla
        // se ve como una IA que está por contestar y nunca contesta.
        if ($conversation->ai_pending) {
            $desde = $conversation->ai_pending_at;
            $minutos = $desde ? (int) $desde->diffInMinutes(now()) : null;

            if ($minutos === null || $minutos >= 5) {
                $this->error('  ✗ La burbuja "Pensando respuesta…" está encendida'
                    .($minutos !== null ? " hace {$minutos} min" : ' (sin marca de tiempo)').'.');
                $this->line('    El job se murió sin apagarla. Se barre sola cada 5 min, o a mano:');
                $this->line('    php artisan wacrm:ai-clear-stuck-pending');
                $problemas++;
            } else {
                $this->line('  · La IA está generando una respuesta ahora mismo ('.$minutos.' min).');
            }
        }

        // Lo que decidió el bot en los últimos mensajes. Todas las formas de
        // no responder se ven igual desde afuera; acá se ven distintas.
        $intentos = AiReplyAttempt::where('conversation_id', $conversation->id)
            ->latest('created_at')
            ->limit(8)
            ->get();

        if ($intentos->isNotEmpty()) {
            $this->newLine();
            $this->line('  <options=bold>Qué hizo la IA con cada mensaje:</>');

            foreach ($intentos->reverse() as $intento) {
                $etiqueta = AiReplyAttempt::LABELS[$intento->decision] ?? $intento->decision;
                $ms = $intento->duration_ms ? ' ('.round($intento->duration_ms / 1000, 1).'s)' : '';

                $linea = '    '.$intento->created_at->format('d/m H:i:s').'  '.$etiqueta.$ms;

                $intento->decision === 'enviada'
                    ? $this->info($linea)
                    : $this->warn($linea);

                if ($intento->detail) {
                    $this->line('      '.mb_substr($intento->detail, 0, 150));
                }
            }
        } else {
            $this->newLine();
            $this->line('  · Sin intentos registrados todavía (el registro empieza con el próximo mensaje).');
        }

        if (FlowRun::where('conversation_id', $conversation->id)->where('status', FlowRun::STATUS_ACTIVE)->exists()) {
            $this->error('  ✗ Hay un FLOW activo: el chatbot estructurado tiene prioridad y la IA se abstiene.');
            $problemas++;
        }

        // Audios: la IA no contesta hasta tener la transcripción. Si whisper
        // no corre, el cliente manda notas de voz y no le responde NADIE.
        $ultimo = Message::where('conversation_id', $conversation->id)
            ->where('sender_type', Message::SENDER_CUSTOMER)
            ->latest('created_at')
            ->first();

        if ($ultimo && $ultimo->content_type === 'audio' && ! $ultimo->transcript) {
            $this->error('  ✗ El último mensaje del cliente es un AUDIO sin transcribir.');
            $this->line('    La IA no contesta audios que no "escuchó": la encola TranscribeAudioJob al terminar.');
            $this->line('    Si whisper no está funcionando, esos mensajes se quedan sin respuesta para siempre.');
            $problemas++;
        }

        return $problemas;
    }

    private function estado(bool $ok, string $etiqueta, string $comoArreglar, int &$problemas): void
    {
        if ($ok) {
            $this->info("  ✓ {$etiqueta}");

            return;
        }

        $this->error("  ✗ {$etiqueta}");
        $this->line("    {$comoArreglar}");
        $problemas++;
    }
}
