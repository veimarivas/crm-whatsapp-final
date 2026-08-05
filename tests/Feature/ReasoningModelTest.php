<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AiConfig;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\Client;
use App\Services\Ai\ReplyGenerator;
use App\Services\Ai\ReplySanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Modelos que "piensan" antes de responder (qwen3, gpt-oss…).
 *
 * Escriben su deliberación en `<think>…</think>`: en inglés, larguísima, y
 * exactamente lo que el cliente NO tiene que ver. Peor todavía: se comen el
 * presupuesto de tokens pensando y la respuesta real nunca llega a
 * escribirse — desde afuera se ve como una IA que no contesta.
 */
class ReasoningModelTest extends TestCase
{
    use RefreshDatabase;

    private AiConfig $config;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create(['name' => 'Admin', 'email' => 'a@test.com', 'password' => bcrypt('x')]);
        $account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $account->id, 'account_role' => User::ROLE_OWNER]);

        $this->config = AiConfig::create([
            'account_id' => $account->id,
            'provider' => 'groq',
            'model' => 'qwen/qwen3.6-27b',
            'api_key' => 'gsk_test',
            'is_active' => true,
        ]);

        $contact = Contact::create(['account_id' => $account->id, 'name' => 'Ana', 'phone' => '5917000', 'phone_normalized' => '5917000']);
        $this->conversation = Conversation::create(['account_id' => $account->id, 'contact_id' => $contact->id, 'status' => 'open']);

        Message::create([
            'account_id' => $account->id,
            'conversation_id' => $this->conversation->id,
            'sender_type' => Message::SENDER_CUSTOMER,
            'content_type' => 'text',
            'content_text' => 'que programas del area de salud tienen?',
        ]);
    }

    public function test_el_razonamiento_no_llega_al_cliente(): void
    {
        $conThink = "<think>\nHere's a thinking process:\n1. Analyze User Input\n2. Check rules\n</think>\n\nTenemos 2 programas del área de salud.";

        $this->assertSame(
            'Tenemos 2 programas del área de salud.',
            (new ReplySanitizer())->clean($conThink),
        );
    }

    public function test_un_razonamiento_cortado_a_la_mitad_no_se_envia_como_respuesta(): void
    {
        // Se acabaron los tokens pensando: lo que hay no es una respuesta, es
        // media deliberación en inglés.
        $cortado = "<think>\nHere's a thinking process:\n1. Analyze User Input\n- The list contains:";

        $this->assertSame('', (new ReplySanitizer())->clean($cortado));
    }

    public function test_tambien_limpia_cuando_solo_llega_el_cierre(): void
    {
        $this->assertSame(
            'Tenemos 2 programas.',
            (new ReplySanitizer())->clean("Some reasoning…</think>\nTenemos 2 programas."),
        );
    }

    public function test_le_pide_al_proveedor_que_oculte_el_razonamiento(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'Hola']]]])]);

        Client::for($this->config)->chat([['role' => 'user', 'content' => 'hola']], null, 500);

        Http::assertSent(function ($request) {
            $this->assertSame('hidden', $request['reasoning_format']);

            return true;
        });
    }

    public function test_si_el_modelo_no_acepta_ese_parametro_se_reintenta_sin_el(): void
    {
        // No todos lo soportan y responden 400: perder la optimización es
        // aceptable, quedarse sin respuesta no.
        Http::fakeSequence()
            ->push(['error' => ['message' => 'unsupported parameter: reasoning_format']], 400)
            ->push(['choices' => [['message' => ['content' => 'Hola igual']]]], 200);

        $reply = Client::for($this->config)->chat([['role' => 'user', 'content' => 'hola']], null, 500);

        $this->assertSame('Hola igual', $reply);
    }

    public function test_en_la_nube_se_da_mas_margen_de_tokens(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        app(ReplyGenerator::class)->generate($this->config, $this->conversation);

        Http::assertSent(function ($request) {
            // Con el presupuesto de CPU (350) el modelo se quedaba sin tokens
            // a mitad de la deliberación y no llegaba a contestar.
            $this->assertSame(1200, $request['max_tokens']);

            return true;
        });
    }

    public function test_el_prompt_exige_espanol(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        app(ReplyGenerator::class)->generate($this->config, $this->conversation);

        Http::assertSent(function ($request) {
            $system = collect($request['messages'])->firstWhere('role', 'system')['content'] ?? '';

            $this->assertStringContainsString('SIEMPRE EN ESPAÑOL', $system);

            return true;
        });
    }
}
