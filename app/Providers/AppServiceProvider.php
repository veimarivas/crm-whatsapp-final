<?php

namespace App\Providers;

use App\Events\InboxUpdated;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Channels\ChannelRouter;
use App\Services\Channels\WhatsAppAdapter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // WhatsApp va siempre; los canales que se agreguen se registran acá,
        // condicionados a su configuración.
        //
        // ⚠️ **`bind` y NO `singleton`, a propósito.** Un singleton captura sus
        // adapters —y con ellos el `Messenger`— en el primer uso y se queda con
        // esa instancia para siempre. Eso hace que cualquier doble creado
        // DESPUÉS del primer envío no lo alcance, y el fallo no se parece a su
        // causa: el envío real intenta salir sin credenciales, falla, y lo que
        // se ve es un contador de errores que no cuadra. Construirlo cuesta
        // nada; que refleje el estado actual del contenedor vale mucho más.
        $this->app->bind(ChannelRouter::class, fn ($app) => (new ChannelRouter)
            ->register($app->make(WhatsAppAdapter::class)));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Rate limits: generoso para Meta (envía en ráfagas y sus
        // reintentos ante 429 pueden acabar desactivando el webhook),
        // y por clave para la API pública.
        RateLimiter::for('whatsapp-webhook', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));

        RateLimiter::for('public-api', fn (Request $request) => Limit::perMinute(120)
            ->by(sha1($request->bearerToken() ?? $request->ip())));

        // Tiempo real del inbox: cualquier mensaje nuevo o cambio de
        // conversación notifica al canal de la cuenta. `rescue` evita
        // que un Reverb caído rompa el flujo de mensajes (el cliente
        // conserva el polling como respaldo).
        Message::created(function (Message $message) {
            $message->conversation?->loadMissing('account');
            $accountId = $message->conversation?->account_id;

            if ($accountId) {
                rescue(fn () => broadcast(new InboxUpdated($accountId, $message->conversation_id)), report: false);
            }
        });

        Conversation::updated(function (Conversation $conversation) {
            rescue(fn () => broadcast(new InboxUpdated($conversation->account_id, $conversation->id)), report: false);
        });
    }
}
