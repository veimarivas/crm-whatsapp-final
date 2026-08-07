<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Automation;
use App\Models\AutomationStep;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Tag;
use App\Models\User;
use App\Models\WhatsappConfig;
use App\Services\Automations\Recipes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Galería de plantillas, precarga del editor y simulador de pruebas.
 */
class AutomationBuilderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $account;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
        ]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->user->id]);
        $this->user->update(['account_id' => $this->account->id, 'account_role' => 'owner']);
        $this->user->refresh();

        WhatsappConfig::create([
            'account_id' => $this->account->id,
            'phone_number_id' => '111222333',
            'access_token' => 'token',
            'status' => 'connected',
        ]);

        $this->contact = Contact::create([
            'account_id' => $this->account->id,
            'phone' => '584125550001',
            'name' => 'Ana',
        ]);
    }

    public function test_el_listado_trae_los_pasos_raiz_para_el_resumen(): void
    {
        $automation = Automation::create([
            'account_id' => $this->account->id,
            'name' => 'Bienvenida',
            'trigger_type' => 'new_contact',
            'trigger_config' => [],
            'is_active' => true,
        ]);
        AutomationStep::create([
            'automation_id' => $automation->id,
            'step_type' => 'send_message',
            'step_config' => ['text' => 'Hola {name}'],
            'position' => 0,
        ]);

        $this->actingAs($this->user)
            ->get(route('automations.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Automations/Index')
                ->has('recipes', count(Recipes::all()))
                ->where('automations.0.root_steps.0.type', 'send_message')
                ->where('automations.0.root_steps.0.config.text', 'Hola {name}'));
    }

    public function test_una_receta_precarga_el_editor_sin_guardar_nada(): void
    {
        $this->actingAs($this->user)
            ->get(route('automations.create', ['recipe' => 'seguimiento-24h']))
            ->assertInertia(fn ($page) => $page
                ->component('Automations/Edit')
                ->where('isDraft', true)
                ->where('automation.trigger_type', 'keyword')
                ->where('steps.1.type', 'wait')
                // Las ramas vienen normalizadas aunque la receta no las declare.
                ->where('steps.0.children_yes', [])
                ->has('sampleContacts'));

        $this->assertSame(0, Automation::count());
    }

    public function test_receta_inexistente_abre_el_editor_vacio(): void
    {
        $this->actingAs($this->user)
            ->get(route('automations.create', ['recipe' => 'no-existe']))
            ->assertInertia(fn ($page) => $page
                ->where('isDraft', false)
                ->where('automation', null)
                ->where('steps', []));
    }

    public function test_la_simulacion_recorre_la_rama_correcta_y_no_envia_nada(): void
    {
        Http::fake();
        $vip = Tag::create(['account_id' => $this->account->id, 'name' => 'VIP']);

        $response = $this->actingAs($this->user)->postJson(route('automations.simulate'), [
            'trigger_type' => 'keyword',
            'trigger_config' => ['keywords' => ['precio']],
            'message_text' => '¿cuál es el PRECIO?',
            'contact_id' => $this->contact->id,
            'steps' => [
                ['type' => 'send_message', 'config' => ['text' => 'Hola {name}'], 'children_yes' => [], 'children_no' => []],
                [
                    'type' => 'condition',
                    'config' => ['field' => 'message_text', 'operator' => 'contains', 'value' => 'precio'],
                    'children_yes' => [['type' => 'add_tag', 'config' => ['tag_id' => $vip->id], 'children_yes' => [], 'children_no' => []]],
                    'children_no' => [['type' => 'send_message', 'config' => ['text' => 'No entendí'], 'children_yes' => [], 'children_no' => []]],
                ],
            ],
        ])->assertOk();

        $response->assertJsonPath('trigger.matched', true);
        // El texto sale interpolado con el contacto real.
        $response->assertJsonPath('steps.0.detail', 'Hola Ana');
        $response->assertJsonPath('steps.1.status', 'yes');
        $response->assertJsonPath('steps.2.detail', 'VIP');
        // La rama no tomada se muestra, pero marcada como no ejecutada.
        $response->assertJsonPath('steps.3.status', 'skipped');

        // Nada de esto ocurrió de verdad.
        $this->assertSame(0, Message::count());
        $this->assertFalse($this->contact->tags()->exists());
        Http::assertNothingSent();
    }

    public function test_la_simulacion_avisa_cuando_el_disparador_no_coincide(): void
    {
        $this->actingAs($this->user)->postJson(route('automations.simulate'), [
            'trigger_type' => 'keyword',
            'trigger_config' => ['keywords' => ['precio']],
            'message_text' => 'buenas tardes',
            'steps' => [['type' => 'send_message', 'config' => ['text' => 'Hola'], 'children_yes' => [], 'children_no' => []]],
        ])->assertOk()
            ->assertJsonPath('trigger.matched', false)
            ->assertJsonPath('steps', []);
    }

    public function test_la_simulacion_marca_como_posterior_lo_que_va_tras_una_espera(): void
    {
        $this->actingAs($this->user)->postJson(route('automations.simulate'), [
            'trigger_type' => 'new_contact',
            'message_text' => 'hola',
            'steps' => [
                ['type' => 'wait', 'config' => ['minutes' => 1440], 'children_yes' => [], 'children_no' => []],
                ['type' => 'send_message', 'config' => ['text' => 'Seguimiento'], 'children_yes' => [], 'children_no' => []],
            ],
        ])->assertOk()
            ->assertJsonPath('steps.0.status', 'wait')
            ->assertJsonPath('steps.0.detail', 'Esperar 1 día')
            ->assertJsonPath('steps.1.status', 'later');
    }

    public function test_la_simulacion_senala_los_pasos_incompletos(): void
    {
        $this->actingAs($this->user)->postJson(route('automations.simulate'), [
            'trigger_type' => 'inbound_message',
            'message_text' => 'hola',
            'steps' => [
                ['type' => 'send_message', 'config' => ['text' => '  '], 'children_yes' => [], 'children_no' => []],
                ['type' => 'add_tag', 'config' => [], 'children_yes' => [], 'children_no' => []],
                ['type' => 'webhook', 'config' => ['url' => 'no-es-url'], 'children_yes' => [], 'children_no' => []],
            ],
        ])->assertOk()
            ->assertJsonPath('steps.0.status', 'error')
            ->assertJsonPath('steps.1.status', 'error')
            ->assertJsonPath('steps.2.status', 'error');
    }

    public function test_la_simulacion_no_alcanza_contactos_de_otra_cuenta(): void
    {
        $otherUser = User::create(['name' => 'Otro', 'email' => 'otro@test.com', 'password' => bcrypt('x')]);
        $otherAccount = Account::create(['name' => 'Otra', 'owner_user_id' => $otherUser->id]);
        $foreign = Contact::create([
            'account_id' => $otherAccount->id,
            'phone' => '584125559999',
            'name' => 'Ajeno',
        ]);

        // El contacto ajeno se ignora: el texto queda sin interpolar.
        $this->actingAs($this->user)->postJson(route('automations.simulate'), [
            'trigger_type' => 'inbound_message',
            'message_text' => 'hola',
            'contact_id' => $foreign->id,
            'steps' => [['type' => 'send_message', 'config' => ['text' => 'Hola {name}'], 'children_yes' => [], 'children_no' => []]],
        ])->assertOk()
            ->assertJsonPath('steps.0.detail', 'Hola {name}');
    }

    public function test_la_simulacion_rechaza_tipos_de_paso_invalidos(): void
    {
        $this->actingAs($this->user)->postJson(route('automations.simulate'), [
            'trigger_type' => 'inbound_message',
            'steps' => [['type' => 'borrar_todo', 'config' => []]],
        ])->assertStatus(422);
    }
}
