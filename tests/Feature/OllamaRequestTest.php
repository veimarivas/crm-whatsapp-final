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
 * Qué se le manda a Ollama en cada consulta.
 *
 * El caso real: `/api/tags` respondía al instante y `/api/chat` moría en el
 * timeout con 0 bytes recibidos. El proceso estaba vivo; lo que no terminaba
 * era la inferencia. Dos causas, y las dos se pedían desde acá:
 *
 *  - `num_ctx` fijo en 16384 para todo, incluso para «hola»: reserva caché de
 *    atención y hace más lenta cada consulta.
 *  - Sin `keep_alive`, Ollama descarga el modelo a los 5 minutos y el
 *    siguiente cliente paga la recarga entera dentro de su propio timeout.
 */
class OllamaRequestTest extends TestCase
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
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'base_url' => 'http://127.0.0.1:11434',
            'is_active' => true,
        ]);

        Http::fake(['*/api/chat' => Http::response(['message' => ['content' => 'ok']])]);
    }

    /** @return array<string, mixed> el payload que se envió */
    private function pedir(string $texto, ?string $system = null): array
    {
        Client::for($this->config)->chat([['role' => 'user', 'content' => $texto]], $system, 800);

        $enviado = [];
        Http::assertSent(function ($request) use (&$enviado) {
            $enviado = $request->data();

            return true;
        });

        return $enviado;
    }

    public function test_una_pregunta_corta_no_reserva_el_contexto_maximo(): void
    {
        $payload = $this->pedir('hola, tienen cursos?');

        $this->assertSame(4096, $payload['options']['num_ctx'],
            'Pedir 16k de contexto para cinco palabras hace más lenta cada consulta.');
    }

    public function test_un_prompt_grande_pide_mas_contexto(): void
    {
        // ~30.000 caracteres: el catálogo completo con historial.
        $payload = $this->pedir('dame los horarios', str_repeat('Programa con módulos y horarios. ', 950));

        $this->assertGreaterThanOrEqual(16384, $payload['options']['num_ctx'],
            'Quedarse corto es peor: Ollama trunca en silencio y se lleva las reglas.');
    }

    public function test_el_contexto_nunca_pasa_del_techo_configurado(): void
    {
        config(['services.ollama.max_ctx' => 8192]);

        $payload = $this->pedir('dame los horarios', str_repeat('Programa con módulos y horarios. ', 2000));

        $this->assertSame(8192, $payload['options']['num_ctx']);
    }

    public function test_pide_que_el_modelo_quede_en_memoria(): void
    {
        $payload = $this->pedir('hola');

        // Sin esto, cada pausa de 5 minutos entre clientes obliga a recargar el
        // modelo, y esa recarga se come el timeout del que preguntó.
        $this->assertSame('30m', $payload['keep_alive']);
    }

    public function test_el_timeout_sale_de_la_configuracion(): void
    {
        config(['services.ollama.timeout' => 240]);

        // 120 s fijos eran pocos para un modelo frío en un VPS sin GPU.
        $this->assertSame(240, (int) config('services.ollama.timeout'));

        $this->pedir('hola'); // no debe romper con el valor nuevo
    }
}
