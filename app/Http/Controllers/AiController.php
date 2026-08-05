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
            'provider' => ['required', Rule::in(['openai', 'anthropic', 'ollama', 'groq', 'gemini'])],
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
     * Analytics de tiempo de respuesta: para cada mensaje de cliente en los
     * últimos 30 días busca el siguiente mensaje del agente/bot en la misma
     * conversación y calcula la diferencia. Agrupa por agente (sender_id) y
     * saca el promedio en segundos.
     */
    public function responseTime(Request $request): Response
    {
        $accountId = $request->user()->account_id;

        // Subquery correlacionada: para cada msg de cliente, obtener el próximo
        // msg agente/bot en la misma conv. Solo mensajes de los últimos 30 días.
        $rows = DB::select("
            SELECT
                reply.sender_id,
                reply.sender_type,
                u.name as agent_name,
                TIMESTAMPDIFF(SECOND, cust.created_at, reply.created_at) as diff_seconds
            FROM messages cust
            JOIN conversations c ON c.id = cust.conversation_id
            JOIN LATERAL (
                SELECT m2.sender_id, m2.sender_type, m2.created_at
                FROM messages m2
                WHERE m2.conversation_id = cust.conversation_id
                  AND m2.created_at > cust.created_at
                  AND m2.sender_type IN ('agent', 'bot')
                ORDER BY m2.created_at ASC
                LIMIT 1
            ) reply ON true
            LEFT JOIN users u ON u.id = reply.sender_id
            WHERE cust.sender_type = 'customer'
              AND cust.created_at >= ?
              AND c.account_id = ?
        ", [now()->subDays(30), $accountId]);

        // Agrupar en PHP por agente
        $byAgent = collect($rows)
            ->groupBy(fn ($r) => $r->sender_id ?? 'bot')
            ->map(function ($group) {
                $first = $group->first();
                $diffs = $group->pluck('diff_seconds')->sort()->values();
                $avg = round($diffs->avg());
                $median = $diffs->count() > 0 ? (int) $diffs[intval($diffs->count() / 2)] : 0;

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
            ->sortBy('avg_seconds')
            ->values();

        $overall = collect($rows)->avg('diff_seconds');
        $overallLabel = $this->formatDuration((int) round($overall ?? 0));

        return Inertia::render('Settings/ResponseTime', [
            'byAgent' => $byAgent,
            'overallLabel' => $overallLabel,
            'totalReplies' => count($rows),
        ]);
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

        // Serie diaria últimos 14 días: cuántas respuestas IA por día
        $daily = Message::whereHas('conversation', fn ($q) => $q->where('account_id', $accountId))
            ->where('sender_type', 'bot')
            ->where('messages.created_at', '>=', now()->subDays(13)->startOfDay())
            ->selectRaw('DATE(messages.created_at) as day, COUNT(*) as count')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $chart = collect(range(13, 0))->map(function ($daysAgo) use ($daily) {
            $day = now()->subDays($daysAgo)->toDateString();

            return [
                'day' => $day,
                'label' => now()->subDays($daysAgo)->translatedFormat('D d/m'),
                'count' => (int) ($daily[$day]->count ?? 0),
            ];
        });

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
            'recentQuestions' => $recentQuestions,
        ]);
    }

    public function destroyDocument(Request $request, AiKnowledgeDocument $document): RedirectResponse
    {
        abort_if($document->account_id !== $request->user()->account_id, 403);

        $document->delete();

        return back()->with('success', 'Documento eliminado.');
    }
}
