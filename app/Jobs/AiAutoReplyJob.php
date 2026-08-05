<?php

namespace App\Jobs;

use App\Models\AiConfig;
use App\Models\AiReplyAttempt;
use App\Models\Conversation;
use App\Models\FlowRun;
use App\Models\Message;
use App\Models\Notification;
use App\Models\User;
use App\Models\WhatsappConfig;
use App\Services\Ai\ReplyGenerator;
use App\Services\Webhooks\Dispatcher;
use App\Services\WhatsApp\Messenger;
use App\Services\WhatsApp\MetaApi;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Bot de auto-respuesta IA. Reglas:
 *  - Solo si la config está activa y auto_reply_enabled.
 *  - Respeta el apagado por conversación (ai_autoreply_disabled).
 *  - Tope de respuestas por conversación.
 *  - No interfiere con un flow activo.
 *
 * Ronda 14 (2026-07-23):
 *  - Marca conversation.ai_pending=true al arrancar (UI muestra "IA pensando...").
 *  - Envía typing indicator a WhatsApp para que el cliente vea "escribiendo...".
 *  - Si Ollama tarda >120s o falla, NO le manda nada al cliente: apaga la IA
 *    en esa conversación y avisa al agente responsable (o al owner) para que
 *    conteste un humano. Lo mismo cuando se agota el tope de respuestas.
 */
class AiAutoReplyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Tiene que ser SIEMPRE mayor que el timeout HTTP hacia el proveedor.
     *
     * Estaba fijo en 130 s contra los 120 s del cliente. Al subir el HTTP a
     * 180 s quedó al revés: el worker mataba el job a los 130 s, en pleno
     * request. Y un job MATADO no ejecuta su `catch`, así que no apagaba
     * `ai_pending` ni contaba la falla: la burbuja «Pensando respuesta…»
     * quedaba girando para siempre y no había ningún error registrado.
     *
     * Por eso se deriva de la config en vez de ser una constante: cambiar una
     * sin la otra es un bug silencioso.
     */
    public int $timeout;

    /**
     * Horas de pausa al agotar el tope, si la cuenta no configuró otra cosa.
     *
     * Tres es el equilibrio: suficiente para que un cliente confundido no
     * siga rebotando contra el bot (que es para lo que existe el tope), y lo
     * bastante corto como para que quien vuelve el mismo día encuentre
     * respuesta. Además entra holgado en la ventana de servicio de 24 h, así
     * que reactivarse no cuesta plata.
     */
    private const DEFAULT_COOLDOWN_HOURS = 3;

    /**
     * Fallas SEGUIDAS antes de apagar la IA en una conversación.
     *
     * Dos y no una: la primera consulta a un modelo frío lo carga en memoria y
     * se puede pasar del timeout. Con una sola, ese tropiezo dejaba la
     * conversación sin bot para siempre.
     */
    private const MAX_FAILURES = 2;

    /**
     * Cuántas veces se pospuso este job porque el modelo estaba ocupado.
     *
     * Se corta a las 5 (unos 2 minutos de espera): si en ese rato no se
     * liberó, algo más grave pasa y seguir reencolando solo tapa el problema.
     */
    private const MAX_REQUEUES = 5;

    private const REQUEUE_SECONDS = 25;

    public function __construct(
        public readonly string $conversationId,
        public readonly int $requeues = 0,
    ) {
        // 30 s de margen sobre el HTTP: alcanza para que el cliente corte por
        // su cuenta, se registre la falla y se apague la burbuja.
        $this->timeout = (int) config('services.ollama.timeout', 180) + 30;

        // Cola propia, si está configurada.
        //
        // Una respuesta de la IA tiene tomado al worker hasta dos minutos, y
        // detrás esperan los webhooks a Komo, los broadcasts y todo lo demás.
        // Con `AI_QUEUE=ia` y un worker dedicado, lo lento deja de tapar lo
        // rápido. Vacío por defecto: activarlo sin levantar ese worker dejaría
        // a la IA sin nadie que la atienda.
        if ($cola = config('services.ai_context.queue')) {
            $this->onQueue($cola);
        }
    }

    public function handle(ReplyGenerator $generator, Messenger $messenger): void
    {
        $conversation = Conversation::find($this->conversationId);

        if (! $conversation) {
            return;
        }

        if ($conversation->ai_autoreply_disabled) {
            AiReplyAttempt::registrar($conversation, 'ia_apagada', $conversation->ai_disabled_reason);

            return;
        }

        $config = AiConfig::forAccount($conversation->account_id)
            ->where('is_active', true)
            ->where('auto_reply_enabled', true)
            ->first();

        if (! $config) {
            AiReplyAttempt::registrar($conversation, 'sin_config');

            return;
        }

        // Si la pausa por tope ya venció, el contador vuelve a cero y la IA
        // retoma sola. Es lo que evita que un tope alcanzado deje muerta la
        // conversación para siempre.
        if ($conversation->ai_paused_until && $conversation->ai_paused_until->isPast()) {
            $conversation->update([
                'ai_reply_count' => 0,
                'ai_paused_until' => null,
                'ai_limit_notified_at' => null,
            ]);
            $conversation->refresh();
            $this->broadcastResumed($conversation);
        }

        // En pausa: se calla sin volver a avisar. El aviso ya salió cuando se
        // alcanzó el tope; repetirlo en cada mensaje sería ruido.
        if ($conversation->ai_paused_until) {
            AiReplyAttempt::registrar($conversation, 'pausa', 'hasta '.$conversation->ai_paused_until->format('d/m H:i'));

            return;
        }

        // Tope agotado: el cliente sigue escribiendo y el bot ya no contesta.
        // Es exactamente el momento en que un humano tiene que entrar, así que
        // se avisa (una sola vez) y se programa la reactivación.
        if ($conversation->ai_reply_count >= $config->auto_reply_max_per_conversation) {
            $horas = max(1, (int) ($config->auto_reply_cooldown_hours ?: self::DEFAULT_COOLDOWN_HOURS));
            $reanuda = now()->addHours($horas);

            $conversation->update(['ai_paused_until' => $reanuda]);

            AiReplyAttempt::registrar($conversation, 'tope', 'reanuda '.$reanuda->format('d/m H:i'));

            $this->notifyHumanNeeded(
                $conversation,
                'limit_reached',
                'La IA llegó a su tope en esta conversación',
                'La IA ya respondió '.$conversation->ai_reply_count.' veces a '
                    .$this->contactLabel($conversation).'. Vuelve a responder a las '
                    .$reanuda->format('H:i').'; hasta entonces seguí vos.',
            );

            return;
        }

        $flowActive = FlowRun::where('conversation_id', $conversation->id)
            ->where('status', FlowRun::STATUS_ACTIVE)
            ->exists();

        if ($flowActive) {
            AiReplyAttempt::registrar($conversation, 'flow_activo');

            return;
        }

        // Fuera de horario de atención: enviar mensaje configurado (una vez
        // por día por conversación para no spamear) y NO consumir Ollama.
        if (! $config->isWithinBusinessHours() && $config->after_hours_message) {
            $todayKey = now()->toDateString();
            $lastSent = cache()->get("after_hours_sent:{$conversation->id}:{$todayKey}");
            if (! $lastSent) {
                try {
                    $messenger->sendText($conversation, $this->afterHoursText($config));
                    cache()->put("after_hours_sent:{$conversation->id}:{$todayKey}", true, now()->endOfDay());
                } catch (\Throwable $e) {
                    Log::warning('After-hours message falló', ['conv_id' => $conversation->id, 'error' => $e->getMessage()]);
                }
            }

            return;
        }

        // Enciendo el flag efímero: la UI del Inbox pintará una burbuja
        // "IA pensando..." mientras dure este job.
        // Con la hora: si al job lo matan (timeout del worker, OOM, reinicio
        // en un despliegue) el `catch` no corre y esta bandera queda encendida
        // para siempre. `wacrm:ai-clear-stuck-pending` la barre, pero necesita
        // saber desde cuándo está así.
        $conversation->update(['ai_pending' => true, 'ai_pending_at' => now()]);
        $this->broadcastPending($conversation, true);

        // Typing indicator al cliente: le llega "escribiendo..." real de WA.
        // Dura ~25s. Best-effort: si falla no bloquea al bot.
        $this->sendTypingToCustomer($conversation);

        // Un modelo, una consulta a la vez.
        //
        // Ollama atiende de a una por modelo: si mandamos la segunda mientras
        // la primera sigue calculando, la segunda se queda esperando EN Ollama
        // sin recibir un solo byte hasta que la otra termine — y se come su
        // propio timeout ahí parada. Eso es exactamente el «0 bytes received»
        // que aparecía: no era Ollama caído, era Ollama ocupado.
        //
        // Con el candado, el que llega y encuentra ocupado se vuelve a encolar
        // para dentro de un rato en vez de irse a morir a la cola del modelo.
        // No todos los almacenes de caché soportan candados atómicos, y ahí
        // `Cache::lock()` no devuelve false: LANZA. Como esto corre antes del
        // try/catch, el job moría entero — sin apagar la burbuja, sin registrar
        // el motivo y sin que se viera nada en el diagnóstico, solo un job
        // fallido más. Si no hay candado disponible, se sigue sin él: el
        // problema que resuelve (dos consultas encimadas) es menos grave que
        // no responder nunca.
        $lock = rescue(
            fn () => Cache::lock('ai:generando:'.$config->account_id, $this->timeout + 30),
            function () {
                Log::warning('El almacén de caché no soporta candados: la IA corre sin serializar. '
                    .'Con CACHE_STORE=database o redis se evita que dos consultas se pisen.');

                return null;
            },
            report: false,
        );

        if ($lock && ! $lock->get()) {
            AiReplyAttempt::registrar($conversation, $this->requeues >= self::MAX_REQUEUES ? 'abandonada' : 'ocupado', 'intento '.($this->requeues + 1));

            if ($this->requeues >= self::MAX_REQUEUES) {
                Log::warning('La IA sigue ocupada tras varios intentos; se abandona esta respuesta', [
                    'conversation_id' => $conversation->id,
                ]);

                return;
            }

            self::dispatch($this->conversationId, $this->requeues + 1)
                ->delay(now()->addSeconds(self::REQUEUE_SECONDS));

            return;
        }

        // Con qué mensaje del cliente arranca esta respuesta. Generar tarda
        // decenas de segundos y en ese rato el cliente puede escribir otra
        // vez; si contestamos igual, le llegan DOS mensajes —uno por cada
        // pregunta— y el primero además responde a un contexto viejo.
        $mensajeAlEmpezar = $this->lastCustomerMessageId($conversation);
        $inicio = microtime(true);

        try {
            $reply = $generator->generate($config, $conversation);

            // Llegó una pregunta nueva mientras pensábamos: esta respuesta ya
            // nació vieja. Se descarta y contesta el job del mensaje nuevo,
            // que tiene todo el contexto —incluida esta pregunta—.
            if ($this->lastCustomerMessageId($conversation) !== $mensajeAlEmpezar) {
                Log::info('Respuesta de IA descartada: el cliente volvió a escribir mientras se generaba', [
                    'conversation_id' => $conversation->id,
                ]);

                AiReplyAttempt::registrar($conversation, 'descartada', null, $inicio);
                $conversation->update(['ai_pending' => false, 'ai_pending_at' => null]);
                $this->broadcastPending($conversation, false);

                return;
            }

            if ($reply === '') {
                AiReplyAttempt::registrar($conversation, 'vacia', 'el modelo no devolvió texto', $inicio);

                // El cliente preguntó y no recibió NADA. Antes esto era
                // silencio absoluto: ni error, ni aviso, ni rastro. Que la IA
                // no sepa qué decir es justamente cuando tiene que entrar un
                // humano.
                $this->notifyHumanNeeded(
                    $conversation,
                    'failed',
                    'La IA no supo qué responder',
                    'El modelo no devolvió texto para '.$this->contactLabel($conversation)
                        .'. Al cliente no se le envió nada: contestale vos.',
                );

                $conversation->update(['ai_pending' => false, 'ai_pending_at' => null]);
                $this->broadcastPending($conversation, false);

                return;
            }

            $messenger->sendText($conversation, $reply);
            AiReplyAttempt::registrar($conversation, 'enviada', mb_substr($reply, 0, 120), $inicio);
            $conversation->increment('ai_reply_count');
            // Una respuesta buena borra el historial de tropiezos: lo que
            // importa son las fallas SEGUIDAS, no las de hace tres días.
            $conversation->update(['ai_pending' => false, 'ai_pending_at' => null, 'ai_failure_count' => 0]);
            $this->broadcastPending($conversation, false);
        } catch (\Throwable $e) {
            Log::warning('Auto-respuesta IA falló', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
                'intento' => $conversation->ai_failure_count + 1,
            ]);

            AiReplyAttempt::registrar($conversation, 'fallo', $e->getMessage(), $inicio);
            $conversation->update(['ai_pending' => false, 'ai_pending_at' => null]);
            $this->broadcastPending($conversation, false);
            $this->deliverFallback($conversation, $e->getMessage());
        } finally {
            // Sin esto, un fallo dejaría el candado puesto hasta que expire y
            // la IA muda para toda la cuenta mientras tanto.
            $lock?->release();
        }
    }

    /**
     * Al fallar la IA: NO se le manda nada al cliente.
     *
     * Antes se enviaba un "Un asesor te atenderá en breve": delataba que
     * detrás había un bot y dejaba al cliente con una respuesta de relleno
     * en vez de una de verdad. Ahora la conversación queda tal cual y el
     * responsable se entera para contestar él.
     *
     * **Una falla sola ya no apaga la IA.** Antes sí, y era demasiado frágil:
     * la primera consulta a Ollama carga el modelo en memoria y puede pasarse
     * del timeout, así que un tropiezo perfectamente normal dejaba la
     * conversación sin bot PARA SIEMPRE — se veía como "IA apagada" sin que
     * nadie la hubiera apagado, y con el contador de respuestas en 0. Ahora
     * el primer fallo avisa y deja que el próximo mensaje reintente (para
     * entonces el modelo ya está caliente); recién el segundo seguido la
     * apaga, y deja escrito por qué.
     */
    private function deliverFallback(Conversation $conversation, string $error = ''): void
    {
        $fallas = $conversation->ai_failure_count + 1;
        $conversation->update(['ai_failure_count' => $fallas]);

        if ($fallas < self::MAX_FAILURES) {
            $this->notifyHumanNeeded(
                $conversation,
                'failed',
                'La IA no pudo responder (reintenta en el próximo mensaje)',
                'Falló la IA con '.$this->contactLabel($conversation)
                    .'. Al cliente no se le envió nada: contestale vos si no puede esperar.',
            );

            return;
        }

        $conversation->setAiEnabled(false, 'Falló '.$fallas.' veces seguidas: '.mb_substr($error, 0, 200));

        $this->notifyHumanNeeded(
            $conversation,
            'failed',
            'La IA se apagó en esta conversación',
            'Falló '.$fallas.' veces seguidas con '.$this->contactLabel($conversation)
                .'. Al cliente no se le envió nada: contestale vos. Podés reactivarla con el toggle IA/Humano.',
        );
    }

    /**
     * Avisa que esta conversación necesita un humano — al agente asignado, o
     * al owner si todavía no tiene responsable — y reenvía el evento al CRM
     * externo (Komo) para que le llegue también al responsable del lead.
     *
     * `limit_reached` se avisa una sola vez por conversación: el tope se sigue
     * superando en cada mensaje nuevo y no queremos repetir el aviso.
     */
    private function notifyHumanNeeded(Conversation $conversation, string $reason, string $title, string $body): void
    {
        if ($reason === 'limit_reached') {
            if ($conversation->ai_limit_notified_at) {
                return;
            }
            $conversation->update(['ai_limit_notified_at' => now()]);
        }

        $recipientId = $conversation->assigned_agent_id
            ?? User::where('account_id', $conversation->account_id)
                ->where('account_role', User::ROLE_OWNER)
                ->value('id');

        if ($recipientId) {
            Notification::create([
                'account_id' => $conversation->account_id,
                'user_id' => $recipientId,
                'type' => 'ai_fallback',
                'conversation_id' => $conversation->id,
                'contact_id' => $conversation->contact_id,
                'title' => $title,
                'body' => $body,
            ]);
        }

        try {
            app(Dispatcher::class)->dispatch(
                $conversation->account_id,
                'ai.unavailable',
                [
                    'conversation_id' => $conversation->id,
                    'reason' => $reason, // 'failed' | 'limit_reached'
                    'title' => $title,
                    'body' => $body,
                    // Para que el CRM externo pueda mostrar el mismo aviso en
                    // su chat, con la hora en que la IA retoma.
                    'paused_until' => $conversation->ai_paused_until?->toIso8601String(),
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('Webhook ai.unavailable falló', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Avisa al CRM externo que la pausa terminó, para que borre su aviso.
     * Sin esto Komo seguiría mostrando "en pausa" con una hora ya vencida.
     */
    private function broadcastResumed(Conversation $conversation): void
    {
        try {
            app(Dispatcher::class)->dispatch(
                $conversation->account_id,
                'ai.resumed',
                ['conversation_id' => $conversation->id],
            );
        } catch (\Throwable $e) {
            Log::warning('Webhook ai.resumed falló', ['error' => $e->getMessage()]);
        }
    }

    private function contactLabel(Conversation $conversation): string
    {
        return $conversation->contact->name ?: $conversation->contact->phone;
    }

    /**
     * Notifica a integraciones (Komo) que la IA cambió su estado de "pensando".
     * Komo lo usa para mostrar la misma burbuja violeta que el Inbox de wacrm.
     * Best-effort: si falla no bloquea al bot.
     */
    /**
     * Aviso de fuera de horario, con la hora real de reapertura si se puede
     * calcular.
     *
     * "Te respondemos mañana a las 08:00" corta la ansiedad de quien escribe
     * de noche; "estamos fuera de horario" a secas invita a insistir cinco
     * veces. El texto que configuró el negocio se respeta tal cual: solo se
     * le agrega la línea, y únicamente si no menciona ya un horario.
     */
    private function afterHoursText(AiConfig $config): string
    {
        $mensaje = $config->after_hours_message;
        $proxima = $config->nextOpeningAt();

        if (! $proxima || preg_match('/\d{1,2}[:h]\d{2}/', $mensaje)) {
            return $mensaje;
        }

        $tz = $config->timezone ?: 'America/La_Paz';
        $ahora = now($tz);

        $cuando = match (true) {
            $proxima->isSameDay($ahora) => 'hoy a las '.$proxima->format('H:i'),
            $proxima->isSameDay($ahora->copy()->addDay()) => 'mañana a las '.$proxima->format('H:i'),
            default => 'el '.$proxima->locale('es')->isoFormat('dddd D [a las] HH:mm'),
        };

        return trim($mensaje)."\n\nTe respondemos {$cuando}. 🙌";
    }

    /** Último mensaje del cliente en esta conversación. */
    private function lastCustomerMessageId(Conversation $conversation): ?string
    {
        return Message::where('conversation_id', $conversation->id)
            ->where('sender_type', Message::SENDER_CUSTOMER)
            ->latest('created_at')
            ->value('id');
    }

    private function broadcastPending(Conversation $conversation, bool $pending): void
    {
        try {
            app(Dispatcher::class)->dispatch(
                $conversation->account_id,
                'ai.pending_changed',
                ['conversation_id' => $conversation->id, 'pending' => $pending]
            );
        } catch (\Throwable $e) {
            Log::warning('Webhook ai.pending_changed falló', ['error' => $e->getMessage()]);
        }
    }

    /** Envía el typing indicator a WhatsApp usando el último wamid del cliente. */
    private function sendTypingToCustomer(Conversation $conversation): void
    {
        $lastCustomerWamid = Message::where('conversation_id', $conversation->id)
            ->where('sender_type', Message::SENDER_CUSTOMER)
            ->whereNotNull('message_id')
            ->latest('created_at')
            ->value('message_id');

        if (! $lastCustomerWamid) {
            return;
        }

        $config = WhatsappConfig::forAccount($conversation->account_id)
            ->where('status', 'connected')
            ->first();

        if ($config) {
            MetaApi::for($config)->sendTypingIndicator($lastCustomerWamid);
        }
    }
}
