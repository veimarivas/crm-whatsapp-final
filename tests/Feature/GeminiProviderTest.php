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
 * Google Gemini como proveedor.
 *
 * Entra por la capa compatible con OpenAI que expone Google, así que comparte
 * camino con Groq. Lo que se fija acá es lo que los diferencia: el host, y que
 * NO se le manden los parámetros de razonamiento —son de Groq y a otro
 * proveedor solo le sacan un 400 por algo que no necesita—.
 */
class GeminiProviderTest extends TestCase
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
            'provider' => 'gemini',
            'model' => 'gemini-2.0-flash',
            'api_key' => 'AIza_test',
            'is_active' => true,
        ]);
    }

    public function test_habla_con_el_endpoint_de_google(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'Hola']]]])]);

        $reply = Client::for($this->config)->chat([['role' => 'user', 'content' => 'hola']], 'Sos un asistente', 300);

        $this->assertSame('Hola', $reply);

        Http::assertSent(function ($request) {
            $this->assertStringContainsString('generativelanguage.googleapis.com', $request->url());
            $this->assertSame('gemini-2.0-flash', $request['model']);

            return true;
        });
    }

    public function test_no_le_manda_los_parametros_de_razonamiento_de_groq(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        Client::for($this->config)->chat([['role' => 'user', 'content' => 'hola']], null, 300);

        Http::assertSent(function ($request) {
            // Pedirle `reasoning_format` a quien no lo entiende es buscarse un
            // 400 por una optimización que ni siquiera necesita.
            $this->assertArrayNotHasKey('reasoning_format', $request->data());
            $this->assertArrayNotHasKey('reasoning_effort', $request->data());

            return true;
        });
    }

    public function test_su_limite_de_uso_tambien_se_trata_como_espera(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Quota exceeded']], 429)]);

        $this->expectException(RateLimitedException::class);

        Client::for($this->config)->chat([['role' => 'user', 'content' => 'hola']], null, 300);
    }

    public function test_lista_sus_modelos_y_valida_la_clave(): void
    {
        Http::fake(['*/models' => Http::response(['data' => [
            ['id' => 'gemini-2.0-flash'],
            ['id' => 'gemini-2.5-flash'],
        ]])]);

        $this->assertTrue(Client::for($this->config)->isReachable());

        $this->artisan('wacrm:ai-models', ['--provider' => 'gemini', '--key' => 'AIza_otra'])
            ->expectsOutputToContain('gemini-2.5-flash')
            ->assertSuccessful();
    }

    public function test_una_clave_invalida_se_reporta_como_inalcanzable(): void
    {
        Http::fake(['*/models' => Http::response(['error' => 'API key not valid'], 400)]);

        $this->assertFalse(Client::for($this->config)->isReachable());
    }
}
