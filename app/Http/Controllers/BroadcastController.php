<?php

namespace App\Http\Controllers;

use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\MessageTemplate;
use App\Models\Tag;
use App\Models\WhatsappConfig;
use App\Services\Broadcasts\Creator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BroadcastController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Broadcasts/Index', [
            'broadcasts' => Broadcast::forAccount($request->user()->account_id)
                ->orderByDesc('created_at')
                ->paginate(20),
        ]);
    }

    public function create(Request $request): Response
    {
        $accountId = $request->user()->account_id;

        return Inertia::render('Broadcasts/Create', [
            'templates' => MessageTemplate::forAccount($accountId)
                ->where('status', 'APPROVED')
                ->orderBy('name')
                ->get(['id', 'name', 'language', 'body_text', 'header_type']),
            'tags' => Tag::forAccount($accountId)->orderBy('name')->get(['id', 'name', 'color']),
            'contactCount' => Contact::forAccount($accountId)->count(),
            'hasWhatsapp' => WhatsappConfig::forAccount($accountId)->where('status', 'connected')->exists(),
        ]);
    }

    public function store(Request $request, Creator $creator): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'template_name' => 'required|string',
            'template_language' => 'required|string|max:10',
            'template_variables' => 'nullable|array',
            'template_variables.*' => 'string|max:500',
            'header_media_url' => 'nullable|url|max:2048',
            'audience' => 'required|in:all,tags',
            'tag_ids' => 'required_if:audience,tags|array',
            'tag_ids.*' => 'uuid',
            'conv_status' => 'nullable|in:open,pending,closed',
            'last_message_days' => 'nullable|integer|min:1|max:365',
            'source' => 'nullable|in:ad',
            'scheduled_at' => 'nullable|date|after:now',
        ]);

        try {
            $broadcast = $creator->create($request->user()->account_id, $validated);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['audience' => $e->getMessage()]);
        }

        return redirect()->route('broadcasts.show', $broadcast)
            ->with('success', $broadcast->scheduled_at ? 'Broadcast programado.' : 'Broadcast en cola de envío.');
    }

    public function show(Request $request, Broadcast $broadcast): Response
    {
        abort_if($broadcast->account_id !== $request->user()->account_id, 403);

        return Inertia::render('Broadcasts/Show', [
            'broadcast' => $broadcast,
            'recipients' => $broadcast->recipients()
                ->with('contact:id,name,phone')
                ->orderBy('created_at')
                ->paginate(50),
        ]);
    }

    /**
     * Dashboard de métricas de broadcasts: tasas globales + embudo por
     * campaña + evolución por día con la tasa de respuesta. Acepta `?days=`
     * (7/15/30/90) para las ventanas de tiempo.
     */
    public function metrics(Request $request): Response
    {
        $accountId = $request->user()->account_id;

        $days = $this->windowDays($request);

        return Inertia::render('Broadcasts/Metrics', [
            ...$this->metricsData($accountId, $days),
            'days' => $days,
            'ranges' => [7, 15, 30, 90],
        ]);
    }

    /**
     * El mismo panel, en CSV: totales y tasas globales, el top por tasa de
     * respuesta y la evolución diaria. Misma ventana `?days=` y mismo
     * aislamiento por cuenta; stream con BOM UTF-8 y `;` (patrón de
     * `ContactController@exportCsv`).
     */
    public function exportMetricsCsv(Request $request): StreamedResponse
    {
        $accountId = $request->user()->account_id;

        $days = $this->windowDays($request);
        $data = $this->metricsData($accountId, $days);

        $filename = 'broadcasts-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['Totales globales'], ';');
            fputcsv($out, ['Campañas', 'Enviados', 'Entregados', 'Leídos', 'Respondidos', 'Fallos'], ';');
            fputcsv($out, [
                $data['totals']['broadcasts'],
                $data['totals']['sent'],
                $data['totals']['delivered'],
                $data['totals']['read'],
                $data['totals']['replied'],
                $data['totals']['failed'],
            ], ';');

            fputcsv($out, [], ';');
            fputcsv($out, ['Tasas globales (%)'], ';');
            fputcsv($out, ['Entrega', 'Lectura', 'Respuesta', 'Fallos'], ';');
            fputcsv($out, [
                $data['rates']['delivery'],
                $data['rates']['read'],
                $data['rates']['reply'],
                $data['rates']['failure'],
            ], ';');

            fputcsv($out, [], ';');
            fputcsv($out, ['Top 10 por tasa de respuesta'], ';');
            fputcsv($out, ['Campaña', 'Plantilla', 'Enviados', 'Entregados', 'Leídos', 'Respondidos', 'Tasa respuesta (%)'], ';');
            foreach ($data['topByReply'] as $b) {
                fputcsv($out, [
                    $b->name,
                    $b->template_name,
                    $b->sent_count,
                    $b->delivered_count,
                    $b->read_count,
                    $b->replied_count,
                    $b->reply_rate ?? 0,
                ], ';');
            }

            fputcsv($out, [], ';');
            fputcsv($out, ['Evolución diaria'], ';');
            fputcsv($out, ['Fecha', 'Enviados', 'Entregados', 'Leídos', 'Respondidos', 'Tasa respuesta (%)'], ';');
            foreach ($data['chart'] as $d) {
                fputcsv($out, [
                    $d['day'],
                    $d['sent'],
                    $d['delivered'],
                    $d['read'],
                    $d['replied'],
                    $d['reply_rate'] ?? '',
                ], ';');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{totals: array<string, int>, rates: array<string, float>, topByReply: mixed, funnels: array<int, mixed>, chart: \Illuminate\Support\Collection<int, array<string, mixed>>} */
    private function metricsData(string $accountId, int $days): array
    {
        $since = now()->subDays($days);

        // Totales agregados (sumas de counters de todos los broadcasts terminados)
        $totals = Broadcast::forAccount($accountId)
            ->whereIn('status', ['sent', 'sending'])
            ->selectRaw('
                COUNT(*) as broadcasts_count,
                SUM(sent_count) as total_sent,
                SUM(delivered_count) as total_delivered,
                SUM(read_count) as total_read,
                SUM(replied_count) as total_replied,
                SUM(failed_count) as total_failed
            ')
            ->first();

        $sent = (int) ($totals?->total_sent ?? 0);
        $rates = [
            'delivery' => $sent > 0 ? round(($totals->total_delivered / $sent) * 100, 1) : 0,
            'read' => $sent > 0 ? round(($totals->total_read / $sent) * 100, 1) : 0,
            'reply' => $sent > 0 ? round(($totals->total_replied / $sent) * 100, 1) : 0,
            'failure' => $sent > 0 ? round(($totals->total_failed / $sent) * 100, 1) : 0,
        ];

        // Top 10 broadcasts por tasa de respuesta, dentro de la ventana elegida
        $topByReply = Broadcast::forAccount($accountId)
            ->where('status', 'sent')
            ->where('sent_count', '>', 0)
            ->where('created_at', '>=', $since)
            ->selectRaw('id, name, template_name, sent_count, delivered_count, read_count, replied_count, created_at,
                ROUND((replied_count * 100.0) / NULLIF(sent_count, 0), 1) as reply_rate')
            ->orderByDesc('reply_rate')
            ->limit(10)
            ->get();

        // Embudo enviados → entregados → leídos → respondidos por campaña, para
        // que la pérdida entre pasos se lea de un vistazo (mismo orden que la
        // tabla y mismos colores que las barras de la página).
        $funnels = $topByReply->map(function (Broadcast $b) {
            return [
                'id' => $b->id,
                'name' => $b->name,
                'steps' => [
                    ['name' => 'Enviados', 'value' => (int) $b->sent_count, 'color' => '#3b82f6'],
                    ['name' => 'Entregados', 'value' => (int) $b->delivered_count, 'color' => '#10b981'],
                    ['name' => 'Leídos', 'value' => (int) $b->read_count, 'color' => '#8b5cf6'],
                    ['name' => 'Respondidos', 'value' => (int) $b->replied_count, 'color' => '#ec4899'],
                ],
            ];
        })->all();

        // Evolución diaria: cuántos mensajes por día y qué porcentaje de los
        // enviados llegó a responder (rate de respuesta diario).
        $daily = Broadcast::forAccount($accountId)
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as day, SUM(sent_count) as sent, SUM(delivered_count) as delivered,
                SUM(read_count) as read_count, SUM(replied_count) as replied_count')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $chart = collect(range($days - 1, 0))->map(function ($daysAgo) use ($daily) {
            $day = now()->subDays($daysAgo)->toDateString();
            $sent = (int) ($daily[$day]->sent ?? 0);

            return [
                'day' => $day,
                'label' => now()->subDays($daysAgo)->translatedFormat('d/m'),
                'sent' => $sent,
                'delivered' => (int) ($daily[$day]->delivered ?? 0),
                'read' => (int) ($daily[$day]->read_count ?? 0),
                'replied' => (int) ($daily[$day]->replied_count ?? 0),
                'reply_rate' => $sent > 0 ? round(((int) ($daily[$day]->replied_count ?? 0) / $sent) * 100, 1) : null,
            ];
        });

        return [
            'totals' => [
                'broadcasts' => (int) ($totals?->broadcasts_count ?? 0),
                'sent' => $sent,
                'delivered' => (int) ($totals?->total_delivered ?? 0),
                'read' => (int) ($totals?->total_read ?? 0),
                'replied' => (int) ($totals?->total_replied ?? 0),
                'failed' => (int) ($totals?->total_failed ?? 0),
            ],
            'rates' => $rates,
            'topByReply' => $topByReply,
            'funnels' => $funnels,
            'chart' => $chart,
        ];
    }

    private function windowDays(Request $request): int
    {
        $days = (int) $request->query('days', 30);

        return in_array($days, [7, 15, 30, 90], true) ? $days : 30;
    }

    public function destroy(Request $request, Broadcast $broadcast): RedirectResponse
    {
        abort_if($broadcast->account_id !== $request->user()->account_id, 403);

        // Solo borradores/programados: un envío en curso o terminado es histórico.
        if (! in_array($broadcast->status, ['draft', 'scheduled'], true)) {
            return back()->withErrors(['broadcast' => 'Solo se pueden eliminar broadcasts programados o en borrador.']);
        }

        $broadcast->delete();

        return redirect()->route('broadcasts.index')->with('success', 'Broadcast eliminado.');
    }
}
