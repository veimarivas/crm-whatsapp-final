<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Flow;
use App\Models\FlowRun;
use App\Models\Message;
use App\Models\Tag;
use App\Models\User;
use App\Models\WhatsappConfig;
use App\Services\Flows\Recipes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Plantillas de chatbot y simulador de conversación.
 */
class FlowBuilderTest extends TestCase
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

    /** Grafo de prueba: pregunta con botones → captura un dato → fin. */
    private function graph(): array
    {
        return [
            ['node_key' => 'menu', 'node_type' => 'send_buttons', 'config' => [
                'text' => 'Hola {name}, ¿qué necesitas?',
                'buttons' => [
                    ['id' => 'inscribir', 'title' => 'Inscribirme', 'next' => 'pedir_correo'],
                    ['id' => 'asesor', 'title' => 'Hablar con asesor', 'next' => 'pasar'],
                ],
            ]],
            ['node_key' => 'pedir_correo', 'node_type' => 'collect_input', 'config' => [
                'text' => '¿Cuál es tu correo?',
                'var' => 'correo',
                'next' => 'fin',
            ]],
            ['node_key' => 'fin', 'node_type' => 'end', 'config' => ['message' => 'Te escribimos a {correo}.']],
            ['node_key' => 'pasar', 'node_type' => 'handoff', 'config' => ['message' => 'Ya te contactan.']],
        ];
    }

    private function simulate(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->postJson(route('flows.simulate'), array_merge([
            'entry_node_id' => 'menu',
            'fallback_policy' => ['max_reprompts' => 1, 'on_exhaust' => 'handoff'],
            'nodes' => $this->graph(),
            'replies' => [],
            'contact_id' => $this->contact->id,
        ], $overrides));
    }

    public function test_el_listado_trae_las_plantillas_y_el_primer_mensaje(): void
    {
        $this->actingAs($this->user)->post(route('flows.store'), [
            'name' => 'Mi bot',
            'recipe' => 'preguntas-frecuentes',
        ])->assertRedirect();

        $this->actingAs($this->user)
            ->get(route('flows.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Flows/Index')
                ->has('recipes', count(Recipes::all()))
                ->where('flows.0.name', 'Mi bot')
                ->where('flows.0.entry_missing', false)
                // El texto del nodo de entrada se muestra como vista previa.
                ->where('flows.0.entry_text', '{name}, elige tu consulta y te respondo al instante:'));
    }

    public function test_crear_desde_una_plantilla_siembra_su_grafo(): void
    {
        $this->actingAs($this->user)->post(route('flows.store'), [
            'name' => 'Captura',
            'recipe' => 'capturar-datos',
        ])->assertRedirect();

        $flow = Flow::first();
        $recipe = Recipes::find('capturar-datos');

        $this->assertSame('draft', $flow->status);
        $this->assertSame($recipe['entry_node_id'], $flow->entry_node_id);
        $this->assertSame(count($recipe['nodes']), $flow->nodes()->count());
    }

    public function test_sin_plantilla_cae_en_la_de_menu(): void
    {
        $this->actingAs($this->user)->post(route('flows.store'), ['name' => 'Sin receta'])->assertRedirect();

        $this->assertSame(Recipes::find(Recipes::DEFAULT)['entry_node_id'], Flow::first()->entry_node_id);
    }

    public function test_plantilla_inexistente_es_rechazada(): void
    {
        $this->actingAs($this->user)
            ->post(route('flows.store'), ['name' => 'X', 'recipe' => 'no-existe'])
            ->assertSessionHasErrors('recipe');
    }

    public function test_la_simulacion_arranca_en_el_nodo_de_entrada_e_interpola(): void
    {
        $this->simulate()->assertOk()
            ->assertJsonPath('status', 'awaiting')
            ->assertJsonPath('transcript.0.from', 'bot')
            ->assertJsonPath('transcript.0.kind', 'buttons')
            ->assertJsonPath('transcript.0.text', 'Hola Ana, ¿qué necesitas?')
            ->assertJsonPath('awaiting.options.0.title', 'Inscribirme');
    }

    public function test_conversacion_completa_captura_variables_y_no_envia_nada(): void
    {
        Http::fake();

        $response = $this->simulate(['replies' => ['inscribir', 'ana@test.com']])->assertOk();

        $response->assertJsonPath('status', 'ended');
        $response->assertJsonPath('vars.correo', 'ana@test.com');
        // El mensaje final usa la variable capturada.
        $transcript = collect($response->json('transcript'));
        $this->assertTrue($transcript->contains(fn ($e) => $e['text'] === 'Te escribimos a ana@test.com.'));

        // Nada de esto ocurrió de verdad.
        $this->assertSame(0, Message::count());
        $this->assertSame(0, FlowRun::count());
        Http::assertNothingSent();
    }

    public function test_el_boton_tambien_matchea_por_su_texto_visible(): void
    {
        // Un cliente que escribe en vez de tocar el botón: misma regla que el Runner.
        $this->simulate(['replies' => ['Hablar con asesor']])->assertOk()
            ->assertJsonPath('status', 'handoff');
    }

    public function test_respuesta_desconocida_repregunta_y_luego_pasa_a_agente(): void
    {
        $response = $this->simulate(['replies' => ['cualquier cosa', 'otra cosa']])->assertOk();

        $response->assertJsonPath('status', 'handoff');
        $notes = collect($response->json('transcript'))->where('from', 'system')->pluck('text');
        $this->assertTrue($notes->contains(fn ($t) => str_contains($t, 'Reintento 1 de 1')));
        $this->assertTrue($notes->contains(fn ($t) => str_contains($t, 'Se agotaron los reintentos')));
    }

    public function test_la_simulacion_avisa_de_una_conexion_rota(): void
    {
        $nodes = $this->graph();
        $nodes[0]['config']['buttons'][0]['next'] = 'nodo_borrado';

        $this->simulate(['nodes' => $nodes, 'replies' => ['inscribir']])->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error', 'El nodo «nodo_borrado» no existe. Revisa a dónde apunta la conexión anterior.');
    }

    public function test_la_simulacion_avisa_si_el_nodo_de_entrada_no_existe(): void
    {
        $this->simulate(['entry_node_id' => 'fantasma'])->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('transcript', []);
    }

    public function test_la_simulacion_corta_los_ciclos(): void
    {
        $this->simulate([
            'entry_node_id' => 'a',
            'nodes' => [
                ['node_key' => 'a', 'node_type' => 'send_message', 'config' => ['text' => 'uno', 'next' => 'b']],
                ['node_key' => 'b', 'node_type' => 'send_message', 'config' => ['text' => 'dos', 'next' => 'a']],
            ],
        ])->assertOk()->assertJsonPath('status', 'failed');
    }

    public function test_la_simulacion_no_llama_a_la_api_externa_ni_etiqueta_de_verdad(): void
    {
        Http::fake();
        $tag = Tag::create(['account_id' => $this->account->id, 'name' => 'VIP']);

        $response = $this->simulate([
            'entry_node_id' => 'etiquetar',
            'replies' => [],
            'nodes' => [
                ['node_key' => 'etiquetar', 'node_type' => 'set_tag', 'config' => ['tag_id' => $tag->id, 'next' => 'consultar']],
                ['node_key' => 'consultar', 'node_type' => 'http_fetch', 'config' => ['url' => 'https://api.test/x', 'var' => 'dato', 'next' => 'fin']],
                ['node_key' => 'fin', 'node_type' => 'end', 'config' => ['message' => 'listo']],
            ],
        ])->assertOk();

        $response->assertJsonPath('status', 'ended');
        $notes = collect($response->json('transcript'))->where('from', 'system')->pluck('text');
        $this->assertTrue($notes->contains(fn ($t) => str_contains($t, 'Se etiquetaría al contacto con «VIP»')));

        Http::assertNothingSent();
        $this->assertFalse($this->contact->tags()->exists());
    }

    public function test_la_simulacion_rechaza_tipos_de_nodo_invalidos(): void
    {
        $this->simulate([
            'nodes' => [['node_key' => 'x', 'node_type' => 'borrar_todo', 'config' => []]],
            'entry_node_id' => 'x',
        ])->assertStatus(422);
    }

    public function test_la_simulacion_ignora_contactos_de_otra_cuenta(): void
    {
        $otherUser = User::create(['name' => 'Otro', 'email' => 'otro@test.com', 'password' => bcrypt('x')]);
        $otherAccount = Account::create(['name' => 'Otra', 'owner_user_id' => $otherUser->id]);
        $foreign = Contact::create([
            'account_id' => $otherAccount->id,
            'phone' => '584125559999',
            'name' => 'Ajeno',
        ]);

        $this->simulate(['contact_id' => $foreign->id])->assertOk()
            ->assertJsonPath('transcript.0.text', 'Hola {name}, ¿qué necesitas?');
    }
}
