<?php

namespace Tests\Feature;

use App\Jobs\AiAutoReplyJob;
use App\Models\Account;
use App\Models\AiConfig;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\ReplyGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Qué pasa cuando la IA falla al generar la respuesta.
 *
 * Antes una sola falla apagaba la IA en esa conversación PARA SIEMPRE. Es
 * demasiado frágil: la primera consulta a Ollama carga el modelo en memoria y
 * puede pasarse del timeout, así que un tropiezo normal dejaba la conversación
 * sin bot — con el contador de respuestas en 0 y sin ninguna explicación en
 * pantalla. Ahora el primer fallo reintenta en el próximo mensaje y recién el
 * segundo seguido la apaga, dejando escrito el motivo.
 */
class AiFailurePolicyTest extends TestCase
{
    use RefreshDatabase;

    private Conversation $conversation;

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
            'auto_reply_enabled' => true,
            'auto_reply_max_per_conversation' => 10,
        ]);

        $contact = Contact::create([
            'account_id' => $account->id,
            'name' => 'Ana',
            'phone' => '59171234567',
            'phone_normalized' => '59171234567',
        ]);

        $this->conversation = Conversation::create([
            'account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        Message::create([
            'account_id' => $account->id,
            'conversation_id' => $this->conversation->id,
            'sender_type' => Message::SENDER_CUSTOMER,
            'content_type' => 'text',
            'content_text' => 'Hola, información por favor',
        ]);

        Http::fake();
    }

    /** El generador revienta como lo haría un timeout de Ollama. */
    private function ejecutarConFalla(string $error = 'cURL error 28: Operation timed out'): void
    {
        $this->mock(ReplyGenerator::class, function ($mock) use ($error) {
            $mock->shouldReceive('generate')->andThrow(new \RuntimeException($error));
        });

        app()->call([new AiAutoReplyJob($this->conversation->id), 'handle']);

        $this->conversation->refresh();
    }

    public function test_una_sola_falla_ya_no_apaga_la_ia(): void
    {
        $this->ejecutarConFalla();

        $this->assertFalse($this->conversation->ai_autoreply_disabled, 'El próximo mensaje tiene que reintentar.');
        $this->assertSame(1, $this->conversation->ai_failure_count);
        $this->assertFalse($this->conversation->ai_pending, 'La burbuja "pensando" no puede quedar encendida.');
    }

    public function test_la_segunda_falla_seguida_la_apaga_y_deja_el_motivo(): void
    {
        $this->ejecutarConFalla();
        $this->ejecutarConFalla('cURL error 28: Operation timed out');

        $this->assertTrue($this->conversation->ai_autoreply_disabled);
        $this->assertSame(2, $this->conversation->ai_failure_count);
        $this->assertStringContainsString('timed out', $this->conversation->ai_disabled_reason);
        $this->assertNotNull($this->conversation->ai_disabled_at);
    }

    public function test_una_respuesta_buena_borra_el_historial_de_fallas(): void
    {
        $this->ejecutarConFalla();
        $this->assertSame(1, $this->conversation->ai_failure_count);

        // Ahora responde bien: lo que importa son las fallas SEGUIDAS.
        $this->mock(ReplyGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->andReturn('Hola, con gusto te ayudo.');
        });

        // El envío a Meta también se aísla: sin credenciales de WhatsApp
        // `sendText` lanza, el job lo cuenta como falla y el test mediría otra
        // cosa de la que cree medir.
        $this->mock(\App\Services\WhatsApp\Messenger::class, function ($mock) {
            $mock->shouldReceive('sendText')->once()->andReturn(new Message());
        });

        app()->call([new AiAutoReplyJob($this->conversation->id), 'handle']);
        $this->conversation->refresh();

        $this->assertSame(0, $this->conversation->ai_failure_count);
        $this->assertFalse($this->conversation->ai_autoreply_disabled);
        $this->assertSame(1, $this->conversation->ai_reply_count);
    }

    public function test_al_cliente_no_se_le_manda_nada_cuando_falla(): void
    {
        $this->ejecutarConFalla();

        // Ni un "un asesor te atenderá": delata al bot y ocupa el lugar de una
        // respuesta de verdad.
        Http::assertNothingSent();
    }

    public function test_el_doctor_reactiva_y_limpia_el_historial(): void
    {
        $this->conversation->update([
            'ai_autoreply_disabled' => true,
            'ai_failure_count' => 2,
            'ai_disabled_reason' => 'timeout',
            'ai_disabled_at' => now(),
        ]);

        Http::fake(['*/api/tags' => Http::response(['models' => []])]);

        $this->artisan('wacrm:ai-doctor', [
            '--conversation' => $this->conversation->id,
            '--reactivate' => true,
            '--skip-worker' => true,
        ])->expectsOutputToContain('Reactivada');

        $this->conversation->refresh();

        $this->assertFalse($this->conversation->ai_autoreply_disabled);
        $this->assertSame(0, $this->conversation->ai_failure_count);
        $this->assertNull($this->conversation->ai_disabled_reason);
    }
}
