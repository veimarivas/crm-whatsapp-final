<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Notification;
use App\Models\QuickReply;
use App\Models\WhatsappConfig;
use App\Services\Broadcasts\Creator;
use App\Services\WhatsApp\Messenger;
use App\Services\WhatsApp\MetaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API pública v1 — equivalente a /api/v1 del original.
 * Autenticada por API key (middleware api.key) con scopes.
 */
class ApiController extends Controller
{
    private function accountId(Request $request): string
    {
        return $request->attributes->get('account_id');
    }

    public function me(Request $request): JsonResponse
    {
        $key = $request->attributes->get('api_key');
        $account = Account::find($this->accountId($request));

        return response()->json([
            'account' => ['id' => $account->id, 'name' => $account->name],
            'key' => ['name' => $key->name, 'scopes' => $key->scopes],
        ]);
    }

    public function contacts(Request $request): JsonResponse
    {
        $contacts = Contact::forAccount($this->accountId($request))
            ->with('tags:id,name')
            ->when($request->query('q'), fn ($q, $term) => $q
                ->where(fn ($w) => $w->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")))
            // Filtro server-side por tag — lo usa meta_ads para armar
            // Custom Audiences sin paginar el catálogo completo.
            ->when($request->query('tag_id'), fn ($q, $tagId) => $q
                ->whereHas('tags', fn ($t) => $t->where('tags.id', $tagId)))
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($contacts);
    }

    public function storeContact(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => 'required|string|max:32',
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'company' => 'nullable|string|max:255',
        ]);

        $accountId = $this->accountId($request);
        $normalized = Contact::normalizePhone($validated['phone']);

        $existing = Contact::forAccount($accountId)->where('phone_normalized', $normalized)->first();

        if ($existing) {
            return response()->json(['message' => 'Contact already exists.', 'contact' => $existing], 409);
        }

        $contact = Contact::create([...$validated, 'account_id' => $accountId]);

        return response()->json($contact, 201);
    }

    public function showContact(Request $request, string $id): JsonResponse
    {
        $contact = Contact::forAccount($this->accountId($request))
            ->with(['tags:id,name', 'customValues'])
            ->findOrFail($id);

        return response()->json($contact);
    }

