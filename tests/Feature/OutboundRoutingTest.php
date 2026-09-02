<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Automations\Engine;
use App\Services\Channels\ChannelRouter;
use App\Services\Channels\ChannelRules;
use App\Services\WhatsApp\Messenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F0/T0.3 — los puntos de salida internos pasan por el router.
 *
 * **No era cosmético.** `AiAutoReplyJob` se encola para TODOS los canales (lo
 * hace el `Ingestor`), pero enviaba con `Messenger`, o sea por Meta. Con
 * Telegram andando eso habría intentado responderle a un contacto **sin
 * teléfono** — y en el mejor caso falla, en el peor le escribe a otra persona.
 * Lo mismo con las automatizaciones y los flows.
 */
class OutboundRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);
    }

    private function contacto(?string $phone = '584125550001'): Contact
    {
        return Contact::create([
            'account_id' => $this->account->id,
            'phone' => $phone,
            'name' => 'Ana',
        ]);
    }

    private function conversacion(Contact $contact, string $channel): Conversation
    {
        return Conversation::create([
            'account_id' => $this->account->id,
            'contact_id' => $contact->id,
            'channel' => $channel,
            'status' => 'open',
            'last_message_at' => now(),
        ]);
    }

    public function test_el_router_se_reconstruye_y_ve_el_contenedor_actual(): void
    {
        // Regresión de diseño: como singleton, el router capturaba el
        // `Messenger` del primer uso y no veía ningún doble creado después. El
        // síntoma no se parecía a la causa — un contador de errores que no
        // cuadraba, porque el envío real salía sin credenciales.
        $primero = app(ChannelRouter::class);
        $segundo = app(ChannelRouter::class);

        $this->assertNotSame($primero, $segundo);
    }

    public function test_una_automatizacion_usa_la_conversacion_del_contacto_cualquiera_sea_su_canal(): void
    {
        // Contacto SIN teléfono que solo escribió por Telegram.
        $contact = $this->contacto(phone: null);
        $telegram = $this->conversacion($contact, ChannelRules::TELEGRAM);

        $this->mock(Messenger::class, function ($mock) {
            // Si el motor cayera a `resolveConversation()`, abriría un hilo de
            // WhatsApp para alguien que no tiene número.
            $mock->shouldNotReceive('resolveConversation');
        });

        $engine = app(Engine::class);

        $metodo = new \ReflectionMethod($engine, 'runAction');
        $metodo->setAccessible(true);

        $paso = new \App\Models\AutomationStep([
            'step_type' => 'send_message',
            'step_config' => ['text' => 'hola'],
        ]);

        try {
            $metodo->invoke($engine, $paso, ['contact_id' => $contact->id]);
        } catch (\RuntimeException $e) {
            // La cuenta no conectó Telegram, así que el adapter lanza con ese
            // motivo. Es el resultado correcto: mejor que falle diciendo qué
            // falta a que el mensaje salga por el canal equivocado.
            $this->assertStringContainsString('Telegram no está conectado', $e->getMessage());
        }

        // Y no se creó ninguna conversación de WhatsApp por el camino.
        $this->assertSame(1, $contact->conversations()->count());
        $this->assertSame($telegram->id, $contact->conversations()->first()->id);
    }

    public function test_un_canal_sin_conectar_no_manda_por_whatsapp(): void
    {
        $contact = $this->contacto();
        $telegram = $this->conversacion($contact, ChannelRules::TELEGRAM);

        // El `Messenger` no puede ser llamado: si el router cayera a WhatsApp,
        // el mensaje saldría al teléfono del contacto — que puede no existir o,
        // peor, ser el de otra persona.
        $this->mock(Messenger::class, fn ($mock) => $mock->shouldNotReceive('sendText'));

        $this->expectException(\RuntimeException::class);

        app(ChannelRouter::class)->forConversation($telegram)->sendText($telegram, 'hola');
    }

    public function test_whatsapp_sigue_saliendo_por_el_messenger_de_siempre(): void
    {
        $contact = $this->contacto();
        $wa = $this->conversacion($contact, ChannelRules::WHATSAPP);

        $this->mock(Messenger::class, function ($mock) use ($wa) {
            $mock->shouldReceive('sendText')
                ->once()
                ->with($wa, 'hola', \Mockery::any(), \Mockery::any())
                ->andReturn(new \App\Models\Message());
        });

        app(ChannelRouter::class)->forConversation($wa)->sendText($wa, 'hola');
    }
}
