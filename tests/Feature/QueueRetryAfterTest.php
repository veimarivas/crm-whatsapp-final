<?php

namespace Tests\Feature;

use App\Jobs\AiAutoReplyJob;
use App\Models\Account;
use App\Models\AiConfig;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `retry_after` de la cola contra el timeout del job.
 *
 * La trampa que se llevó puestas las respuestas durante días: con
 * `retry_after = 90` (el default de Laravel) y una respuesta de IA que puede
 * tardar 150 s, la cola daba el job por abandonado a los 90 s y lo volvía a
 * repartir MIENTRAS SEGUÍA CORRIENDO.
 *
 * Eso producía las dos cosas que se veían en producción a la vez: el cliente
 * recibía la respuesta dos veces, y el job moría con
 * `MaxAttemptsExceededException` porque tiene `tries = 1`.
 *
 * Es un problema de configuración, no de código: por eso hay un test que lo
 * fija y un chequeo en el diagnóstico.
 */
class QueueRetryAfterTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_cola_espera_mas_de_lo_que_puede_tardar_la_ia(): void
    {
        // `database` explícitamente: es la conexión de producción. En los
        // tests la cola es `sync`, que no tiene `retry_after` — ahí el job
        // corre en el acto y nadie lo puede repartir dos veces.
        $retryAfter = (int) config('queue.connections.database.retry_after');

        $job = new AiAutoReplyJob('cualquiera');

        // Si esto se invierte, vuelven las respuestas duplicadas.
        $this->assertGreaterThan(
            $job->timeout,
            $retryAfter,
            "retry_after ({$retryAfter}s) debe superar al timeout del job ({$job->timeout}s).",
        );
    }

    public function test_el_diagnostico_avisa_si_la_configuracion_esta_invertida(): void
    {
        $owner = User::create(['name' => 'Admin', 'email' => 'a@test.com', 'password' => bcrypt('x')]);
        $account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $account->id, 'account_role' => User::ROLE_OWNER]);

        AiConfig::create([
            'account_id' => $account->id,
            'provider' => 'groq',
            'model' => 'qwen/qwen3.6-27b',
            'api_key' => 'gsk_test',
            'is_active' => true,
            'auto_reply_enabled' => true,
        ]);

        $contact = Contact::create(['account_id' => $account->id, 'name' => 'Ana', 'phone' => '5917000', 'phone_normalized' => '5917000']);
        $conversation = Conversation::create(['account_id' => $account->id, 'contact_id' => $contact->id, 'status' => 'open']);

        Message::create([
            'account_id' => $account->id,
            'conversation_id' => $conversation->id,
            'sender_type' => Message::SENDER_CUSTOMER,
            'content_type' => 'text',
            'content_text' => 'hola',
        ]);

        Http::fake(['*/models' => Http::response(['data' => []])]);

        config(['queue.connections.'.config('queue.default').'.retry_after' => 90]);

        $this->artisan('wacrm:ai-doctor', ['--conversation' => $conversation->id, '--skip-worker' => true])
            ->expectsOutputToContain('retry_after')
            ->assertFailed();
    }
}
