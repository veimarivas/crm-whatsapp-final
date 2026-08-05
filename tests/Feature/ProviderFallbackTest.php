<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AiConfig;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\RateLimitedException;
use App\Services\Ai\ReplyGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Respaldo cuando el proveedor principal se queda sin cuota.
 *
 * Los planes gratuitos se agotan, y cuando eso pasa el cliente no tiene la
 * culpa. Con un respaldo local (Ollama) la conversación sigue: más lento, pero
 * responde. Vale más una respuesta lenta que un cliente esperando a que se
 * reponga una cuota.
 */
class ProviderFallbackTest extends TestCase
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
            'provider' => 'gemini',
            'model' => 'gemini-2.0-flash',
            'api_key' => 'AIza_test',
            'is_active' => true,
        ]);

        $contact = Contact::create(['account_id' => $account->id, 'name' => 'Ana', 'phone' => '5917000', 'phone_normalized' => '5917000']);
        $this->conversation = Conversation::create(['account_id' => $account->id, 'contact_id' => $contact->id, 'status' => 'open']);

        Message::create([
            'account_id' => $account->id,
            'conversation_id' => $this->conversation->id,
            'sender_type' => Message::SENDER_CUSTOMER,
            'content_type' => 'text',
            'content_text' => 'que programas ofrecen?',
        ]);
    }

    public function test_con_respaldo_configurado_responde_igual(): void
    {
        config([
            'services.ai_context.fallback_provider' => 'ollama',
            'services.ai_context.fallback_model' => 'qwen2.5:7b',
        ]);

        Http::fake([
            // El principal dice "sin cuota"…
            '*googleapis.com*' => Http::response(['error' => ['message' => 'Quota exceeded']], 429),
            // …y el respaldo local contesta.
            '*11434*' => Http::response(['message' => ['content' => 'Tenemos 11 programas abiertos.']]),
        ]);

        $reply = app(ReplyGenerator::class)->generate($this->config, $this->conversation);

        $this->assertSame('Tenemos 11 programas abiertos.', $reply);
    }

    public function test_sin_respaldo_sube_el_error_para_que_el_job_reintente(): void
    {
        config(['services.ai_context.fallback_provider' => null]);

        Http::fake(['*' => Http::response(['error' => ['message' => 'Quota exceeded']], 429)]);

        // Sin respaldo NO se inventa una respuesta: el job la reencola para
        // más tarde, que es lo correcto cuando la cuota se repone sola.
        $this->expectException(RateLimitedException::class);

        app(ReplyGenerator::class)->generate($this->config, $this->conversation);
    }

    public function test_el_error_del_proveedor_llega_completo(): void
    {
        config(['services.ai_context.fallback_provider' => null]);

        Http::fake(['*' => Http::response([
            'error' => ['message' => 'Quota exceeded for quota metric ... limit: 0'],
        ], 429)]);

        try {
            app(ReplyGenerator::class)->generate($this->config, $this->conversation);
            $this->fail('Tenía que lanzar RateLimitedException.');
        } catch (RateLimitedException $e) {
            // «limit: 0» significa que ese modelo no tiene cupo gratuito en el
            // proyecto, no que se haya gastado la cuota. Taparlo con un texto
            // propio hacía perder esa diferencia, que se arregla distinto.
            $this->assertStringContainsString('limit: 0', $e->getMessage());
        }
    }

    public function test_el_respaldo_no_puede_ser_el_mismo_proveedor(): void
    {
        config(['services.ai_context.fallback_provider' => 'gemini']);

        Http::fake(['*' => Http::response(['error' => ['message' => 'Quota exceeded']], 429)]);

        // Reintentar contra el mismo que acaba de decir "sin cuota" es gastar
        // una llamada para recibir el mismo 429.
        $this->expectException(RateLimitedException::class);

        app(ReplyGenerator::class)->generate($this->config, $this->conversation);
    }
}
