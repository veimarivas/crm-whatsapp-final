<?php

namespace Tests\Feature;

use App\Jobs\AiAutoReplyJob;
use App\Models\Account;
use App\Models\AiConfig;
use App\Models\AiReplyAttempt;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\Client;
use App\Services\Ai\RateLimitedException;
use App\Services\Ai\ReplyGenerator;
use App\Services\WhatsApp\Messenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Cuota del proveedor agotada (HTTP 429).
 *
 * No es un fallo que haya que arreglar: es «esperá un minuto». Tratarlo como
 * una falla normal era doblemente malo — apagaba la IA de esa conversación
 * (dos fallos seguidos la apagan) por algo que no tiene nada que ver con ella,
 * y tiraba a la basura una respuesta que iba a salir bien un minuto después.
 */
class RateLimitRetryTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private AiConfig $config;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create(['name' => 'Admin', 'email' => 'a@test.com', 'password' => bcrypt('x')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        $this->config = AiConfig::create([
            'account_id' => $this->account->id,
            'provider' => 'groq',
            'model' => 'llama-3.3-70b-versatile',
            'api_key' => 'gsk_test',
            'is_active' => true,
            'auto_reply_enabled' => true,
            'auto_reply_max_per_conversation' => 20,
        ]);

        $contact = Contact::create(['account_id' => $this->account->id, 'name' => 'Ana', 'phone' => '5917000', 'phone_normalized' => '5917000']);
        $this->conversation = Conversation::create(['account_id' => $this->account->id, 'contact_id' => $contact->id, 'status' => 'open']);

        Message::create([
            'account_id' => $this->account->id,
            'conversation_id' => $this->conversation->id,
            'sender_type' => Message::SENDER_CUSTOMER,
            'content_type' => 'text',
            'content_text' => 'hola',
        ]);
    }

    public function test_el_429_se_distingue_de_cualquier_otro_error(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Rate limit reached']], 429)]);

        $this->expectException(RateLimitedException::class);

        Client::for($this->config)->chat([['role' => 'user', 'content' => 'hola']], null, 200);
    }

    public function test_no_cuenta_como_falla_de_la_conversacion(): void
    {
        Queue::fake();

        $this->mock(ReplyGenerator::class, fn ($m) => $m->shouldReceive('generate')
            ->andThrow(new RateLimitedException('Groq: límite de uso alcanzado')));

        app()->call([new AiAutoReplyJob($this->conversation->id), 'handle']);

        $this->conversation->refresh();

        // Dos fallas seguidas apagan la IA. La cuota del proveedor no puede
        // apagar una conversación: no tiene nada que ver con ella.
        $this->assertSame(0, $this->conversation->ai_failure_count);
        $this->assertFalse($this->conversation->ai_autoreply_disabled);
    }

    public function test_se_reintenta_mas_tarde_en_vez_de_perder_la_respuesta(): void
    {
        Queue::fake();

        $this->mock(ReplyGenerator::class, fn ($m) => $m->shouldReceive('generate')
            ->andThrow(new RateLimitedException('Groq: límite de uso alcanzado')));

        app()->call([new AiAutoReplyJob($this->conversation->id), 'handle']);

        Queue::assertPushed(AiAutoReplyJob::class, fn ($job) => $job->requeues === 1);
    }

    public function test_la_burbuja_no_queda_encendida(): void
    {
        Queue::fake();

        $this->mock(ReplyGenerator::class, fn ($m) => $m->shouldReceive('generate')
            ->andThrow(new RateLimitedException('Groq: límite de uso alcanzado')));

        app()->call([new AiAutoReplyJob($this->conversation->id), 'handle']);

        $this->assertFalse($this->conversation->refresh()->ai_pending);
    }

    public function test_tras_varios_intentos_avisa_a_un_humano(): void
    {
        Queue::fake();

        $this->mock(ReplyGenerator::class, fn ($m) => $m->shouldReceive('generate')
            ->andThrow(new RateLimitedException('Groq: límite de uso alcanzado')));

        $this->mock(Messenger::class, fn ($m) => $m->shouldNotReceive('sendText'));

        // Último intento: ya se esperó varios minutos. Dejar al cliente
        // esperando indefinidamente no es opción.
        app()->call([new AiAutoReplyJob($this->conversation->id, 5), 'handle']);

        Queue::assertNothingPushed();

        $this->assertDatabaseHas('notifications', ['account_id' => $this->account->id]);
    }

    public function test_queda_registrado_como_cuota_y_no_como_fallo(): void
    {
        Queue::fake();

        $this->mock(ReplyGenerator::class, fn ($m) => $m->shouldReceive('generate')
            ->andThrow(new RateLimitedException('Groq: límite de uso alcanzado')));

        app()->call([new AiAutoReplyJob($this->conversation->id), 'handle']);

        $this->assertSame(
            'limite_proveedor',
            AiReplyAttempt::where('conversation_id', $this->conversation->id)->value('decision'),
        );
    }
}
