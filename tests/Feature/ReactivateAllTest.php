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
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Volver a encender lo que se apagó por fallas del proveedor.
 *
 * Cuando Ollama se quedaba sin tiempo, cada conversación que fallaba dos veces
 * se apagaba sola. Al cambiar de proveedor esas conversaciones SIGUEN
 * apagadas: nadie las vuelve a encender, y esos clientes escriben sin que les
 * conteste nadie. Una por una es inviable.
 */
class ReactivateAllTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create(['name' => 'Admin', 'email' => 'a@test.com', 'password' => bcrypt('x')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        AiConfig::create([
            'account_id' => $this->account->id,
            'provider' => 'groq',
            'model' => 'qwen/qwen3.6-27b',
            'api_key' => 'gsk_test',
            'is_active' => true,
            'auto_reply_enabled' => true,
        ]);

        Http::fake(['*/models' => Http::response(['data' => []])]);
    }

    private function conversacion(string $telefono, array $attrs = [], ?string $ultimoDe = null): Conversation
    {
        $contact = Contact::create([
            'account_id' => $this->account->id,
            'name' => 'Cliente '.$telefono,
            'phone' => $telefono,
            'phone_normalized' => $telefono,
        ]);

        $conversation = Conversation::create(array_merge([
            'account_id' => $this->account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ], $attrs));

        if ($ultimoDe) {
            Message::create([
                'account_id' => $this->account->id,
                'conversation_id' => $conversation->id,
                'sender_type' => $ultimoDe,
                'content_type' => 'text',
                'content_text' => 'hola',
            ]);
        }

        return $conversation;
    }

    public function test_reenciende_las_apagadas_por_fallas(): void
    {
        $a = $this->conversacion('59171865104', [
            'ai_autoreply_disabled' => true,
            'ai_failure_count' => 2,
            'ai_disabled_reason' => 'Falló 2 veces seguidas: cURL error 28',
        ]);
        $b = $this->conversacion('59177117771', [
            'ai_autoreply_disabled' => true,
            'ai_failure_count' => 2,
            'ai_disabled_reason' => 'Falló 2 veces seguidas: cURL error 28',
        ]);

        $this->artisan('wacrm:ai-doctor', ['--reactivate-all' => true, '--skip-worker' => true])
            ->assertSuccessful();

        $this->assertFalse($a->refresh()->ai_autoreply_disabled);
        $this->assertFalse($b->refresh()->ai_autoreply_disabled);
        $this->assertSame(0, $a->ai_failure_count);
        $this->assertNull($a->ai_disabled_reason);
    }

    public function test_no_toca_la_que_apago_una_persona(): void
    {
        // Ahí la decisión fue de alguien, no de un error: reencenderla sería
        // pasarle por encima al agente que tomó la conversación.
        $manual = $this->conversacion('59170000001', [
            'ai_autoreply_disabled' => true,
            'ai_failure_count' => 0,
            'ai_disabled_reason' => 'Apagada a mano por Daniel desde el Inbox',
        ]);

        $this->artisan('wacrm:ai-doctor', ['--reactivate-all' => true, '--skip-worker' => true])
            ->assertSuccessful();

        $this->assertTrue($manual->refresh()->ai_autoreply_disabled);
    }

    public function test_contesta_la_pregunta_que_habia_quedado_sin_responder(): void
    {
        Queue::fake();

        $this->conversacion('59171865104', [
            'ai_autoreply_disabled' => true,
            'ai_failure_count' => 2,
        ], ultimoDe: Message::SENDER_CUSTOMER);

        $this->artisan('wacrm:ai-doctor', ['--reactivate-all' => true, '--skip-worker' => true]);

        // Reactivar sin contestar deja al cliente esperando a que insista, y
        // puede no insistir.
        Queue::assertPushed(AiAutoReplyJob::class);
    }

    public function test_no_contesta_si_el_ultimo_mensaje_ya_era_nuestro(): void
    {
        Queue::fake();

        $this->conversacion('59177117771', [
            'ai_autoreply_disabled' => true,
            'ai_failure_count' => 2,
        ], ultimoDe: Message::SENDER_AGENT);

        $this->artisan('wacrm:ai-doctor', ['--reactivate-all' => true, '--skip-worker' => true]);

        Queue::assertNotPushed(AiAutoReplyJob::class);
    }
}
