<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Notification;
use App\Models\User;
use App\Services\WhatsApp\ServiceWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    /**
     * Mensajes entrantes nuevos desde `since`, para el aviso en vivo que corre
     * en TODA la app (no solo en el Inbox).
     *
     * Va por polling y no por websocket a propósito: Reverb no corre en el
     * servidor (`BROADCAST_CONNECTION=log`), así que un canal de Echo no
     * llegaría nunca. Esto funciona con la infraestructura que hay.
     *
     * Respeta el rol: el agente solo se entera de SUS conversaciones.
     */
    public function recentInbound(Request $request): JsonResponse
    {
        $user = $request->user();

        $since = $request->query('since')
            ? Carbon::parse($request->query('since'))
            : now()->subMinute();

        // Tope defensivo: un `since` viejo (pestaña dormida días) traería una
        // avalancha de toasts. Se muestra lo reciente y nada más.
        if ($since->lt(now()->subHour())) {
            $since = now()->subHour();
        }

        $messages = Message::query()
            ->where('sender_type', Message::SENDER_CUSTOMER)
            ->where('messages.created_at', '>', $since)
            ->whereHas('conversation', fn ($q) => $q
                ->where('account_id', $user->account_id)
                ->when(! $user->hasRoleAtLeast(User::ROLE_ADMIN),
                    fn ($x) => $x->where('assigned_agent_id', $user->id)))
            ->with('conversation.contact:id,name,phone')
            ->orderByDesc('messages.created_at')
            ->limit(8)
            ->get(['id', 'conversation_id', 'content_text', 'content_type', 'created_at']);

        return response()->json([
            // El servidor manda su reloj: si el del navegador está corrido, un
            // `since` calculado en el cliente se saltearía mensajes.
            'now' => now()->toIso8601String(),
            'messages' => $messages->map(fn (Message $m) => [
                'id' => $m->id,
                'conversation_id' => $m->conversation_id,
                'contact' => $m->conversation?->contact?->name
                    ?: $m->conversation?->contact?->phone
                    ?: 'Contacto',
                'preview' => $this->preview($m),
                'at' => $m->created_at->toIso8601String(),
            ])->values(),
        ]);
    }

    /** Texto corto del mensaje; los adjuntos se describen por su tipo. */
    private function preview(Message $message): string
    {
        $text = trim((string) $message->content_text);

        if ($text === '') {
            return match ($message->content_type) {
                'audio' => '🎙 Audio',
                'image' => '🖼️ Imagen',
                'video' => '🎥 Video',
                'document' => '📄 Documento',
                'sticker' => '🟪 Sticker',
                'location' => '📍 Ubicación',
                default => 'Mensaje nuevo',
            };
        }

        return mb_strlen($text) > 120 ? mb_substr($text, 0, 120).'…' : $text;
    }

    public function index(Request $request): Response
    {
        $notifications = Notification::where('user_id', $request->user()->id)
            // El estado de la conversación viaja para poder pintarlo en la
            // lista sin tener que abrir el aviso.
            ->with(['contact:id,name,phone', 'actor:id,name', 'conversation:id,status'])
            ->orderByDesc('created_at')
            ->paginate(30);

        // Ventana del contacto al que se refiere el aviso: si la IA falló o
        // alguien espera respuesta, saber cuánto queda para contestar gratis
        // es justo lo que decide si se atiende ahora o después.
        $windows = app(ServiceWindow::class)
            ->forContacts($notifications->pluck('contact_id')->filter()->unique()->values()->all());

        $notifications->each(fn ($n) => $n->setAttribute('service_window', $windows[$n->contact_id] ?? null));

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
        ]);
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        Notification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back();
    }
}
