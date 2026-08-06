<?php

namespace App\Services\Supervision;

use App\Models\Conversation;
use App\Models\Deal;
use App\Models\Message;
use App\Models\User;
use App\Services\WhatsApp\ServiceWindow;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Métricas de seguimiento para el admin: cómo está atendiendo cada agente.
 *
 * Gemelo del `Services\Supervision\ResponseMetrics` del Komo, pero acá se
 * calcula sobre `messages` (la fuente real) en vez de sobre el espejo de
 * eventos — y la atribución es exacta, porque `messages.sender_id` dice qué
 * usuario mandó cada saliente. **Si cambia una definición hay que tocar los
 * dos.**
 *
 * Definiciones — importan para leer bien los números:
 *
 *  - «Esperando»: el contacto escribió y todavía NO contestó un humano. Una
 *    respuesta de la IA NO cierra la espera: lo relevante es si el agente
 *    atendió, la IA solo gana tiempo.
 *  - «Tiempo de respuesta»: segundos entre que el contacto escribe (el primer
 *    mensaje de la ráfaga, no el último) y el primer saliente humano. Los
 *    mensajes seguidos del contacto no reinician el reloj.
 *  - «Primera respuesta»: el primero de esos tiempos en la conversación.
 *  - «Quién respondió primero»: quién mandó el primer saliente tras el primer
 *    entrante — la IA, el agente asignado, u otro del equipo.
 *
 * La ventana temporal recorta los mensajes: una conversación que arrancó
 * antes del periodo se mide solo por lo que pasó dentro de él.
 */
class ResponseMetrics
{
    /** Minutos sin respuesta humana a partir de los cuales se marca en rojo. */
    public const SLA_MINUTES = 30;

    /** Acumuladores por día (Y-m-d), llenados durante el recorrido. */
    private array $daily = [];

    /** Ventana de servicio de WhatsApp por conversación. */
    private array $windows = [];

    public function __construct(
        private readonly string $accountId,
        private readonly CarbonInterface $since,
    ) {}

    /**
     * @return array{agents: array<int, mixed>, conversations: array<int, mixed>, totals: array<string, mixed>, daily: array<int, mixed>, stages: array<int, mixed>}
     */
    public function build(): array
    {
        $conversations = Conversation::forAccount($this->accountId)
            ->with(['contact:id,name,phone', 'assignedAgent:id,name,account_role'])
            ->get(['id', 'contact_id', 'assigned_agent_id', 'status', 'created_at']);

        $perConversation = $this->measure($conversations->pluck('id')->all());

        $this->windows = app(ServiceWindow::class)->forMany(
            $conversations->pluck('id')->all()
        );

        $rows = $conversations
            ->filter(fn (Conversation $c) => isset($perConversation[$c->id]))
            ->map(fn (Conversation $c) => $this->row($c, $perConversation[$c->id]))
            ->sortByDesc(fn ($row) => $row['awaiting_minutes'] ?? -1)
            ->values();

        return [
            'agents' => $this->aggregateByAgent($rows, $conversations),
            'conversations' => $rows->all(),
            'totals' => $this->totals($rows),
            'daily' => $this->dailySeries(),
            'stages' => $this->stageDistribution(),
        ];
    }

