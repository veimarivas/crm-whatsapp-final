<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AiConfig;
use App\Models\User;
use App\Services\Ai\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * El nombre del modelo en Gemini.
 *
 * Su catálogo devuelve los ids como `models/gemini-2.5-flash`, pero en el chat
 * se escribe sin ese prefijo — o al revés, según de dónde se copie. Un 404 por
 * ese detalle de formato deja al cliente sin respuesta, y desde afuera se ve
 * igual que cualquier otra falla.
 */
class ModelNameFormatTest extends TestCase
{
    use RefreshDatabase;

    private function config(string $model): AiConfig
    {
        $owner = User::create(['name' => 'Admin', 'email' => 'a'.mt_rand().'@test.com', 'password' => bcrypt('x')]);
        $account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $account->id, 'account_role' => User::ROLE_OWNER]);

        return AiConfig::create([
            'account_id' => $account->id,
            'provider' => 'gemini',
            'model' => $model,
            'api_key' => 'AIza_test',
            'is_active' => true,
        ]);
    }

    public function test_si_falla_sin_prefijo_se_reintenta_con_el(): void
    {
        Http::fakeSequence()
            ->push(['error' => ['message' => 'models/gemini-2.5-flash-lite is not found']], 404)
            ->push(['choices' => [['message' => ['content' => 'Tenemos 11 programas.']]]], 200);

        $reply = Client::for($this->config('gemini-2.5-flash-lite'))
            ->chat([['role' => 'user', 'content' => 'hola']], null, 300);

        $this->assertSame('Tenemos 11 programas.', $reply);

        Http::assertSent(fn ($request) => $request['model'] === 'models/gemini-2.5-flash-lite');
    }

    public function test_si_falla_con_prefijo_se_reintenta_sin_el(): void
    {
        Http::fakeSequence()
            ->push(['error' => ['message' => 'not found']], 404)
            ->push(['choices' => [['message' => ['content' => 'ok']]]], 200);

        $reply = Client::for($this->config('models/gemini-2.5-flash-lite'))
            ->chat([['role' => 'user', 'content' => 'hola']], null, 300);

        $this->assertSame('ok', $reply);

        Http::assertSent(fn ($request) => $request['model'] === 'gemini-2.5-flash-lite');
    }

    public function test_si_el_modelo_no_existe_de_ninguna_forma_el_error_lo_explica(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['message' => 'models/inventado is not found for API version v1beta'],
        ], 404)]);

        try {
            Client::for($this->config('inventado'))->chat([['role' => 'user', 'content' => 'hola']], null, 300);
            $this->fail('Tenía que lanzar.');
        } catch (RuntimeException $e) {
            // «HTTP 404» a secas no dice nada; el proveedor explica cuál no
            // encontró y ese texto es el que resuelve el problema.
            $this->assertStringContainsString('is not found', $e->getMessage());
            $this->assertStringContainsString('404', $e->getMessage());
        }
    }
}
