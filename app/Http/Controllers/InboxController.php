<?php

namespace App\Http\Controllers;

use App\Models\AiConfig;
use App\Models\ContactNote;
use App\Models\Conversation;
use App\Models\Deal;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\Notification;
use App\Models\User;
use App\Models\WhatsappConfig;
use App\Services\Ai\ReplyGenerator;
use App\Services\Flows\Runner;
use App\Services\Webhooks\Dispatcher;
use App\Services\WhatsApp\Messenger;
use App\Services\WhatsApp\MetaApi;
use App\Services\WhatsApp\ServiceWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class InboxController extends Controller
{
    public function index(Request $request): Response
    {
        $accountId = $request->user()->account_id;

        return Inertia::render('Inbox/Index', [
            'hasWhatsappConfig' => WhatsappConfig::forAccount($accountId)
                ->where('status', 'connected')
                ->exists(),
            'hasAi' => AiConfig::forAccount($accountId)->where('is_active', true)->exists(),
            'members' => User::where('account_id', $accountId)->get(['id', 'name']),
        ]);
    }

    /**
     * Búsqueda global: encuentra mensajes que contengan las palabras del query
     * (FULLTEXT en modo booleano con comodín) y devuelve las conversaciones
     * a las que pertenecen + un snippet resaltando el match. Respeta la
     * restricción por rol (agent solo ve las conversaciones asignadas).
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 3) {
            return response()->json([]);
        }

        $user = $request->user();

        // Término booleano con comodín — tolera prefijos, ignora <3 letras
        $terms = collect(preg_split('/\s+/', $q))
            ->filter(fn ($t) => mb_strlen($t) >= 3)
            ->map(fn ($t) => $t.'*')
            ->join(' ');

        if ($terms === '') {
            return response()->json([]);
        }

        // Trae los últimos 40 mensajes que matchean + su conversación
        $matches = Message::query()
            ->whereHas('conversation', fn ($qq) => $qq
                ->where('account_id', $user->account_id)
                ->when(! $user->hasRoleAtLeast(User::ROLE_ADMIN),
                    fn ($x) => $x->where('assigned_agent_id', $user->id)))
            ->whereRaw('MATCH(content_text) AGAINST(? IN BOOLEAN MODE)', [$terms])
            ->with(['conversation.contact:id,name,phone,avatar_url', 'conversation.assignedAgent:id,name'])
            ->orderByDesc('created_at')
            ->limit(40)
            ->get(['id', 'conversation_id', 'content_text', 'sender_type', 'created_at']);

        // Agrupa por conversación (varios matches en una conv aparecen una vez con el más reciente)
        $grouped = $matches->groupBy('conversation_id')->map(function ($msgs) use ($q) {
            $latest = $msgs->first();
            $snippet = mb_substr(str_ireplace(explode(' ', $q), array_map(fn ($w) => "«{$w}»", explode(' ', $q)), $latest->content_text ?? ''), 0, 160);

            return [
                'conversation' => $latest->conversation,
                'snippet' => $snippet,
                'match_count' => $msgs->count(),
                'match_at' => $latest->created_at,
            ];
        })->values();

        return response()->json($grouped);
    }

    /** Lista de conversaciones (JSON, la UI hace polling). */
    public function conversations(Request $request): JsonResponse
    {
        $user = $request->user();

        $conversations = Conversation::forAccount($user->account_id)
            ->with(['contact:id,name,phone,avatar_url', 'assignedAgent:id,name'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            // Filtro de canal server-side (T0.5). Va acá y no en el cliente:
            // el listado se corta en 100, así que filtrar en pantalla mostraría
            // «3 de Telegram» cuando en realidad hay treinta más sin traer.
            ->when($request->query('channel'), fn ($q, $channel) => $q->where('channel', $channel))
            // Restricción por rol: agent/viewer solo ven las conversaciones
            // asignadas a ellos. admin/owner ven todo.
            ->when(! $user->hasRoleAtLeast(User::ROLE_ADMIN),
                fn ($q) => $q->where('assigned_agent_id', $user->id),
            )
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();

        // Ventana de servicio: cuánto queda para escribir sin que Meta cobre.
        // En lote — una query para las 100, no dos por cada una.
        $windows = app(ServiceWindow::class)
            ->forMany($conversations->pluck('id')->all());

        $conversations->each(function (Conversation $c) use ($windows) {
            $c->setAttribute('service_window', $windows[$c->id] ?? null);
        });

        return response()->json($conversations);
    }

    /** Mensajes de una conversación (JSON, la UI hace polling). */
    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);

        $messages = $conversation->messages()
            ->with([
                'reactions:id,message_id,emoji,actor_type',
                'replyTo:id,content_text,content_type,sender_type',
                'sender:id,name,account_role',
            ])
            ->orderBy('created_at')
            ->limit(500)
            ->get();

        return response()->json($messages);
    }

    /** Envía un mensaje de texto y lo persiste. */
    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);

        $validated = $request->validate([
            'text' => 'required|string|max:4096',
            'reply_to_message_id' => 'nullable|uuid',
        ]);

        // Mensaje citado: debe ser de esta misma conversación.
        $replyTo = null;
        if ($validated['reply_to_message_id'] ?? null) {
            $replyTo = $conversation->messages()->find($validated['reply_to_message_id']);
        }

        $config = WhatsappConfig::forAccount($request->user()->account_id)
            ->where('status', 'connected')
            ->firstOrFail();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => Message::SENDER_AGENT,
            'sender_id' => $request->user()->id,
            'content_type' => 'text',
            'content_text' => $validated['text'],
            'reply_to_message_id' => $replyTo?->id,
            'status' => 'sending',
        ]);

        try {
            $result = MetaApi::for($config)->sendText(
                $conversation->contact->phone_normalized ?? $conversation->contact->phone,
                $validated['text'],
                $replyTo?->message_id,
            );

            $message->update([
                'message_id' => $result['messages'][0]['id'] ?? null,
                'status' => 'sent',
            ]);
        } catch (\RuntimeException $e) {
            $message->update(['status' => 'failed']);

            return response()->json(['message' => $e->getMessage()], 502);
        }

        $conversation->update([
            'last_message_text' => $validated['text'],
            'last_message_at' => now(),
        ]);

        // Handoff del flow (chatbot cede el control), pero el modo IA/Humano
        // ahora lo controla el agente con el toggle explícito del header.
        app(Runner::class)->pauseForConversation($conversation);

        // Notifica al Komo (y otras integraciones suscritas) que salió un mensaje.
        try {
            app(Dispatcher::class)->dispatch($conversation->account_id, 'message.sent', [
                'conversation_id' => $conversation->id,
                'contact' => $conversation->contact->only(['id', 'phone', 'name', 'email', 'company']),
                'message' => [
                    'id' => $message->id,
                    'type' => 'text',
                    'text' => $validated['text'],
                    'wamid' => $message->message_id,
                    'sender_type' => Message::SENDER_AGENT,
                    'sender_name' => $request->user()->name,
                    'sender_role' => $request->user()->account_role,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Webhook message.sent falló', ['error' => $e->getMessage()]);
        }

        return response()->json($message->fresh());
    }

    /** Cambia el modo de la conversación: IA (auto-respuesta) o Humano. */
    public function setAiMode(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);

        $validated = $request->validate(['ai_enabled' => 'required|boolean']);

        // `setAiEnabled` reinicia contador y pausa al encender, deja escrito el
        // motivo al apagar y avisa a Komo para que su toggle no quede mostrando
        // lo contrario de lo que pasa acá.
        $conversation->setAiEnabled(
            $validated['ai_enabled'],
            'Apagada a mano por '.($request->user()->name ?? 'un agente').' desde el Inbox',
        );

        if ($validated['ai_enabled']) {
            $conversation->update(['ai_limit_notified_at' => null]);
        }

        // Si se levantó una pausa a mano, el CRM externo tiene que borrar su
        // aviso: si no, Komo seguiría mostrando "en pausa hasta las HH:MM".
        if ($validated['ai_enabled']) {
            try {
                app(Dispatcher::class)->dispatch($conversation->account_id, 'ai.resumed', [
                    'conversation_id' => $conversation->id,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Webhook ai.resumed falló', ['error' => $e->getMessage()]);
            }
        }

        return response()->json($conversation->fresh());
    }

    /** Marca la conversación como leída. */
    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);

        $conversation->update(['unread_count' => 0]);

        return response()->json(['ok' => true]);
    }

    /** Reacciona con un emoji a un mensaje (o la quita con emoji vacío). */
    public function react(Request $request, Message $message): JsonResponse
    {
        $conversation = $message->conversation;
        $this->authorizeConversation($request, $conversation);

        $validated = $request->validate(['emoji' => 'nullable|string|max:16']);
        $emoji = $validated['emoji'] ?? '';

        if (! $message->message_id) {
            return response()->json(['message' => 'Este mensaje no se puede reaccionar (sin wamid).'], 422);
        }

        $config = WhatsappConfig::forAccount($conversation->account_id)
            ->where('status', 'connected')
            ->firstOrFail();

        try {
            MetaApi::for($config)->sendReaction(
                $conversation->contact->phone_normalized ?? $conversation->contact->phone,
                $message->message_id,
                $emoji, // vacío = retirar la reacción (contrato de Meta)
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        if ($emoji === '') {
            $message->reactions()
                ->where('actor_type', 'agent')
                ->where('actor_id', $request->user()->id)
                ->delete();
        } else {
            MessageReaction::updateOrCreate(
                ['message_id' => $message->id, 'actor_type' => 'agent', 'actor_id' => $request->user()->id],
                ['conversation_id' => $conversation->id, 'emoji' => $emoji],
            );
        }

        return response()->json(['ok' => true]);
    }

    /** Notas internas del contacto de la conversación. */
    public function notes(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);

        return response()->json(
            ContactNote::where('contact_id', $conversation->contact_id)
                ->with('author:id,name')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(),
        );
    }

    public function addNote(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);

        $validated = $request->validate([
            'text' => 'nullable|string|max:2000',
            'audio' => 'nullable|file|mimetypes:audio/ogg,audio/mpeg,audio/webm,audio/wav|max:5120',
        ]);

        if (empty($validated['text']) && ! $request->hasFile('audio')) {
            return response()->json(['message' => 'Debe incluir texto o audio.'], 422);
        }

        $audioPath = null;
        if ($request->hasFile('audio')) {
            $audioPath = $request->file('audio')->store('voice-notes', 'public');
        }

        $note = ContactNote::create([
            'account_id' => $conversation->account_id,
            'contact_id' => $conversation->contact_id,
            'user_id' => $request->user()->id,
            'note_text' => $validated['text'] ?? '',
            'audio_path' => $audioPath,
        ]);

        return response()->json($note->load('author:id,name'), 201);
    }

    /** Envía un archivo (imagen, video, audio o documento). */
    public function sendMedia(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);

        $validated = $request->validate([
            'file' => 'required|file|max:16384', // 16 MB (límite de Meta para la mayoría de tipos)
            'caption' => 'nullable|string|max:1024',
        ]);

        $file = $validated['file'];

        try {
            $message = app(Messenger::class)->sendMedia(
                $conversation,
                $file->get(),
                $file->getMimeType() ?? 'application/octet-stream',
                $file->getClientOriginalName(),
                $validated['caption'] ?? null,
                Message::SENDER_AGENT,
                $request->user()->id,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        app(Runner::class)->pauseForConversation($conversation);

        return response()->json($message);
    }

    /** Asigna la conversación a un agente y le notifica. */
    public function assign(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);

        $validated = $request->validate(['agent_id' => 'nullable|uuid']);
        $agentId = $validated['agent_id'] ?? null;

        if ($agentId !== null) {
            $isMember = User::where('id', $agentId)
                ->where('account_id', $request->user()->account_id)
                ->exists();
            abort_unless($isMember, 422);
        }

        $conversation->update(['assigned_agent_id' => $agentId]);

        // El deal de la conversación espeja la asignación (columna de /pipelines).
        Deal::where('conversation_id', $conversation->id)->update(['assigned_to' => $agentId]);

        if ($agentId && $agentId !== $request->user()->id) {
            Notification::create([
                'account_id' => $conversation->account_id,
                'user_id' => $agentId,
                'type' => 'conversation_assigned',
                'conversation_id' => $conversation->id,
                'contact_id' => $conversation->contact_id,
                'actor_user_id' => $request->user()->id,
                'title' => 'Conversación asignada',
                'body' => 'Te asignaron la conversación con '.
                    ($conversation->contact->name ?? $conversation->contact->phone),
            ]);
        }

        return response()->json($conversation->fresh()->load('assignedAgent:id,name'));
    }

    /** Borrador IA para el cuadro de respuesta. */
    public function aiDraft(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);

        $request->merge(['conversation_id' => $conversation->id]);

        return app(AiController::class)->draft($request, app(ReplyGenerator::class));
    }

    /** Cambia el estado (open / pending / closed). */
    public function updateStatus(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);

        $validated = $request->validate(['status' => 'required|in:open,pending,closed']);

        $conversation->update(['status' => $validated['status']]);

        return response()->json($conversation->fresh());
    }

    /**
     * Aplica una acción a múltiples conversaciones a la vez. Ideal para
     * limpiar el inbox después de una campaña grande (cerrar todo, asignar
     * un batch a un agente, marcar leído).
     *
     * Solo admin/owner puede hacer bulk actions (los agents solo tienen
     * acceso a sus propias conversaciones, no tiene sentido bulk allí).
     */
    public function bulkAction(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRoleAtLeast(User::ROLE_ADMIN), 403);

        $validated = $request->validate([
            'conversation_ids' => 'required|array|min:1|max:200',
            'conversation_ids.*' => 'uuid',
            'action' => 'required|in:close,open,pending,mark_read,assign',
            'agent_id' => 'nullable|uuid', // solo para action=assign
        ]);

        $accountId = $request->user()->account_id;

        $query = Conversation::forAccount($accountId)->whereIn('id', $validated['conversation_ids']);

        $updates = match ($validated['action']) {
            'close' => ['status' => 'closed'],
            'open' => ['status' => 'open'],
            'pending' => ['status' => 'pending'],
            'mark_read' => ['unread_count' => 0],
            'assign' => (function () use ($validated, $accountId) {
                if ($validated['agent_id']) {
                    abort_unless(
                        User::where('id', $validated['agent_id'])->where('account_id', $accountId)->exists(),
                        422
                    );
                }

                return ['assigned_agent_id' => $validated['agent_id']];
            })(),
        };

        $count = $query->update($updates);

        if ($validated['action'] === 'assign') {
            $conversationIds = Conversation::forAccount($accountId)
                ->whereIn('id', $validated['conversation_ids'])
                ->pluck('id');

            // Los deals de las conversaciones asignadas espejan la asignación.
            Deal::whereIn('conversation_id', $conversationIds)->update(['assigned_to' => $validated['agent_id']]);
        }

        return response()->json(['ok' => true, 'updated' => $count]);
    }

    private function authorizeConversation(Request $request, Conversation $conversation): void
    {
        $user = $request->user();

        abort_if($conversation->account_id !== $user->account_id, 403);

        // Restricción por rol: agent/viewer solo pueden interactuar con
        // conversaciones que tienen asignadas. admin/owner sin restricción.
        if (! $user->hasRoleAtLeast(User::ROLE_ADMIN)) {
            abort_if($conversation->assigned_agent_id !== $user->id, 403,
                'No tienes acceso a esta conversación. Pídele al admin que te la asigne.');
        }
    }
}
