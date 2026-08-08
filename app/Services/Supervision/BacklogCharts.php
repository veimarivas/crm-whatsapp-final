<?php

namespace App\Services\Supervision;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Gráficos nuevos del index de Seguimiento.
 *
 * Vive deliberadamente FUERA de `ResponseMetrics`: ese servicio es el gemelo
 * exacto del Komo (mismas definiciones, mismos tests) y tocar una definición
 * suya obliga a replicar el cambio allá. Esto solo LEE mensajes con las
 * mismas reglas:
 *   - «espera»: primer mensaje del cliente que no recibió respuesta humana.
 *   - un saliente humano cierra la espera; un saliente de IA NO.
 *
 * Trae cuatro vistas al index:
 *   - comparativa de MEDIANA de primera respuesta por agente (contra el SLA)
 *   - tendencia diaria de cumplimiento SLA (% de respuestas dentro del plazo)
 *   - heatmap hora × día de los mensajes entrantes («cuándo escriben»)
 *   - antigüedad del backlog (horas esperando respuesta humana)
 */
class BacklogCharts
{
    /** SLA de respuesta humana en minutos, igual que `ResponseMetrics`. */
    public const SLA_MINUTES = 30;

    private const DAYS = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];

    public function __construct(
        private readonly string $accountId,
        private readonly CarbonInterface $since,
    ) {}

    /**
     * @return array{
     *   median_by_agent: array<int, array<string, mixed>>,
     *   compliance: array<int, array<string, mixed>>,
     *   heatmap: array<int, array<string, mixed>>,
     *   backlog: array<int, array{label: string, count: int}>
     * }
     */
    public function build(): array
    {
        $conversations = Conversation::forAccount($this->accountId)
            ->get(['id', 'assigned_agent_id']);

        if ($conversations->isEmpty()) {
            return [
                'median_by_agent' => [],
                'compliance' => $this->dailySlaCompliance([]),
                'heatmap' => $this->emptyHeatmap(),
                'backlog' => collect($this->bucketLabels())
                    ->map(fn ($b) => ['label' => $b['label'], 'count' => 0])
                    ->values()->all(),
            ];
        }

        $members = User::where('account_id', $this->accountId)
            ->get(['id', 'name'])
            ->keyBy('id');

        $messages = Message::whereIn('conversation_id', $conversations->pluck('id'))
            ->whereIn('sender_type', [Message::SENDER_CUSTOMER, Message::SENDER_AGENT, Message::SENDER_BOT])
            ->where('created_at', '>=', $this->since)
            ->orderBy('created_at')
            ->get(['conversation_id', 'sender_type', 'created_at'])
            ->groupBy('conversation_id');

        $medianSamples = [];   // agent id => [primeras respuestas en segundos]
        $perDay = [];          // dia => ['total' => n, 'within' => n]
        $heatmap = $this->emptyHeatmap();
        $waitingMinutes = [];

        $agentOf = $conversations->pluck('assigned_agent_id', 'id');

        foreach ($messages as $conversationId => $timeline) {
            $awaitingSince = null;
            $lastCustomerAt = null;
            $waitingNow = false;
            $firstHumanSeconds = null;

            foreach ($timeline as $message) {
                if ($message->sender_type === Message::SENDER_CUSTOMER) {
                    $hour = (int) $message->created_at->format('G');
                    $dow = (int) $message->created_at->format('w');
                    $heatmap[$dow]['hours'][$hour] = ($heatmap[$dow]['hours'][$hour] ?? 0) + 1;

                    $awaitingSince ??= $message->created_at;
                    $lastCustomerAt = $message->created_at;
                    $waitingNow = true;
                    continue;
                }

                if ($message->sender_type === Message::SENDER_BOT) {
                    continue;
                }

                // Humano: un saliente que responde una espera abierta. Los
                // seguimientos proactivos no son respuestas y no entran en el
                // cumplimiento SLA ni en la mediana.
                if ($awaitingSince === null) {
                    continue;
                }

                $seconds = (int) $awaitingSince->diffInSeconds($message->created_at, true);
                $firstHumanSeconds ??= $seconds;

                $day = $message->created_at->format('Y-m-d');
                $perDay[$day]['total'] = ($perDay[$day]['total'] ?? 0) + 1;
                if ($seconds <= self::SLA_MINUTES * 60) {
                    $perDay[$day]['within'] = ($perDay[$day]['within'] ?? 0) + 1;
                }

                $awaitingSince = null;
                $waitingNow = false;
            }

            if ($lastCustomerAt !== null && $waitingNow) {
                $waitingMinutes[] = (int) $lastCustomerAt->diffInMinutes(now(), true);
            }

            // La primera respuesta se imputa a SU dueño, aunque un saliente
            // proactivo no la haya provocado.
            if ($firstHumanSeconds !== null) {
                $agentId = $agentOf[$conversationId] ?? null;
                if ($agentId !== null) {
                    $medianSamples[$agentId][] = $firstHumanSeconds;
                }
            }
        }

        return [
            'median_by_agent' => $this->medianByAgent($medianSamples, $members),
            'compliance' => $this->dailySlaCompliance($perDay),
            'heatmap' => $heatmap,
            'backlog' => $this->bucketize($waitingMinutes),
        ];
    }

    /**
     * Mediana de la primera respuesta por responsable (solo agentes con
     * respuestas medidas en la ventana). Ordenada de menor a mayor.
     *
     * @param  array<int|string, array<int, int>>  $samples  agent id => segundos
     * @param  Collection<int, User>  $members
     * @return array<int, array{id: string|null, name: string, median: int, responses: int}>
     */
    private function medianByAgent(array $samples, Collection $members): array
    {
        $rows = [];

        foreach ($samples as $agentId => $seconds) {
            sort($seconds);
            $n = count($seconds);
            $median = $n % 2 === 1
                ? $seconds[intdiv($n, 2)]
                : (int) round(($seconds[$n / 2 - 1] + $seconds[$n / 2]) / 2);

            $rows[] = [
                'id' => (string) $agentId,
                'name' => $members->firstWhere('id', $agentId)?->name ?? 'Sin asignar',
                'median' => $median,
                'responses' => $n,
            ];
        }

        usort($rows, fn ($a, $b) => $a['median'] <=> $b['median']);

        return $rows;
    }

    /**
     * Serie diaria de cumplimiento SLA: días sin respuestas → pct null.
     *
     * @param  array<string, array{total?: int, within?: int}>  $perDay
     * @return array<int, array{date: string, label: string, total: int, within: int, pct: int|null}>
     */
    private function dailySlaCompliance(array $perDay): array
    {
        $out = [];
        $cursor = $this->since->copy()->startOfDay();
        $end = now()->startOfDay();

        while ($cursor <= $end) {
            $key = $cursor->format('Y-m-d');
            $bucket = $perDay[$key] ?? [];
            $total = $bucket['total'] ?? 0;
            $within = $bucket['within'] ?? 0;

            $out[] = [
                'date' => $key,
                'label' => $cursor->translatedFormat('d M'),
                'total' => $total,
                'within' => $within,
                'pct' => $total ? (int) round(($within / $total) * 100) : null,
            ];

            $cursor = $cursor->addDay();
        }

        return $out;
    }

    /** Rejilla vacía 7 × 24 para el heatmap (día 0 = Dom). */
    private function emptyHeatmap(): array
    {
        return collect(self::DAYS)->map(fn ($label) => [
            'label' => $label,
            'hours' => array_fill(0, 24, 0),
        ])->values()->all();
    }

    /** Baldes de antigüedad del backlog en minutos. El último no tiene techo. */
    private function bucketLabels(): array
    {
        return [
            ['label' => '‹ 1 h', 'min' => 0, 'max' => 60],
            ['label' => '1-4 h', 'min' => 60, 'max' => 240],
            ['label' => '4-8 h', 'min' => 240, 'max' => 480],
            ['label' => '8-24 h', 'min' => 480, 'max' => 1440],
            ['label' => '› 24 h', 'min' => 1440, 'max' => null],
        ];
    }

    /** @param array<int, int> $minutes */
    private function bucketize(array $minutes): array
    {
        $counts = array_fill(0, count($this->bucketLabels()), 0);

        foreach ($minutes as $m) {
            foreach ($this->bucketLabels() as $i => $bucket) {
                if ($m >= $bucket['min'] && ($bucket['max'] === null || $m < $bucket['max'])) {
                    $counts[$i]++;
                    break;
                }
            }
        }

        return collect($this->bucketLabels())
            ->map(fn ($b, $i) => ['label' => $b['label'], 'count' => $counts[$i]])
            ->values()
            ->all();
    }
}