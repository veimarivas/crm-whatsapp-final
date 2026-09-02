<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ChannelConfig;
use App\Models\User;
use App\Services\Channels\ChannelRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * F1 — conectar Telegram desde la pantalla, sin tocar la base.
 *
 * Hasta acá había que insertar la fila de `channel_configs` a mano. Eso alcanza
 * para probar; no para entregarlo.
 */
class ChannelSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);
    }

    private function fakeTelegram(array $getMe = ['ok' => true, 'result' => ['username' => 'mi_bot']], array $setWebhook = ['ok' => true, 'result' => true]): void
    {
        // Por método y no con `*`: los stubs de `Http::fake` se evalúan en
        // orden y gana el primero que matchea, así que un comodín taparía al
        // específico.
        Http::fake([
            '*/getMe' => Http::response($getMe, ($getMe['ok'] ?? false) ? 200 : 401),
            '*/setWebhook' => Http::response($setWebhook, ($setWebhook['ok'] ?? false) ? 200 : 400),
            '*/deleteWebhook' => Http::response(['ok' => true, 'result' => true]),
        ]);
    }

    public function test_conectar_valida_el_token_y_registra_el_webhook(): void
    {
        $this->fakeTelegram();

        $this->actingAs($this->owner)
            ->post(route('settings.channels.telegram'), ['bot_token' => '123:ABC'])
            ->assertSessionHasNoErrors();

        $config = ChannelConfig::firstOrFail();

        $this->assertTrue($config->is_enabled);
        $this->assertSame('123:ABC', $config->credential('bot_token'));
        $this->assertSame('mi_bot', $config->settings['bot_username']);

        // El secreto lo genera el servidor, no el usuario: es lo único que
        // separa un update de Telegram de cualquiera que descubra la URL, y una
        // clave elegida a mano termina siendo «telegram123».
        $this->assertNotEmpty($config->credential('webhook_secret'));

        Http::assertSent(function ($request) use ($config) {
            return str_contains($request->url(), '/setWebhook')
                && $request->data()['secret_token'] === $config->credential('webhook_secret')
                && str_contains($request->data()['url'], "/webhooks/telegram/{$this->account->id}");
        });
    }

    public function test_un_token_invalido_no_guarda_nada(): void
    {
        // Una configuración guardada que no funciona es peor que ninguna: la
        // pantalla diría «Conectado» y los mensajes no llegarían.
        $this->fakeTelegram(getMe: ['ok' => false, 'description' => 'Unauthorized']);

        $this->actingAs($this->owner)
            ->from(route('settings.channels'))
            ->post(route('settings.channels.telegram'), ['bot_token' => 'malo'])
            ->assertSessionHasErrors('bot_token');

        $this->assertSame(0, ChannelConfig::count());
    }

    public function test_si_el_webhook_no_se_puede_registrar_queda_deshabilitado(): void
    {
        // Token válido pero URL no pública (el caso de desarrollo local).
        // Mostrar «Conectado» sin que llegue nada es la peor forma de fallar.
        $this->fakeTelegram(setWebhook: ['ok' => false, 'description' => 'bad webhook: HTTPS url must be provided']);

        $this->actingAs($this->owner)
            ->from(route('settings.channels'))
            ->post(route('settings.channels.telegram'), ['bot_token' => '123:ABC'])
            ->assertSessionHasErrors('bot_token');

        $this->assertFalse(ChannelConfig::firstOrFail()->is_enabled);
    }

    public function test_desconectar_avisa_a_telegram_y_borra_las_credenciales(): void
    {
        $this->fakeTelegram();

        $this->actingAs($this->owner)->post(route('settings.channels.telegram'), ['bot_token' => '123:ABC']);
        $this->assertSame(1, ChannelConfig::count());

        $this->actingAs($this->owner)->delete(route('settings.channels.telegram.destroy'));

        // Se le avisa a Telegram: si solo se apagara acá, seguiría golpeando la
        // URL y recibiendo 404 para siempre.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/deleteWebhook'));

        // Y la fila se borra con el token adentro, en vez de quedar apagada
        // conservando una credencial que ya nadie va a usar.
        $this->assertSame(0, ChannelConfig::count());
    }

    public function test_un_agente_no_puede_conectar_canales(): void
    {
        $agente = User::create([
            'name' => 'Ana', 'email' => 'ana@test.com', 'password' => bcrypt('x'),
            'account_id' => $this->account->id, 'account_role' => User::ROLE_AGENT,
        ]);

        $this->fakeTelegram();

        $this->actingAs($agente)
            ->post(route('settings.channels.telegram'), ['bot_token' => '123:ABC'])
            ->assertForbidden();

        $this->assertSame(0, ChannelConfig::count());
        Http::assertNothingSent();
    }

    public function test_la_pantalla_muestra_el_estado_de_cada_canal(): void
    {
        $this->actingAs($this->owner)
            ->get(route('settings.channels'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Channels')
                ->where('channels.0.channel', 'whatsapp')
                ->where('channels.0.connected', false)
                ->where('channels.1.channel', 'telegram')
                ->where('channels.1.connected', false)
                ->where('telegramWebhookUrl', route('webhooks.telegram', $this->account->id))
            );
    }

    public function test_el_comando_re_registra_el_webhook(): void
    {
        $this->fakeTelegram();
        $this->actingAs($this->owner)->post(route('settings.channels.telegram'), ['bot_token' => '123:ABC']);

        // Existe para lo que la pantalla no puede: reapuntar el bot tras un
        // cambio de dominio, o reparar un webhook que Telegram desactivó.
        $this->artisan('wacrm:telegram-setup-webhook')->assertSuccessful();

        $this->assertSame(2, Http::recorded(fn ($r) => str_contains($r->url(), '/setWebhook'))->count());
    }

    public function test_el_comando_se_niega_a_registrar_sin_secreto(): void
    {
        ChannelConfig::create([
            'account_id' => $this->account->id,
            'channel' => ChannelRules::TELEGRAM,
            'is_enabled' => true,
            'credentials' => ['bot_token' => '123:ABC'], // sin webhook_secret
        ]);

        $this->fakeTelegram();

        // Sin secreto el webhook quedaría abierto a cualquiera que descubra la
        // URL. Antes que registrar algo inseguro, no se registra y se dice.
        $this->artisan('wacrm:telegram-setup-webhook')->assertFailed();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/setWebhook'));
    }
}