    public function updateContact(Request $request, string $id): JsonResponse
    {
        $contact = Contact::forAccount($this->accountId($request))->findOrFail($id);

        $contact->update($request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'company' => 'nullable|string|max:255',
        ]));

        return response()->json($contact->fresh());
    }

    public function destroyContact(Request $request, string $id): JsonResponse
    {
        Contact::forAccount($this->accountId($request))->findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }

    public function conversations(Request $request): JsonResponse
    {
        $conversations = Conversation::forAccount($this->accountId($request))
            ->with('contact:id,name,phone')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('last_message_at')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($conversations);
    }

    public function messages(Request $request, string $id): JsonResponse
    {
        $conversation = Conversation::forAccount($this->accountId($request))->findOrFail($id);

        return response()->json(
            $conversation->messages()->orderByDesc('created_at')->paginate(50),
        );
    }

    public function broadcasts(Request $request): JsonResponse
    {
        return response()->json(
            Broadcast::forAccount($this->accountId($request))
                ->orderByDesc('created_at')
                ->paginate(min((int) $request->query('per_page', 25), 100)),
        );
    }

    public function showBroadcast(Request $request, string $id): JsonResponse
    {
        $broadcast = Broadcast::forAccount($this->accountId($request))->findOrFail($id);

        return response()->json([
            ...$broadcast->toArray(),
            'report' => $broadcast->audience_filter['report'] ?? null,
            'recipients_by_status' => $broadcast->recipients()
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
            // Motivos de fallo agrupados: sin esto, «40 fallaron» no dice si
            // fue la ventana, un teléfono inválido o que Meta rechazó todo.
            'failure_reasons' => $broadcast->recipients()
                ->where('status', 'failed')
                ->selectRaw('error_message, COUNT(*) as total')
                ->groupBy('error_message')
                ->pluck('total', 'error_message'),
        ]);
    }

    /** Crea y envía (o programa) un broadcast con una plantilla aprobada. */
    /**
     * Alta de un broadcast.
     *
     * Desde D1a acepta cuerpo de texto y audiencia por teléfonos, que es lo que
     * necesita Komo para dejar de tener su propio motor de envíos. Las dos
     * formas viejas (`body_type` ausente = plantilla, `audience` all|tags)
     * siguen validando y comportándose igual: el contrato es aditivo.
     */
    public function storeBroadcast(Request $request, Creator $creator): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'body_type' => 'nullable|in:template,text',
            // El texto y la plantilla son excluyentes, y cuál se exige depende
            // del cuerpo. `required_if` con el default ausente no alcanza:
            // sin `body_type` el cuerpo es plantilla (contrato viejo).
            'body_text' => 'required_if:body_type,text|nullable|string|max:4000',
            'media_base64' => 'nullable|string',
            'media_mime' => 'nullable|string|max:100',
            'template_name' => 'required_unless:body_type,text|nullable|string',
            'template_language' => 'required_unless:body_type,text|nullable|string|max:10',
            'template_variables' => 'nullable|array',
            'template_variables.*' => 'string|max:500',
            'header_media_url' => 'nullable|url|max:2048',
            'audience' => 'required|in:all,tags,phones',
            'tag_ids' => 'required_if:audience,tags|array',
            'tag_ids.*' => 'uuid',
            'recipients' => 'required_if:audience,phones|array|max:5000',
            'recipients.*.phone' => 'required|string|max:32',
            'recipients.*.external_ref' => 'nullable|string|max:64',
            'scheduled_at' => 'nullable|date|after:now',
        ]);

        $accountId = $this->accountId($request);

        $connected = WhatsappConfig::forAccount($accountId)
            ->where('status', 'connected')
            ->exists();

        if (! $connected) {
            return response()->json(['message' => 'WhatsApp is not connected.'], 422);
        }

        // El adjunto viaja en base64 con el alta y se guarda UNA vez: el envío
        // lo sube a Meta una sola vez para todos los destinatarios.
        if (! empty($validated['media_base64'])) {
            $binary = base64_decode($validated['media_base64'], true);

            if ($binary === false) {
                return response()->json(['message' => 'El adjunto no es base64 válido.'], 422);
            }

            $path = 'broadcasts/'.\Illuminate\Support\Str::uuid();
            \Illuminate\Support\Facades\Storage::disk('local')->put($path, $binary);
            $validated['body_media_path'] = $path;
        }

        try {
            $broadcast = $creator->create($accountId, $validated);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // El informe de audiencia va en la respuesta y no solo guardado: quien
        // pidió el envío tiene que poder decir «se manda a 40 de 300» ANTES de
        // que el usuario cierre la pantalla.
        return response()->json([
            ...$broadcast->toArray(),
            'report' => $broadcast->audience_filter['report'] ?? null,
        ], 201);
    }

    /**
     * Notificaciones in-app del user que emitió la API key en uso.
     * Consumido por el Komo Hub (Fase 5) para consolidar notifs de las
     * 3 apps en una sola campana. Shape normalizado con `link_path` para
     * armar deep-links desde el hub.
     */
    public function notifications(Request $request): JsonResponse
    {
        $key = $request->attributes->get('api_key');
        $accountId = $this->accountId($request);
        $userId = $key->created_by; // notifs del user "dueño" de la key (el hub)

        $query = Notification::forAccount($accountId)
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($request->query('since'), fn ($q, $since) => $q->where('created_at', '>=', $since))
            ->orderByDesc('created_at')
            ->limit(min((int) $request->query('limit', 50), 200));

        $items = $query->get()->map(fn ($n) => [
            'id' => $n->id,
            'type' => $n->type,
            'title' => $n->title,
            'body' => $n->body,
            // El deep-link natural: la conversación en el inbox; sino, la
            // página de notificaciones (patrón común para las 3 apps).
            'link_path' => $n->conversation_id
                ? '/inbox?conversation='.$n->conversation_id
                : '/notifications',
            'created_at' => $n->created_at->toIso8601String(),
            'read_at' => $n->read_at?->toIso8601String(),
        ]);

        return response()->json(['data' => $items]);
    }

    /** Envía un texto a un teléfono (crea contacto/conversación si no existen). */
    public function sendMessage(Request $request, Messenger $messenger): JsonResponse
    {
        $validated = $request->validate([
            'to' => 'required|string|max:32',
            'text' => 'required|string|max:4096',
        ]);

        $accountId = $this->accountId($request);

        $connected = WhatsappConfig::forAccount($accountId)->where('status', 'connected')->exists();

        if (! $connected) {
            return response()->json(['message' => 'WhatsApp is not connected.'], 422);
        }

        $contact = Contact::firstOrCreate(
            ['account_id' => $accountId, 'phone_normalized' => Contact::normalizePhone($validated['to'])],
            ['phone' => $validated['to']],
        );

        $conversation = $messenger->resolveConversation($contact);

        try {
            $message = $messenger->sendText($conversation, $validated['text'], Message::SENDER_AGENT);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json($message, 201);
    }

    /**
     * Enviar archivo (imagen/audio/video/documento) por WhatsApp desde una
     * integración externa (Komo). Recibe base64 + mime + filename opcional.
     * Requiere scope messages:write.
     */
    public function sendMediaMessage(Request $request, Messenger $messenger): JsonResponse
    {
        $validated = $request->validate([
            'to' => 'required|string|max:32',
            'file_base64' => 'required|string', // hasta ~16MB base64 = 22MB approx
            'mime_type' => 'required|string|max:100',
            'filename' => 'nullable|string|max:200',
            'caption' => 'nullable|string|max:1024',
        ]);

        $accountId = $this->accountId($request);

        $connected = WhatsappConfig::forAccount($accountId)->where('status', 'connected')->exists();
        if (! $connected) {
            return response()->json(['message' => 'WhatsApp is not connected.'], 422);
        }

        $contents = base64_decode($validated['file_base64'], true);
        if ($contents === false) {
            return response()->json(['message' => 'file_base64 no es base64 válido.'], 422);
        }

        $contact = Contact::firstOrCreate(
            ['account_id' => $accountId, 'phone_normalized' => Contact::normalizePhone($validated['to'])],
            ['phone' => $validated['to']],
        );
        $conversation = $messenger->resolveConversation($contact);

        try {
            $message = $messenger->sendMedia(
                $conversation,
                $contents,
                $validated['mime_type'],
                $validated['filename'] ?? 'archivo',
                $validated['caption'] ?? null,
                Message::SENDER_AGENT,
                null,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json($message, 201);
    }

    /**
     * Descarga binaria de un archivo de WhatsApp por su media_id.
     * Usado por integraciones (Komo) para servir audio/imagen desde su dominio.
     * Requiere que el media_id pertenezca a algún mensaje de la cuenta.
     */
    public function downloadMedia(Request $request, string $mediaId): Response
    {
        $accountId = $this->accountId($request);

        // Verificar que el media_id existe en algún mensaje de esta cuenta
        $exists = Message::whereHas('conversation', fn ($q) => $q->where('account_id', $accountId))
            ->where('media_url', $mediaId)
            ->exists();
        abort_unless($exists, 404, 'Media no encontrado en esta cuenta.');

        $config = WhatsappConfig::forAccount($accountId)->where('status', 'connected')->first();
        abort_unless($config, 422, 'WhatsApp no conectado.');

        $api = MetaApi::for($config);
        $url = $api->getMediaUrl($mediaId);
        abort_unless($url, 502, 'Meta no devolvió URL para este media.');

        $response = $api->downloadMedia($url);

        return response($response->body(), 200, [
            'Content-Type' => $response->header('Content-Type') ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** Lista las plantillas rápidas de la cuenta (compartidas por todo el equipo). */
    public function quickReplies(Request $request): JsonResponse
    {
        $accountId = $this->accountId($request);

        return response()->json(
            QuickReply::forAccount($accountId)
                ->whereNull('user_id') // solo las compartidas del equipo (por API no filtramos por user)
                ->orderBy('shortcut')
                ->get(['id', 'shortcut', 'content'])
        );
    }
}
