<?php

namespace App\Http\Controllers;

use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\MessageTemplate;
use App\Models\Tag;
use App\Models\WhatsappConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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

    public function store(Request $request, \App\Services\Broadcasts\Creator $creator): RedirectResponse
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
     * Dashboard de métricas de broadcasts: tasas globales + top campañas +
     * evolución de últimos 30 días. Útil para el equipo de marketing.
     */
    public function metrics(Request $request): Response
    {
        $accountId = $request->user()->account_id;

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

        // Top 10 broadcasts por tasa de respuesta (los que más engagement generan)
        $topByReply = Broadcast::forAccount($accountId)
            ->where('status', 'sent')
            ->where('sent_count', '>', 0)
            ->selectRaw('id, name, template_name, sent_count, delivered_count, read_count, replied_count, created_at,
                ROUND((replied_count * 100.0) / NULLIF(sent_count, 0), 1) as reply_rate')
            ->orderByDesc('reply_rate')
            ->limit(10)
            ->get();

        // Evolución últimos 30 días: cuántos mensajes enviados por día
        $daily = Broadcast::forAccount($accountId)
            ->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw('DATE(created_at) as day, SUM(sent_count) as sent, SUM(delivered_count) as delivered, SUM(read_count) as read_count')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $chart = collect(range(29, 0))->map(function ($daysAgo) use ($daily) {
            $day = now()->subDays($daysAgo)->toDateString();
            return [
                'day' => $day,
                'label' => now()->subDays($daysAgo)->translatedFormat('d/m'),
                'sent' => (int) ($daily[$day]->sent ?? 0),
                'delivered' => (int) ($daily[$day]->delivered ?? 0),
                'read' => (int) ($daily[$day]->read_count ?? 0),
            ];
        });

        return Inertia::render('Broadcasts/Metrics', [
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
            'chart' => $chart,
        ]);
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
