<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AiConfig;
use App\Models\User;
use App\Services\Ai\Client;
use App\Services\Ai\RateLimitedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OpenRouter como proveedor.
 *
 * Enruta a decenas de proveedores detrás de la API de OpenAI, así que comparte
 * camino con Groq y Gemini. Lo propio suyo son los ids con barra y sufijo
 * (`nvidia/nemotron-nano-9b-v2:free`) y un catálogo de cientos de modelos, que
 * sin filtro es ilegible justo cuando hay que elegir uno.
 */
class OpenRouterProviderTest extends TestCase
{
    use RefreshDatabase;

    private AiConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create(['name' => 'Admin', 'email' => 'a@test.com', 'password' => bcrypt('x')]);
        $account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $account->id, 'account_role' => User::ROLE_OWNER]);

        $this->config = AiConfig::create([
            'account_id' => $account->id,
            'provider' => 'openrouter',
            'model' => 'nvidia/nemotron-nano-9b-v2:free',
            'api_key' => 'sk-or-test',
            'is_active' => true,
        ]);
    }

    public function test_habla_con_el_endpoint_de_openrouter(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'Hola']]]])]);

        $reply = Client::for($this->config)->chat([['role' => 'user', 'content' => 'hola']], 'Reglas', 300);

        $this->assertSame('Hola', $reply);

        Http::assertSent(function ($request) {
            $this->assertStringContainsString('openrouter.ai/api/v1', $request->url());
            // El id va tal cual, con barra y con el sufijo :free.
            $this->assertSame('nvidia/nemotron-nano-9b-v2:free', $request['model']);

            return true;
        });
    }

    public function test_no_le_manda_los_parametros_de_razonamiento_de_groq(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        Client::for($this->config)->chat([['role' => 'user', 'content' => 'hola']], null, 300);

        Http::assertSent(function ($request) {
            $this->assertArrayNotHasKey('reasoning_format', $request->data());

            return true;
        });
    }

    public function test_su_limite_tambien_es_espera_y_no_falla(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Rate limit exceeded']], 429)]);

        $this->expectException(RateLimitedException::class);

        Client::for($this->config)->chat([['role' => 'user', 'content' => 'hola']], null, 300);
    }

    public function test_el_listado_se_puede_filtrar_por_gratuitos(): void
    {
        Http::fake(['*/models' => Http::response(['data' => [
            ['id' => 'anthropic/claude-3.5-sonnet'],
            ['id' => 'nvidia/nemotron-nano-9b-v2:free'],
            ['id' => 'openai/gpt-oss-20b:free'],
        ]])]);

        // Con cientos de modelos, listarlos todos no sirve para elegir uno.
        $this->artisan('wacrm:ai-models', ['--filter' => 'free'])
            ->expectsOutputToContain('nemotron-nano-9b-v2:free')
            ->doesntExpectOutputToContain('claude-3.5-sonnet')
            ->assertSuccessful();
    }

    public function test_avisa_si_el_filtro_no_deja_nada(): void
    {
        Http::fake(['*/models' => Http::response(['data' => [['id' => 'anthropic/claude-3.5-sonnet']]])]);

        $this->artisan('wacrm:ai-models', ['--filter' => 'free'])
            ->expectsOutputToContain('Ninguno')
            ->assertFailed();
    }
}
