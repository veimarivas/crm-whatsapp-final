<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AiConfig;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `/api/v1/ai/status`: lo que Komo consulta en cada render.
 *
 * Si este endpoint tira un 500, Komo pinta «Sin conexión» — que manda a
 * revisar la red cuando el problema está del otro lado. Cualquier fallo tiene
 * que llegar descrito y con 200, para que el cartel diga la verdad.
 */
class AiStatusEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create(['name' => 'Admin', 'email' => 'a@test.com', 'password' => bcrypt('x')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        // Por el mismo camino que la UI: replicar el hasheo a mano en el test
        // lo deja atado a un detalle interno que ya vive en el modelo.
        [, $this->key] = ApiKey::issue($this->account->id, $owner->id, 'Komo', ['conversations:read']);
    }

    private function consultar(): array
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->key)
            ->getJson('/api/v1/ai/status')
            ->assertOk()
            ->json();
    }

    public function test_sin_ia_configurada_lo_dice(): void
    {
        $this->assertSame('not_configured', $this->consultar()['reason']);
    }

    public function test_con_ollama_arriba_reporta_disponible(): void
    {
        AiConfig::create([
            'account_id' => $this->account->id,
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'base_url' => 'http://127.0.0.1:11434',
            'is_active' => true,
            'auto_reply_enabled' => true,
        ]);

        Http::fake(['*/api/tags' => Http::response(['models' => []])]);

        $data = $this->consultar();

        $this->assertTrue($data['available']);
        $this->assertSame('qwen2.5:7b', $data['model']);
    }

    public function test_con_ollama_caido_reporta_el_proveedor_y_no_un_error_de_red(): void
    {
        AiConfig::create([
            'account_id' => $this->account->id,
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'base_url' => 'http://127.0.0.1:11434',
            'is_active' => true,
            'auto_reply_enabled' => true,
        ]);

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'));

        $this->assertSame('provider_down', $this->consultar()['reason']);
    }

    public function test_un_error_interno_vuelve_descrito_y_no_como_500(): void
    {
        // Se llama al controlador sin el contexto de cuenta que pone el
        // middleware: es la forma honesta de provocar un fallo interno sin
        // inventar un dato corrupto que en realidad no rompe nada.
        //
        // Lo que se fija: ante CUALQUIER fallo acá adentro, la respuesta sigue
        // siendo 200 con el motivo descrito. Con un 500, Komo pintaba «Sin
        // conexión» y mandaba a revisar la red teniendo el problema del otro
        // lado.
        $response = (new \App\Http\Controllers\Api\V1\AiStatusApiController())
            ->show(new \Illuminate\Http\Request());

        $data = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('status_error', $data['reason']);
        $this->assertFalse($data['available']);
        $this->assertArrayHasKey('detail', $data);
    }
}
