<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApiKey;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappConfig;
use App\Services\Channels\ChannelRouter;
use App\Services\Channels\ChannelRules;
use App\Services\Channels\WhatsAppAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * F0 — se puede responder EN una conversación, no solo a un teléfono.
 *
 * Era el **bloqueante 2** del plan omnicanal: todo lo que salía del CRM externo
 * direccionaba por teléfono, así que a un lead de Telegram no había forma de
 * contestarle — no tiene número. El camino por teléfono no cambió: sigue
 * siendo el de todos los mensajes que ya se mandaban.
 */
class OutboundByConversationTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        WhatsappConfig::create([
            'account_id' => $this->account->id,
            'phone_number_id' => '111',
            'access_token' => 'token',
            'status' => 'connected',
        ]);

        [, $this->token] = ApiKey::issue($this->account->id, $owner->id, 'komo', ['messages:write']);

        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.out']]])]);
    }

    private function conversacion(string $channel = ChannelRules::WHATSAPP, ?string $phone = '584125550001'): Conversation
    {
        $contact = Contact::create([
            'account_id' => $this->account->id,
            'phone' => $phone,
            'name' => 'Ana',
        ]);

        return Conversation::create([
            'account_id' => $this->account->id,
            'contact_id' => $contact->id,
            'channel' => $channel,
            'status' => 'open',
        ]);
    }

    public function test_envia_por_conversation_id(): void
    {
        $conversation = $this->conversacion();

        $this->withToken($this->token)->postJson('/api/v1/messages', [
            'conversation_id' => $conversation->id,
            'text' => 'hola',
        ])->assertCreated();

        $message = Message::where('sender_type', Message::SENDER_AGENT)->firstOrFail();
        $this->assertSame($conversation->id, $message->conversation_id);
        $this->assertSame('hola', $message->content_text);
    }

    public function test_el_camino_por_telefono_no_cambio(): void
    {
        // Exactamente el payload de siempre: es como manda todo lo que ya
        // existía, y tiene que seguir funcionando igual.
        $this->withToken($this->token)->postJson('/api/v1/messages', [
            'to' => '584125559999',
            'text' => 'hola',
        ])->assertCreated();

        $conversation = Conversation::firstOrFail();
        $this->assertSame(ChannelRules::WHATSAPP, $conversation->channel);
        $this->assertSame('584125559999', $conversation->channel_conversation_id);
    }

    public function test_hace_falta_uno_de_los_dos(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/messages', ['text' => 'hola'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to', 'conversation_id']);
    }

    public function test_una_conversacion_de_otra_cuenta_es_404(): void
    {
        $otroOwner = User::create(['name' => 'Otro', 'email' => 'otro@test.com', 'password' => bcrypt('x')]);
        $otraCuenta = Account::create(['name' => 'Otra', 'owner_user_id' => $otroOwner->id]);
        $ajeno = Contact::create(['account_id' => $otraCuenta->id, 'phone' => '584125550002']);
        $ajena = Conversation::create([
            'account_id' => $otraCuenta->id, 'contact_id' => $ajeno->id, 'status' => 'open',
        ]);

        $this->withToken($this->token)->postJson('/api/v1/messages', [
            'conversation_id' => $ajena->id,
            'text' => 'hola',
        ])->assertNotFound();

        $this->assertSame(0, Message::count());
    }

    public function test_un_canal_sin_adapter_no_cae_a_whatsapp(): void
    {
        // Una conversación de Telegram sin adapter registrado: si el router
        // cayera a WhatsApp, el mensaje saldría al teléfono del contacto — que
        // puede no existir o, peor, ser el de otra persona.
        $conversation = $this->conversacion(ChannelRules::TELEGRAM);

        $this->withToken($this->token)->postJson('/api/v1/messages', [
            'conversation_id' => $conversation->id,
            'text' => 'hola',
        ])->assertStatus(502);

        $this->assertSame(0, Message::where('status', 'sent')->count());
        Http::assertNothingSent();
    }

    public function test_la_conexion_de_whatsapp_no_bloquea_otros_canales(): void
    {
        WhatsappConfig::forAccount($this->account->id)->update(['status' => 'disconnected']);

        // WhatsApp desconectado corta WhatsApp…
        $wa = $this->conversacion(ChannelRules::WHATSAPP);
        $this->withToken($this->token)->postJson('/api/v1/messages', [
            'conversation_id' => $wa->id, 'text' => 'hola',
        ])->assertStatus(422);

        // …pero no puede cortar Telegram: sería bloquear un canal por la
        // configuración de otro. Falla por falta de adapter (502), no por la
        // conexión de WhatsApp (422).
        $tg = $this->conversacion(ChannelRules::TELEGRAM, phone: null);
        $this->withToken($this->token)->postJson('/api/v1/messages', [
            'conversation_id' => $tg->id, 'text' => 'hola',
        ])->assertStatus(502);
    }

    public function test_el_router_resuelve_whatsapp_y_rechaza_lo_que_no_conoce(): void
    {
        $router = app(ChannelRouter::class);

        $this->assertInstanceOf(WhatsAppAdapter::class, $router->adapter(ChannelRules::WHATSAPP));
        $this->assertSame([ChannelRules::WHATSAPP], $router->registered());
        $this->assertFalse($router->supports(ChannelRules::TELEGRAM));

        $this->expectExceptionMessage('No hay forma de enviar por el canal «telegram».');
        $router->adapter(ChannelRules::TELEGRAM);
    }
}
