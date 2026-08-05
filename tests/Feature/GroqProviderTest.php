<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AiConfig;
use App\Models\User;
use App\Services\Ai\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Groq como proveedor.
 *
 * Habla el mismo protocolo que OpenAI, así que lo que hay que fijar no es el
 * formato sino lo que se rompe en la práctica: que apunte al host correcto,
 * que el 429 del plan gratuito se explique como límite de uso —y no como un
 * problema de la API key— y que «alcanzable» signifique que la clave sirve de
 * verdad, no que el campo esté lleno.
 */
class GroqProviderTest extends TestCase
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
            'provider' => 'groq',
            'model' => 'llama-3.3-70b-versatile',
            'api_key' => 'gsk_test',
            'is_active' => true,
        ]);
    }

    public function test_habla_con_el_endpoint_de_groq(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'Hola']]]])]);

        $reply = Client::for($this->config)->chat([['role' => 'user', 'content' => 'hola']], 'Sos un asistente', 200);

        $this->assertSame('Hola', $reply);

        Http::assertSent(function ($request) {
            $this->assertStringContainsString('api.groq.com', $request->url());
            $this->assertSame('llama-3.3-70b-versatile', $request['model']);
            $this->assertSame(200, $request['max_tokens']);
            // El system va primero, como en OpenAI.
            $this->assertSame('system', $request['messages'][0]['role']);

            return true;
        });
    }

    public function test_el_limite_del_plan_gratuito_se_explica_como_tal(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Rate limit reached']], 429)]);

        $this->expectExceptionMessageMatches('/límite de uso/');

        Client::for($this->config)->chat([['role' => 'user', 'content' => 'hola']], null, 200);
    }

    public function test_una_clave_invalida_se_reporta_como_inalcanzable(): void
    {
        // Con la nube no alcanza con mirar si hay clave: puede estar revocada
        // o agotada, y eso solo se sabe preguntando.
        Http::fake(['*/models' => Http::response(['error' => 'invalid_api_key'], 401)]);

        $this->assertFalse(Client::for($this->config)->isReachable());
    }

    public function test_una_clave_valida_se_reporta_alcanzable(): void
    {
        Http::fake(['*/models' => Http::response(['data' => [['id' => 'llama-3.3-70b-versatile']]])]);

        $this->assertTrue(Client::for($this->config)->isReachable());
    }

    public function test_lista_los_modelos_disponibles(): void
    {
        Http::fake(['*/models' => Http::response(['data' => [
            ['id' => 'llama-3.3-70b-versatile'],
            ['id' => 'qwen-2.5-32b'],
        ]])]);

        $this->artisan('wacrm:ai-models')
            ->expectsOutputToContain('qwen-2.5-32b')
            ->assertSuccessful();
    }

    public function test_se_puede_probar_una_clave_antes_de_guardarla(): void
    {
        Http::fake(['*/models' => Http::response(['data' => [['id' => 'llama-3.3-70b-versatile']]])]);

        // Sirve para saber si la clave anda sin dejarla configurada.
        $this->artisan('wacrm:ai-models', ['--provider' => 'groq', '--key' => 'gsk_otra'])
            ->expectsOutputToContain('llama-3.3-70b-versatile')
            ->assertSuccessful();
    }
}
