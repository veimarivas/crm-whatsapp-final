<?php

namespace App\Http\Controllers;

use App\Models\AiConfig;
use App\Models\AiKnowledgeDocument;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Notification;
use App\Services\Ai\Chunker;
use App\Services\Ai\OfertaAcademica;
use App\Services\Ai\ReplyGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AiController extends Controller
{
    public function edit(Request $request): Response
    {
        $accountId = $request->user()->account_id;
        $config = AiConfig::forAccount($accountId)->first();

        return Inertia::render('Settings/Ai', [
            'config' => $config ? [
                'provider' => $config->provider,
                'model' => $config->model,
                'base_url' => $config->base_url,
                'system_prompt' => $config->system_prompt,
                'is_active' => $config->is_active,
                'auto_reply_enabled' => $config->auto_reply_enabled,
                'auto_reply_max_per_conversation' => $config->auto_reply_max_per_conversation,
                'auto_reply_cooldown_hours' => $config->auto_reply_cooldown_hours,
                'business_hours' => $config->business_hours,
                'after_hours_message' => $config->after_hours_message,
                'timezone' => $config->timezone,
                'has_key' => true,
                'has_embeddings_key' => $config->hasSemanticSearch(),
                // Estado en vivo del horario: leer una grilla de 7 días y
                // deducir si ahora mismo atiende es justo lo que nadie hace.
                'within_business_hours' => $config->isWithinBusinessHours(),
                'next_opening_at' => $config->nextOpeningAt()?->toIso8601String(),
                'knowledge_synced_at' => $config->knowledge_synced_at?->toIso8601String(),
            ] : null,
            'documents' => AiKnowledgeDocument::forAccount($accountId)
                ->withCount('chunks')
                ->orderByDesc('is_pinned')
                ->orderByDesc('updated_at')
                ->get(['id', 'title', 'is_pinned', 'updated_at']),
            // El catálogo vigente, para poder LEER lo que la IA está usando
            // como fuente. Sin esto, "la IA responde cualquier cosa" no se
            // puede diagnosticar: no hay forma de ver qué sabe.
            'catalog' => AiKnowledgeDocument::forAccount($accountId)
                ->where('is_pinned', true)
                ->orderBy('created_at')
                ->value('content'),
            'syncHours' => ['08:00', '14:00', '18:00'],
        ]);
    }

    /**
     * Refresca la oferta académica a pedido, sin esperar al próximo horario.
     *
     * Corre en primer plano porque quien aprieta el botón quiere ver el
     * resultado ahora; con pocas decenas de programas tarda un par de
     * segundos. Si la BD académica no responde, el comando conserva el
     * conocimiento anterior y avisa.
     */
    public function syncKnowledge(Request $request): RedirectResponse
    {
        $accountId = $request->user()->account_id;

        $code = Artisan::call('wacrm:sync-oferta-academica', ['--account' => $accountId]);

        if ($code !== 0) {
            return back()->with('error', 'No se pudo leer la base académica. Se conservó el conocimiento anterior.');
        }

        $count = AiKnowledgeDocument::forAccount($accountId)
            ->where('title', 'like', OfertaAcademica::DOC_PREFIX.'%')
            ->where('is_pinned', false)
            ->count();

        return back()->with('success', "Conocimiento actualizado: {$count} programas en inscripciones.");
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'provider' => ['required', Rule::in(['openai', 'anthropic', 'ollama', 'groq', 'gemini', 'openrouter'])],
            'model' => 'required|string|max:100',
            'base_url' => 'nullable|string|max:255|url',
            'api_key' => 'nullable|string',
            'embeddings_api_key' => 'nullable|string',
            'system_prompt' => 'nullable|string|max:4000',
            'is_active' => 'boolean',
            'auto_reply_enabled' => 'boolean',
            'auto_reply_max_per_conversation' => 'integer|between:1,20',
            // Máximo 24 h: más que eso equivale a apagar la IA, y para eso ya
            // está el toggle por conversación.
            'auto_reply_cooldown_hours' => 'integer|between:1,24',
            'business_hours' => 'nullable|array',
            'after_hours_message' => 'nullable|string|max:1024',
            'timezone' => 'nullable|string|max:60',
        ]);

        $accountId = $request->user()->account_id;
        $existing = AiConfig::forAccount($accountId)->first();

        // Ollama corre local y no requiere clave; los demás sí.
        if ($validated['provider'] !== 'ollama' && ! $existing && empty($validated['api_key'])) {
            return back()->withErrors(['api_key' => 'La API key es obligatoria.']);
        }

        if ($validated['provider'] === 'ollama' && empty($validated['base_url'])) {
            $validated['base_url'] = 'http://127.0.0.1:11434';
        }

        if (empty($validated['api_key'])) {
            unset($validated['api_key']); // vacío = conservar la actual
        }

        if (empty($validated['embeddings_api_key'])) {
            unset($validated['embeddings_api_key']); // vacío = conservar la actual
        }

        AiConfig::updateOrCreate(
            ['account_id' => $accountId],
            [...$validated, 'created_by' => $request->user()->id],
        );

        return back()->with('success', 'Configuración de IA guardada.');
    }

    /** Borrador para el inbox: no envía nada, devuelve el texto sugerido. */
    public function draft(Request $request, ReplyGenerator $generator): JsonResponse
    {
        $validated = $request->validate(['conversation_id' => 'required|uuid']);

        $conversation = Conversation::forAccount($request->user()->account_id)
            ->findOrFail($validated['conversation_id']);

        $config = AiConfig::forAccount($request->user()->account_id)
            ->where('is_active', true)
            ->first();

        if (! $config) {
            return response()->json(['message' => 'Configura la IA en Ajustes primero.'], 422);
        }

        try {
            return response()->json(['draft' => $generator->generate($config, $conversation)]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    public function storeDocument(Request $request, Chunker $chunker): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string|max:100000',
        ]);

        $document = AiKnowledgeDocument::create([
            ...$validated,
            'account_id' => $request->user()->account_id,
            'created_by' => $request->user()->id,
        ]);

        $chunks = $chunker->reindex($document);

        return back()->with('success', "Documento guardado ({$chunks} fragmentos indexados).");
    }

    /** Re-trocea y re-vectoriza toda la base de conocimiento. */
    public function reindex(Request $request, Chunker $chunker): RedirectResponse
    {
        $documents = AiKnowledgeDocument::forAccount($request->user()->account_id)->get();

        $total = 0;
        foreach ($documents as $document) {
            $total += $chunker->reindex($document);
        }

        return back()->with('success', "Reindexados {$documents->count()} documentos ({$total} fragmentos).");
    }

    /**
     * Analytics de tiempo de respuesta: cada mensaje de cliente seguido del
     * primer mensaje saliente (agente o IA) de la misma conversación.
     *
     * Centro de analítica del panel: acepta `?days=` (7/15/30/90), y devuelve
     * histograma de espera, mediana diaria y comparativa por agente, además de
     * deltas contra la ventana anterior para que los KPIs tengan contexto.
     *
     * Definición de una «respuesta» aquí: el primer saliente posterior cuenta,
     * sea agente o IA. Es el mismo criterio de antes de esta ronda (la IA
     * cuenta como respuesta); en /supervision NO se cuenta porque ese panel
     * mide la espera humana.
     */
    public function responseTime(Request $request): Response
    {
        $accountId = $request->user()->account_id;

        $days = (int) $request->query('days', 30);
        if (! in_array($days, [7, 15, 30, 90], true)) {
            $days = 30;
        }

        $current = $this->responseRows($accountId, now()->subDays($days));
        $previous = $this->responseRows(
            $accountId,
            now()->subDays($days * 2),
            now()->subDays($days),
        );

        // Comparativo por agente/bot: la mediana es el número que no distorsiona
        // el promedio (una demora larga pesa igual que diez).
        $byAgent = $current
            ->groupBy(fn ($r) => $r->sender_id ?? 'bot')
            ->map(function ($group) {
                $first = $group->first();
                $diffs = $this->sortedDiffs($group);
                $avg = $diffs->count() > 0 ? (int) round($diffs->avg()) : 0;
                $median = $diffs->count() > 0 ? (int) $diffs[(int) floor($diffs->count() / 2)] : 0;

                return [
                    'name' => $first->sender_type === 'bot' ? '✨ IA' : ($first->agent_name ?? 'Agente eliminado'),
                    'is_bot' => $first->sender_type === 'bot',
                    'count' => $group->count(),
                    'avg_seconds' => $avg,
                    'median_seconds' => $median,
                    'avg_label' => $this->formatDuration($avg),
                    'median_label' => $this->formatDuration($median),
                ];
            })
            ->sortBy('median_seconds')
            ->values();

        [$histogram, $daily] = $this->analytics($current, $days);

        return Inertia::render('Settings/ResponseTime', [
            'byAgent' => $byAgent,
            'histogram' => $histogram,
            'daily' => $daily,
            'kpis' => $this->kpis($current),
            'deltas' => $this->deltas($current, $previous),
            'days' => $days,
            'ranges' => [7, 15, 30, 90],
        ]);
    }

    /**
     * Diferencias entre cada mensaje de cliente y el primer saliente posterior
     * en la misma conversación — agente o IA. $to corta la ventana anterior
     * cuando se compara contra el período previo.
     *
     * MariaDB no soporta JOIN ... LATERAL (la versión origin de esta página
     * sí lo usaba y por eso solo corría en MySQL 8): se resuelve igual con
     * un NOT EXISTS que descarta cualquier saliente intermedio, que es la
     * misma definición (el primer saliente posterior) sobre el mismo SGBD.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function responseRows(string $accountId, Carbon $from, ?Carbon $to = null): Collection
    {
        $rows = DB::select("
            SELECT
                rep.sender_id,
                rep.sender_type,
                DATE_FORMAT(rep.created_at, '%Y-%m-%d') as replied_date,
                u.name as agent_name,
                TIMESTAMPDIFF(SECOND, cust.created_at, rep.created_at) as diff_seconds
            FROM messages cust
            JOIN conversations c ON c.id = cust.conversation_id
            JOIN messages rep ON rep.conversation_id = cust.conversation_id
                AND rep.sender_type IN ('agent', 'bot')
                AND rep.created_at > cust.created_at
            LEFT JOIN users u ON u.id = rep.sender_id
            WHERE cust.sender_type = 'customer'
              AND cust.created_at >= ?
              AND cust.created_at < ?
              AND c.account_id = ?
              AND NOT EXISTS (
                  SELECT 1
                  FROM messages m3
                  WHERE m3.conversation_id = cust.conversation_id
                    AND m3.sender_type IN ('agent', 'bot')
                    AND m3.created_at > cust.created_at
                    AND m3.created_at < rep.created_at
              )
        ", [$from, $to ?? now(), $accountId]);

        return collect($rows);
    }

    /** @return \Illuminate\Support\Collection<int, int> */
    private function sortedDiffs(Collection $rows): Collection
    {
        return $rows->pluck('diff_seconds')
            ->map(fn ($d) => (int) $d)
            ->sort()
            ->values();
    }

    /**
     * Histograma de espera y mediana por día para la ventana actual. Dígitos
     * de los mismos baldes que el panel de Seguimiento, para que «menos de 5 m»
     * signifique lo mismo en los dos paneles.
     *
     * @return array{array<int, array{label: string, count: int}>, array<int, array<string, mixed>>}
     */
    private function analytics(Collection $rows, int $days): array
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
        foreach ($rows as $r) {
            $s = (int) $r->diff_seconds;
            foreach ($buckets as $i => $b) {
                if (($b['min'] === null || $s >= $b['min']) && ($b['max'] === null || $s < $b['max'])) {
                    $counts[$i]++;
                    break;
                }
            }
        }

        $histogram = collect($buckets)
            ->map(fn (array $b, int $i) => ['label' => $b['label'], 'count' => $counts[$i]])
            ->values()
            ->all();

        // Serie diaria de la mediana, rellenando los días sin respuestas con
        // null (un hueco es un hueco, no un 0 que mienta). Empieza el día
        // «previo al de hoy» para que el eje tenga exactamente `days` puntos.
        $byDay = $rows->groupBy(fn ($r) => $r->replied_date ?? 'sin-fecha');

        $daily = [];
        $cursor = now()->subDays($days - 1)->startOfDay();
        while ($cursor <= now()->startOfDay()) {
            $key = $cursor->format('Y-m-d');
            $diffs = $this->sortedDiffs(collect($byDay->get($key, [])));
            $daily[] = [
                'date' => $key,
                'label' => $cursor->translatedFormat('j M'),
                'median_seconds' => $diffs->count() > 0 ? (int) $diffs[(int) floor($diffs->count() / 2)] : null,
                'avg_seconds' => $diffs->count() > 0 ? (int) round($diffs->avg()) : null,
                'count' => $diffs->count(),
            ];
            $cursor = $cursor->addDay();
        }

        return [$histogram, $daily];
    }

    /** @return array{avg_seconds: int, avg_label: string, median_seconds: int, median_label: string, total_replies: int} */
    private function kpis(Collection $rows): array
    {
        $diffs = $this->sortedDiffs($rows);
        $avg = $diffs->count() > 0 ? (int) round($diffs->avg()) : 0;
        $median = $diffs->count() > 0 ? (int) $diffs[(int) floor($diffs->count() / 2)] : 0;

        return [
            'avg_seconds' => $avg,
            'avg_label' => $this->formatDuration($avg),
            'median_seconds' => $median,
            'median_label' => $this->formatDuration($median),
            'total_replies' => $diffs->count(),
        ];
    }

    /**
     * % de cambio contra la ventana anterior (misma longitud). Null cuando no
     * hay con qué comparar. Negativo en tiempo = más rápido (bien); en total
     * de respuestas un negativo es menos actividad (mal) — el color lo pone la
     * vista.
     *
     * @return array{median_pct: ?float, avg_pct: ?float, total_pct: ?float, prev_total: int}
     */
    private function deltas(Collection $current, Collection $previous): array
    {
        $now = $this->sortedDiffs($current);
        $then = $this->sortedDiffs($previous);

        $measures = fn (Collection $c) => [
            'avg' => $c->count() > 0 ? (int) round($c->avg()) : null,
            'median' => $c->count() > 0 ? (int) $c[(int) floor($c->count() / 2)] : null,
        ];

        $pctChange = fn (?int $now, ?int $then) => ($now !== null && $then !== null && $then > 0)
            ? (($now - $then) / $then) * 100 : null;

        $cv = $measures($now);
        $pv = $measures($then);

        return [
            'median_pct' => $pctChange($cv['median'], $pv['median']),
            'avg_pct' => $pctChange($cv['avg'], $pv['avg']),
            'total_pct' => $previous->count() > 0
                ? (($current->count() - $previous->count()) / $previous->count()) * 100 : null,
            'prev_total' => $previous->count(),
        ];
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        if ($seconds < 3600) {
            return floor($seconds / 60).'m '.($seconds % 60).'s';
        }

        return floor($seconds / 3600).'h '.floor(($seconds % 3600) / 60).'m';
    }

    /**
     * Página de estadísticas de la IA: contadores, tasa de éxito y las
     * últimas preguntas de clientes (para saber qué agregar al knowledge base).
     */
    public function stats(Request $request): Response
    {
        $accountId = $request->user()->account_id;

        // Contadores de respuestas de la IA (sender_type='bot').
        $botRepliesLast7d = Message::whereHas('conversation', fn ($q) => $q->where('account_id', $accountId))
            ->where('sender_type', 'bot')
            ->where('messages.created_at', '>=', now()->subDays(7))
            ->count();
        $botRepliesLast30d = Message::whereHas('conversation', fn ($q) => $q->where('account_id', $accountId))
            ->where('sender_type', 'bot')
            ->where('messages.created_at', '>=', now()->subDays(30))
            ->count();
        $botRepliesTotal = Message::whereHas('conversation', fn ($q) => $q->where('account_id', $accountId))
            ->where('sender_type', 'bot')
            ->count();

        // Fallbacks (notificaciones tipo ai_fallback) — la IA no pudo responder.
        $fallbacksLast30d = Notification::where('account_id', $accountId)
            ->where('type', 'ai_fallback')
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        // Tasa de éxito (respuestas bot / (respuestas bot + fallbacks) últimos 30d)
        $successRate = ($botRepliesLast30d + $fallbacksLast30d) > 0
            ? round($botRepliesLast30d / ($botRepliesLast30d + $fallbacksLast30d) * 100, 1)
            : 100;

        // Serie diaria (últimos 14 días): respuestas de IA + fallbacks, con la
        // tasa de éxito diaria para que la línea de la derecha tenga sentido.
        $since = now()->subDays(13)->startOfDay();

        $dailyBot = Message::whereHas('conversation', fn ($q) => $q->where('account_id', $accountId))
            ->where('sender_type', 'bot')
            ->where('messages.created_at', '>=', $since)
            ->selectRaw('DATE(messages.created_at) as day, COUNT(*) as count')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $dailyFallback = Notification::where('account_id', $accountId)
            ->where('type', 'ai_fallback')
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as count')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $chart = collect(range(13, 0))->map(function ($daysAgo) use ($dailyBot, $dailyFallback) {
            $day = now()->subDays($daysAgo)->toDateString();
            $bot = (int) ($dailyBot[$day]->count ?? 0);
            $fallback = (int) ($dailyFallback[$day]->count ?? 0);

            return [
                'day' => $day,
                'label' => now()->subDays($daysAgo)->translatedFormat('D d/m'),
                'ai_replies' => $bot,
                'fallbacks' => $fallback,
                'success_rate' => $bot + $fallback > 0 ? round($bot / ($bot + $fallback) * 100, 1) : null,
            ];
        });

        // Deltas de los KPIs contra la ventana anterior: qué pasó respecto al
        // período previo, no solo el número en sí.
        $prevBot7d = $this->countBotRepliesBetween($accountId, now()->subDays(14), now()->subDays(7));
        $prevBot30d = $this->countBotRepliesBetween($accountId, now()->subDays(60), now()->subDays(30));
        $prevFallback30d = Notification::where('account_id', $accountId)
            ->where('type', 'ai_fallback')
            ->whereBetween('created_at', [now()->subDays(60), now()->subDays(30)])
            ->count();
        $prevSuccess30d = ($prevBot30d + $prevFallback30d) > 0
            ? round($prevBot30d / ($prevBot30d + $prevFallback30d) * 100, 1)
            : null;

        $pctChange = fn (int $now, int $then) => $then > 0 ? (($now - $then) / $then) * 100 : null;

        $deltas = [
            'replies_7d_pct' => $pctChange($botRepliesLast7d, $prevBot7d),
            'replies_30d_pct' => $pctChange($botRepliesLast30d, $prevBot30d),
            'success_pp' => $prevSuccess30d !== null ? round($successRate - $prevSuccess30d, 1) : null,
        ];

        // Últimas preguntas del cliente (para ver qué le preguntan a la IA
        // y saber qué agregar al knowledge base). Solo msgs de customer con IA activa.
        $recentQuestions = Message::whereHas('conversation', fn ($q) => $q
            ->where('account_id', $accountId)
            ->where('ai_autoreply_disabled', false))
            ->where('sender_type', 'customer')
            ->whereNotNull('content_text')
            ->with('conversation.contact:id,name,phone')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get(['id', 'conversation_id', 'content_text', 'created_at']);

        return Inertia::render('Settings/AiStats', [
            'stats' => [
                'replies_7d' => $botRepliesLast7d,
                'replies_30d' => $botRepliesLast30d,
                'replies_total' => $botRepliesTotal,
                'fallbacks_30d' => $fallbacksLast30d,
                'success_rate' => $successRate,
            ],
            'chart' => $chart,
            'deltas' => $deltas,
            'recentQuestions' => $recentQuestions,
        ]);
    }

    /** Respuestas de la IA (sender_type='bot') dentro de una ventana. */
    private function countBotRepliesBetween(string $accountId, Carbon $from, Carbon $to): int
    {
        return Message::whereHas('conversation', fn ($q) => $q->where('account_id', $accountId))
            ->where('sender_type', 'bot')
            ->whereBetween('messages.created_at', [$from, $to])
            ->count();
    }

    public function destroyDocument(Request $request, AiKnowledgeDocument $document): RedirectResponse
    {
        abort_if($document->account_id !== $request->user()->account_id, 403);

        $document->delete();

        return back()->with('success', 'Documento eliminado.');
    }
}
