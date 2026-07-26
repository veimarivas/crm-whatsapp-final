<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\WhatsApp\ServiceWindow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $notifications = Notification::where('user_id', $request->user()->id)
            ->with(['contact:id,name,phone', 'actor:id,name'])
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
