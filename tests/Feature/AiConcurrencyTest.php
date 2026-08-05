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
use App\Services\WhatsApp\Messenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Una consulta a la vez contra el modelo.
 *
 * El «cURL error 28 … 0 bytes received» no era Ollama caído: era Ollama
 * OCUPADO. Atiende de a una por modelo, así que la segunda consulta se queda
 * esperando en su cola sin recibir un byte y se come su propio timeout ahí
 * parada. Con dos clientes escribiendo al mismo tiempo —o uno que escribe dos
 * veces— la segunda respuesta estaba condenada.
 *
 * Con el candado, el que llega y encuentra ocupado se reencola en vez de irse
 * a morir a la cola del modelo.
 */
class AiConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create(['name' => 'Admin', 'email' => 'a@test.com', 'password' => bcrypt('x')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        AiConfig::create([
            'account_id' => $this->account->id,
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'base_url' => 'http://127.0.0.1:11434',
            'is_active' => true,
            'auto_reply_enabled' => true,
            'auto_reply_max_per_conversation' => 10,
        ]);

        $contact = Contact::create(['account_id' => $this->account->id, 'name' => 'Ana', 'phone' => '5917000', 'phone_normalized' => '5917000']);
        $this->conversation = Conversation::create(['account_id' => $this->account->id, 'contact_id' => $contact->id, 'status' => 'open']);

        Message::create([
            'account_id' => $this->account->id,
            'conversation_id' => $this->conversation->id,
            'sender_type' => Message::SENDER_CUSTOMER,
            'content_type' => 'text',
            'content_text' => 'que programas tienen?',
        ]);

        Http::fake();
    }

    private function ocuparElModelo(): void
    {
        // Otro job ya está generando para esta cuenta.
        Cache::lock('ai:generando:'.$this->account->id, 300)->get();
    }

    public function test_si_el_modelo_esta_ocupado_no_se_manda_la_consulta(): void
    {
        $this->ocuparElModelo();

        // Mandarla igual significa quedarse esperando en la cola de Ollama
        // hasta agotar el timeout, sin recibir un byte.
        $this->mock(ReplyGenerator::class, function ($mock) {
            $mock->shouldNotReceive('generate');
        });

        Queue::fake();

        app()->call([new AiAutoReplyJob($this->conversation->id), 'handle']);

        // Se reintenta más tarde en vez de perderse.
        Queue::assertPushed(AiAutoReplyJob::class, fn ($job) => $job->requeues === 1);
    }

    public function test_con_el_modelo_libre_responde_normal(): void
    {
        $this->mock(ReplyGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn('Tenemos 3 programas abiertos.');
        });

        $this->mock(Messenger::class, function ($mock) {
            $mock->shouldReceive('sendText')->once()->andReturn(new Message());
        });

        app()->call([new AiAutoReplyJob($this->conversation->id), 'handle']);

        $this->assertSame(1, $this->conversation->refresh()->ai_reply_count);
    }

    public function test_el_candado_se_libera_aunque_la_generacion_falle(): void
    {
        $this->mock(ReplyGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->andThrow(new \RuntimeException('timeout'));
        });

        app()->call([new AiAutoReplyJob($this->conversation->id), 'handle']);

        // Si quedara puesto, la IA se callaría para toda la cuenta hasta que
        // expire el candado.
        $this->assertTrue(
            Cache::lock('ai:generando:'.$this->account->id, 10)->get(),
            'El candado quedó tomado después de un fallo.',
        );
    }

    public function test_no_se_reencola_para_siempre(): void
    {
        $this->ocuparElModelo();
        Queue::fake();

        // Quinto intento: ya se esperó ~2 minutos. Insistir solo tapa un
        // problema más grave.
        app()->call([new AiAutoReplyJob($this->conversation->id, 5), 'handle']);

        Queue::assertNothingPushed();
    }
}