    /**
     * Drill-down de UN agente (la «ficha» que la nota de alcance dejaba fuera):
     * KPIs, histograma de tiempos, embudo de sus negocios y los pendientes
     * operativos (esperando respuesta / ventana cerrada) de sus contactos.
     *
     * Reusa los mismos recorridos que `build()` para que un número signifique
     * lo mismo a nivel equipo que a nivel agente.
     *
     * @return array{agent: array<string, mixed>, kpis: array<string, mixed>, histogram: array<int, array<string, mixed>>, daily: array<int, array<string, mixed>>, conversations: array<int, array<string, mixed>>}
     */
    public function forAgent(string $userId): array
    {
        $agent = User::where('account_id', $this->accountId)->findOrFail($userId);

        $conversations = Conversation::forAccount($this->accountId)
            ->where('assigned_agent_id', $agent->id)
            ->with(['contact:id,name,phone', 'assignedAgent:id,name,account_role'])
            ->get(['id', 'contact_id', 'assigned_agent_id', 'status', 'created_at']);

        $perConversation = $this->measure($conversations->pluck('id')->all());

        $this->windows = app(ServiceWindow::class)->forMany(
            $conversations->pluck('id')->all()
        );

        $rows = $conversations
            ->filter(fn (Conversation $c) => isset($perConversation[$c->id]))
            ->map(fn (Conversation $c) => $this->row($c, $perConversation[$c->id]))
            ->sortByDesc(fn ($row) => $row['awaiting_minutes'] ?? -1)
            ->values();

        // Los KPIs salen del mismo agregador que el listado del equipo: tomamos
        // el bucket de este agente (los demás quedan vacíos con estas filas).
        $kpis = collect($this->aggregateByAgent($rows, $conversations))
            ->firstWhere('id', $agent->id);

        return [
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'role' => $agent->account_role,
                'email' => $agent->email,
            ],
            'kpis' => $kpis ?? [
                'name' => $agent->name,
                'role' => $agent->account_role,
                'assigned_contacts' => 0,
                'assigned_conversations' => 0,
                'open_conversations' => 0,
                'pending_conversations' => 0,
                'closed_conversations' => 0,
                'conversations' => 0,
                'answered' => 0,
                'never_answered' => 0,
                'waiting_now' => 0,
                'breached_sla' => 0,
                'window_closing' => 0,
                'window_closed' => 0,
                'avg_first_response_seconds' => null,
                'avg_response_seconds' => null,
                'slowest_response_seconds' => null,
                'ia_first' => 0,
                'assigned_first' => 0,
                'other_agent_first' => 0,
                'unknown_first' => 0,
                'messages_sent' => 0,
                'messages_received' => 0,
                'last_activity_at' => null,
                'deals_open' => 0,
                'deals_value' => 0.0,
                'by_stage' => [],
            ],
            'histogram' => $this->histogram($rows),
            'daily' => $this->dailySeries(),
            'conversations' => $rows->all(),
        ];
    }

    /**
     * Distribución de los tiempos de primera respuesta en baldes. Con la tercia
     * de valores que importa al leer un agente: ¿responde al instante, en
     * minutos, o deja pasar la media hora del SLA?
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array{label: string, count: int}>
     */
    private function histogram(Collection $rows): array
    {
        $buckets = [
            ['label' => 'Menos de 1 m', 'min' => null, 'max' => 60],
            ['label' => '1 a 5 m', 'min' => 60, 'max' => 300],
            ['label' => '5 a 15 m', 'min' => 300, 'max' => 900],
            ['label' => '15 a 30 m', 'min' => 900, 'max' => 1800],
            ['label' => '30 m a 1 h', 'min' => 1800, 'max' => 3600],
            ['label' => 'Más de 1 h', 'min' => 3600, 'max' => null],
        ];

        $counts = array_fill(0, count($buckets), 0);

        foreach ($rows as $row) {
            $seconds = $row['first_response_seconds'];
            if ($seconds === null) {
                continue;
            }
            foreach ($buckets as $i => $bucket) {
                if (($bucket['min'] === null || $seconds >= $bucket['min'])
                    && ($bucket['max'] === null || $seconds < $bucket['max'])) {
                    $counts[$i]++;
                    break;
                }
            }
        }

        return collect($buckets)
            ->map(fn (array $b, int $i) => ['label' => $b['label'], 'count' => $counts[$i]])
            ->values()
            ->all();
    }

    /**
     * Recorre los mensajes de cada conversación en orden y saca sus tiempos.
     *
     * @param  array<int, string>  $conversationIds
     * @return array<string, array<string, mixed>>
     */
    private function measure(array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        $messages = Message::whereIn('conversation_id', $conversationIds)
            ->whereIn('sender_type', [Message::SENDER_CUSTOMER, Message::SENDER_AGENT, Message::SENDER_BOT])
            ->where('created_at', '>=', $this->since)
            ->orderBy('created_at')
            ->get(['conversation_id', 'sender_type', 'sender_id', 'created_at'])
            ->groupBy('conversation_id');

        $out = [];

        foreach ($messages as $conversationId => $timeline) {
            $awaitingHumanSince = null;
            $inbound = 0;
            $humanReplies = 0;
            $botReplies = 0;
            $responseSeconds = [];
            $firstResponseSeconds = null;
            $firstResponder = null; // 'ia' | user id | 'desconocido'
            $lastAt = null;

            foreach ($timeline as $message) {
                $lastAt = $message->created_at;
                $day = $message->created_at->format('Y-m-d');

                if ($message->sender_type === Message::SENDER_CUSTOMER) {
                    $inbound++;
                    $this->daily[$day]['inbound'] = ($this->daily[$day]['inbound'] ?? 0) + 1;
                    // Solo el primer mensaje de la ráfaga arranca el reloj: si el
                    // contacto manda cinco seguidos, esperó desde el primero.
                    $awaitingHumanSince ??= $message->created_at;

                    continue;
                }

                if ($message->sender_type === Message::SENDER_BOT) {
                    $botReplies++;
                    $this->daily[$day]['bot'] = ($this->daily[$day]['bot'] ?? 0) + 1;
                    $firstResponder ??= 'ia';

                    continue;
                }

                $humanReplies++;
                $this->daily[$day]['human'] = ($this->daily[$day]['human'] ?? 0) + 1;
                $firstResponder ??= $message->sender_id ?? 'desconocido';

                // Un saliente humano sin espera abierta es un seguimiento
                // proactivo, no una respuesta: no entra en los promedios.
                if ($awaitingHumanSince !== null) {
                    $seconds = (int) $awaitingHumanSince->diffInSeconds($message->created_at, true);
                    $responseSeconds[] = $seconds;
                    $firstResponseSeconds ??= $seconds;
                    // La respuesta se imputa al día en que se dio, no al día en
                    // que entró el mensaje: la gráfica muestra el desempeño del
                    // turno que efectivamente atendió.
                    $this->daily[$day]['responses'][] = $seconds;
                    $awaitingHumanSince = null;
                }
            }

            $out[$conversationId] = [
                'inbound' => $inbound,
                'human_replies' => $humanReplies,
                'bot_replies' => $botReplies,
                'response_seconds' => $responseSeconds,
                'first_response_seconds' => $firstResponseSeconds,
                'first_responder' => $firstResponder,
                'awaiting_since' => $awaitingHumanSince,
                'last_at' => $lastAt,
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $m */
    private function row(Conversation $conversation, array $m): array
    {
        $awaitingMinutes = $m['awaiting_since']
            ? (int) $m['awaiting_since']->diffInMinutes(now(), true)
            : null;

        return [
            'id' => $conversation->id,
            'contact' => $conversation->contact?->name ?: $conversation->contact?->phone ?: 'Sin contacto',
            'phone' => $conversation->contact?->phone,
            'status' => $conversation->status,
            'agent' => $conversation->assignedAgent
                ? ['id' => $conversation->assignedAgent->id, 'name' => $conversation->assignedAgent->name]
                : null,
            'inbound' => $m['inbound'],
            'human_replies' => $m['human_replies'],
            'bot_replies' => $m['bot_replies'],
            'first_response_seconds' => $m['first_response_seconds'],
            'avg_response_seconds' => $m['response_seconds']
                ? (int) round(array_sum($m['response_seconds']) / count($m['response_seconds']))
                : null,
            'slowest_response_seconds' => $m['response_seconds'] ? max($m['response_seconds']) : null,
            'first_responder' => $this->classifyFirstResponder($conversation, $m['first_responder']),
            'awaiting_minutes' => $awaitingMinutes,
            'breached_sla' => $awaitingMinutes !== null && $awaitingMinutes >= self::SLA_MINUTES,
            'service_window' => $this->windows[$conversation->id] ?? null,
            'last_activity_at' => $m['last_at'],
        ];
    }

    /**
     * 'ia' | 'asignado' | 'otro_agente' | 'sin_identificar' | 'sin_respuesta'.
     * Distinguir «asignado» de «otro agente» es lo que deja ver si el dueño de
     * la conversación la está trabajando o se la están cubriendo.
     */
    private function classifyFirstResponder(Conversation $conversation, mixed $firstResponder): string
    {
        return match (true) {
            $firstResponder === null => 'sin_respuesta',
            $firstResponder === 'ia' => 'ia',
            $firstResponder === 'desconocido' => 'sin_identificar',
            $firstResponder === $conversation->assigned_agent_id => 'asignado',
            default => 'otro_agente',
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  Collection<int, Conversation>  $conversations
     * @return array<int, array<string, mixed>>
     */
    private function aggregateByAgent(Collection $rows, Collection $conversations): array
    {
        $members = User::where('account_id', $this->accountId)
            ->orderBy('name')
            ->get(['id', 'name', 'account_role']);

        // Negocios abiertos por responsable, para ver en qué etapa quedó lo
        // que cada uno viene trabajando.
        $deals = Deal::forAccount($this->accountId)
            ->where('status', 'open')
            ->with('stage:id,name,color')
            ->get(['id', 'assigned_to', 'stage_id', 'value']);

        $buckets = $members->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'role' => $u->account_role,
        ])->push(['id' => null, 'name' => 'Sin asignar', 'role' => null]);

        return $buckets
            ->map(function (array $bucket) use ($rows, $conversations, $deals) {
                $mine = $rows->filter(fn ($r) => ($r['agent']['id'] ?? null) === $bucket['id']);

                // TODAS sus conversaciones, con o sin actividad en el periodo:
                // es la carga real que tiene encima, no solo lo que se movió.
                $assigned = $conversations->filter(fn (Conversation $c) => $c->assigned_agent_id === $bucket['id']);

                if ($mine->isEmpty() && $assigned->isEmpty()) {
                    return null;
                }

                $firstResponses = $mine->pluck('first_response_seconds')->filter(fn ($s) => $s !== null);
                $avgResponses = $mine->pluck('avg_response_seconds')->filter(fn ($s) => $s !== null);
                $myDeals = $deals->filter(fn (Deal $d) => $d->assigned_to === $bucket['id']);

                return [
                    ...$bucket,
                    // Carga asignada (independiente del periodo)
                    'assigned_contacts' => $assigned->pluck('contact_id')->filter()->unique()->count(),
                    'assigned_conversations' => $assigned->count(),
                    'open_conversations' => $assigned->where('status', Conversation::STATUS_OPEN)->count(),
                    'pending_conversations' => $assigned->where('status', Conversation::STATUS_PENDING)->count(),
                    'closed_conversations' => $assigned->where('status', Conversation::STATUS_CLOSED)->count(),
                    // Actividad del periodo
                    'conversations' => $mine->count(),
                    'answered' => $mine->filter(fn ($r) => $r['human_replies'] > 0)->count(),
                    'never_answered' => $mine->filter(fn ($r) => $r['human_replies'] === 0)->count(),
                    'waiting_now' => $mine->filter(fn ($r) => $r['awaiting_minutes'] !== null)->count(),
                    'breached_sla' => $mine->filter(fn ($r) => $r['breached_sla'])->count(),
                    'window_closing' => $mine->filter(fn ($r) => ($r['service_window']['is_expiring'] ?? false))->count(),
                    'window_closed' => $mine->filter(fn ($r) => ($r['service_window']['source'] ?? 'none') !== 'none'
                        && ! ($r['service_window']['is_open'] ?? false))->count(),
                    'avg_first_response_seconds' => $firstResponses->isNotEmpty()
                        ? (int) round($firstResponses->avg()) : null,
                    'avg_response_seconds' => $avgResponses->isNotEmpty()
                        ? (int) round($avgResponses->avg()) : null,
                    'slowest_response_seconds' => $mine->pluck('slowest_response_seconds')
                        ->filter(fn ($s) => $s !== null)->max(),
                    'ia_first' => $mine->filter(fn ($r) => $r['first_responder'] === 'ia')->count(),
                    'assigned_first' => $mine->filter(fn ($r) => $r['first_responder'] === 'asignado')->count(),
                    'other_agent_first' => $mine->filter(fn ($r) => $r['first_responder'] === 'otro_agente')->count(),
                    'unknown_first' => $mine->filter(fn ($r) => $r['first_responder'] === 'sin_identificar')->count(),
                    'messages_sent' => $mine->sum('human_replies'),
                    'messages_received' => $mine->sum('inbound'),
                    'last_activity_at' => $mine->pluck('last_activity_at')->filter()->max(),
                    'deals_open' => $myDeals->count(),
                    'deals_value' => (float) $myDeals->sum('value'),
                    'by_stage' => $myDeals
                        ->groupBy(fn (Deal $d) => $d->stage?->name ?? 'Sin etapa')
                        ->map(fn ($group, $name) => [
                            'name' => $name,
                            'color' => $group->first()->stage?->color,
                            'count' => $group->count(),
                        ])->values()->all(),
                ];
            })
            ->filter()
            ->sortByDesc('breached_sla')
            ->values()
            ->all();
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function totals(Collection $rows): array
    {
        $firstResponses = $rows->pluck('first_response_seconds')->filter(fn ($s) => $s !== null);

        return [
            'conversations' => $rows->count(),
            'waiting_now' => $rows->filter(fn ($r) => $r['awaiting_minutes'] !== null)->count(),
            'breached_sla' => $rows->filter(fn ($r) => $r['breached_sla'])->count(),
            'never_answered' => $rows->filter(fn ($r) => $r['human_replies'] === 0)->count(),
            'avg_first_response_seconds' => $firstResponses->isNotEmpty()
                ? (int) round($firstResponses->avg()) : null,
            'ia_first' => $rows->filter(fn ($r) => $r['first_responder'] === 'ia')->count(),
            // Conversaciones a las que ya no se les puede escribir gratis:
            // el admin necesita verlo antes de reclamar una respuesta.
            'window_closed' => $rows->filter(fn ($r) => ($r['service_window']['source'] ?? 'none') !== 'none'
                && ! ($r['service_window']['is_open'] ?? false))->count(),
            'sla_minutes' => self::SLA_MINUTES,
        ];
    }

    /**
     * Serie por día. Rellena los días sin actividad con ceros — un hueco en el
     * eje se leería como continuidad.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dailySeries(): array
    {
        $out = [];
        $cursor = $this->since->copy()->startOfDay();
        $end = now()->startOfDay();

        while ($cursor <= $end) {
            $key = $cursor->format('Y-m-d');
            $bucket = $this->daily[$key] ?? [];
            $responses = $bucket['responses'] ?? [];

            $out[] = [
                'date' => $key,
                'label' => $cursor->translatedFormat('d M'),
                'inbound' => $bucket['inbound'] ?? 0,
                'human_replies' => $bucket['human'] ?? 0,
                'bot_replies' => $bucket['bot'] ?? 0,
                'avg_response_seconds' => $responses
                    ? (int) round(array_sum($responses) / count($responses))
                    : null,
            ];

            $cursor = $cursor->addDay();
        }

        return $out;
    }

    /**
     * Reparto de los negocios abiertos por etapa: en qué proceso está lo que
     * el equipo viene trabajando.
     *
     * @return array<int, array<string, mixed>>
     */
    private function stageDistribution(): array
    {
        return Deal::forAccount($this->accountId)
            ->where('status', 'open')
            ->with('stage:id,name,color')
            ->get(['id', 'stage_id', 'value'])
            ->groupBy(fn (Deal $d) => $d->stage?->name ?? 'Sin etapa')
            ->map(fn ($group, $name) => [
                'name' => $name,
                'color' => $group->first()->stage?->color,
                'count' => $group->count(),
                'value' => (float) $group->sum('value'),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }
}
