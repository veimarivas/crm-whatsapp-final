<?php

namespace App\Http\Controllers;

use App\Models\Automation;
use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Deal;
use App\Models\Flow;
use App\Models\Message;
use App\Services\WhatsApp\ServiceWindow;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $accountId = $request->user()->account_id;

        // Mensajes por día (últimos 7 días): entrantes de clientes y salientes
        // partidos en humano e IA, para el área apilada del gráfico.
        $daily = Message::whereHas('conversation', fn ($q) => $q->where('account_id', $accountId))
            ->where('messages.created_at', '>=', now()->subDays(6)->startOfDay())
            ->selectRaw("DATE(messages.created_at) as day,
                SUM(sender_type = 'customer') as inbound,
                SUM(sender_type = 'agent') as agent_out,
                SUM(sender_type = 'bot') as bot_out")
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $chart = collect(range(6, 0))->map(function ($daysAgo) use ($daily) {
            $day = now()->subDays($daysAgo)->toDateString();

            return [
                'day' => $day,
                'inbound' => (int) ($daily[$day]->inbound ?? 0),
                'agent_out' => (int) ($daily[$day]->agent_out ?? 0),
                'bot_out' => (int) ($daily[$day]->bot_out ?? 0),
            ];
        });

        return Inertia::render('Dashboard', [
            'stats' => [
                'contacts' => Contact::forAccount($accountId)->count(),
                'openConversations' => Conversation::forAccount($accountId)->where('status', 'open')->count(),
                'pendingConversations' => Conversation::forAccount($accountId)->where('status', 'pending')->count(),
                'unreadTotal' => (int) Conversation::forAccount($accountId)->sum('unread_count'),
                'pipelineValue' => (float) Deal::forAccount($accountId)->where('status', 'open')->sum('value'),
                'dealsWon' => Deal::forAccount($accountId)->where('status', 'won')->count(),
                'broadcasts' => Broadcast::forAccount($accountId)->where('status', 'sent')->count(),
                'automations' => Automation::forAccount($accountId)->where('is_active', true)->count(),
                'flows' => Flow::forAccount($accountId)->where('status', 'active')->count(),
                'pending' => Conversation::forAccount($accountId)->where('status', 'pending')->count(),
                'aiReplies' => Message::whereHas('conversation', fn ($q) => $q->where('account_id', $accountId))
                    ->where('sender_type', 'bot')
                    ->where('messages.created_at', '>=', now()->subDays(6)->startOfDay())
                    ->count(),
            ],
            'chart' => $chart,
            'recentConversations' => $this->withServiceWindow(
                Conversation::forAccount($accountId)
                    ->with('contact:id,name,phone')
                    ->orderByDesc('last_message_at')
                    ->limit(6)
                    ->get(['id', 'contact_id', 'status', 'last_message_text', 'last_message_at', 'unread_count'])
            ),
            'currency' => $request->user()->account->default_currency,
        ]);
    }

    /**
     * Adjunta la ventana de servicio a cada conversación del listado, para
     * ver de un vistazo a quién todavía se le puede escribir sin costo.
     *
     * @param  Collection<int, Conversation>  $conversations
     * @return Collection<int, Conversation>
     */
    private function withServiceWindow($conversations)
    {
        $windows = app(ServiceWindow::class)
            ->forMany($conversations->pluck('id')->all());

        return $conversations->each(
            fn (Conversation $c) => $c->setAttribute('service_window', $windows[$c->id] ?? null)
        );
    }
}
