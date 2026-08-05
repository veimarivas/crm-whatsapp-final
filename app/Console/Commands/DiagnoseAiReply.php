<?php

namespace App\Console\Commands;

use App\Jobs\QueuePingJob;
use App\Models\Account;
use App\Models\AiConfig;
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

        $this->error('  ✗ El worker NO procesó el job de prueba en 12s.');
        $this->line('    Sin worker, la IA no responde nunca: el job se encola y se queda ahí.');
        $this->line('    sudo systemctl status crm-whatsapp-queue.service');
        $this->line('    sudo systemctl restart crm-whatsapp-queue.service');

        return 1;
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
                $conversation->update([
                    'ai_autoreply_disabled' => false,
                    'ai_failure_count' => 0,
                    'ai_disabled_reason' => null,
                    'ai_disabled_at' => null,
                ]);
                $this->info('    ✓ Reactivada. Responderá el próximo mensaje del cliente.');

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
