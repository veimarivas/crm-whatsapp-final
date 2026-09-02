<?php

namespace App\Http\Controllers;

use App\Models\ChannelConfig;
use App\Models\User;
use App\Services\Channels\ChannelRules;
use App\Services\Telegram\TelegramApi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `/settings/channels` — qué canales tiene conectados la cuenta.
 *
 * Hasta acá conectar Telegram exigía insertar una fila en `channel_configs` a
 * mano. Eso alcanza para probar; no para entregarlo.
 *
 * WhatsApp **no se administra desde acá**: tiene su propia pantalla
 * (`/settings/whatsapp`) con la configuración de Meta, que es bastante más que
 * un token. Se muestra su estado y se enlaza — dos lugares para lo mismo es
 * cómo se termina con dos verdades.
 */
class ChannelController extends Controller
{
    public function index(Request $request): Response
    {
        $accountId = $request->user()->account_id;

        // ⚠️ `->get('clave')` y no `$coleccion['clave']`: el acceso por corchetes
        // a una clave inexistente de una Collection **lanza** («Undefined array
        // key»), no devuelve null, así que el `?->` de más abajo nunca llega a
        // actuar. Con un canal sin configurar, la pantalla daba 500.
        $configs = ChannelConfig::forAccount($accountId)->get()->keyBy('channel');
        $telegram = $configs->get(ChannelRules::TELEGRAM);

        return Inertia::render('Settings/Channels', [
            'channels' => [
                [
                    'channel' => ChannelRules::WHATSAPP,
                    'connected' => \App\Models\WhatsappConfig::forAccount($accountId)
                        ->where('status', 'connected')->exists(),
                    // Se administra en su propia pantalla: acá solo el estado.
                    'managed_elsewhere' => route('settings.whatsapp'),
                ],
                [
                    'channel' => ChannelRules::TELEGRAM,
                    'connected' => (bool) $telegram?->is_enabled,
                    'connected_at' => $telegram?->connected_at?->toIso8601String(),
                    'bot_username' => $telegram?->settings['bot_username'] ?? null,
                    'managed_elsewhere' => null,
                ],
            ],
            // La URL a la que Telegram va a mandar los updates. Se muestra
            // porque cuando algo no llega, lo primero que hay que poder mirar
            // es si esta URL es la que el bot tiene registrada.
            'telegramWebhookUrl' => route('webhooks.telegram', $accountId),
        ]);
    }

    /**
     * Conectar Telegram: pegar el bot token y listo.
     *
     * El token se **valida contra Telegram antes de guardarlo** (`getMe`). Una
     * configuración guardada que no funciona es peor que ninguna: la pantalla
     * diría «Conectado» y los mensajes no llegarían, sin nada que mirar.
     */
    public function connectTelegram(Request $request): RedirectResponse
    {
        $this->assertAdmin($request);

        $validated = $request->validate([
            'bot_token' => 'required|string|max:100',
        ]);

        try {
            $bot = (new TelegramApi($validated['bot_token']))->getMe();
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['bot_token' => $e->getMessage()]);
        }

        $accountId = $request->user()->account_id;

        // El secreto se genera acá y no lo elige el usuario: es lo único que
        // separa un update de Telegram de cualquiera que descubra la URL, y una
        // clave elegida a mano termina siendo «telegram123».
        $secret = Str::random(48);

        $config = ChannelConfig::updateOrCreate(
            ['account_id' => $accountId, 'channel' => ChannelRules::TELEGRAM],
            [
                'credentials' => ['bot_token' => $validated['bot_token'], 'webhook_secret' => $secret],
                'settings' => ['bot_username' => $bot['username'] ?? null],
                'is_enabled' => true,
                'connected_at' => now(),
            ],
        );

        try {
            TelegramApi::for($config)->setWebhook(route('webhooks.telegram', $accountId), $secret);
        } catch (\RuntimeException $e) {
            // El token era válido pero el webhook no se pudo registrar (URL no
            // pública, sin HTTPS…). Se deja DESHABILITADO: mostrar «Conectado»
            // sin que llegue nada es la peor forma de fallar.
            $config->update(['is_enabled' => false, 'connected_at' => null]);

            throw ValidationException::withMessages([
                'bot_token' => 'El token es válido, pero no se pudo registrar el webhook: '.$e->getMessage()
                    .' — Telegram exige una URL pública con HTTPS.',
            ]);
        }

        return back()->with('success', "Telegram conectado (@{$bot['username']}).");
    }

    public function disconnectTelegram(Request $request): RedirectResponse
    {
        $this->assertAdmin($request);

        $config = ChannelConfig::forAccount($request->user()->account_id)
            ->where('channel', ChannelRules::TELEGRAM)
            ->first();

        if (! $config) {
            return back();
        }

        // Se le avisa a Telegram para que deje de mandar: si solo se apagara
        // acá, seguiría golpeando la URL y recibiendo 404 para siempre.
        rescue(fn () => TelegramApi::for($config)->deleteWebhook(), report: false);

        // La fila se borra con las credenciales adentro. Dejarla apagada
        // conservaría un bot token que ya nadie va a usar.
        $config->delete();

        return back()->with('success', 'Telegram desconectado.');
    }

    private function assertAdmin(Request $request): void
    {
        abort_if(! $request->user()->hasRoleAtLeast(User::ROLE_ADMIN), 403);
    }
}
