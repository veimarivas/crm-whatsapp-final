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
use App\Services\Ai\ReplySanitizer;
use App\Services\WhatsApp\Messenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Respuestas cortas y una sola por tanda.
 *
 * Dos quejas reales del mismo origen: la IA contestaba parrafadas que en un
 * chat nadie lee, y al cliente le llegaban DOS mensajes seguidos. Lo segundo
 * pasa porque cada mensaje entrante encola su propia respuesta: si el cliente
 * escribe otra vez mientras la IA piensa (y piensa decenas de segundos), se
 * generan dos respuestas, la primera además contra un contexto viejo.
 */
class ReplyBrevityTest extends TestCase
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
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'base_url' => 'http://127.0.0.1:11434',
            'is_active' => true,
            'auto_reply_enabled' => true,
            'auto_reply_max_per_conversation' => 10,
        ]);

        $contact = Contact::create(['account_id' => $this->account->id, 'name' => 'Ana', 'phone' => '5917000', 'phone_normalized' => '5917000']);
        $this->conversation = Conversation::create(['account_id' => $this->account->id, 'contact_id' => $contact->id, 'status' => 'open']);
    }

    private function preguntar(string $texto): Message
    {
        return Message::create([
            'account_id' => $this->account->id,
            'conversation_id' => $this->conversation->id,
            'sender_type' => Message::SENDER_CUSTOMER,
            'content_type' => 'text',
            'content_text' => $texto,
        ]);
    }

    public function test_una_respuesta_larga_se_corta_y_ofrece_ampliar(): void
    {
        $largo = collect(range(1, 30))
            ->map(fn ($i) => "{$i}. Diplomado de prueba número {$i} con su descripción completa")
            ->join("\n");

        $corta = (new ReplySanitizer())->fitToChat($largo, 700);

        $this->assertLessThan(800, mb_strlen($corta));
        $this->assertStringContainsString('¿Querés que te amplíe alguno?', $corta);
        // Corta por líneas: partir un ítem a la mitad se ve peor que mostrar
        // uno menos.
        $this->assertStringNotContainsString('Diplomado de prueba número 30', $corta);
    }

    public function test_una_respuesta_corta_pasa_intacta(): void
    {
        $corta = 'El diplomado cuesta Bs 4.440 y empieza el 12/08/2026.';

        $this->assertSame($corta, (new ReplySanitizer())->fitToChat($corta, 700));
    }

    public function test_el_generador_acota_lo_que_devuelve(): void
    {
        $this->preguntar('que programas tienen?');

        $largo = str_repeat('Diplomado con muchos datos y descripciones extensas. ', 60);
        Http::fake(['*' => Http::response(['message' => ['content' => $largo]])]);

        $reply = app(ReplyGenerator::class)->generate($this->config, $this->conversation);

        $this->assertLessThan(900, mb_strlen($reply));
    }

    public function test_si_el_cliente_vuelve_a_escribir_no_le_llegan_dos_respuestas(): void
    {
        $this->preguntar('que programas tienen?');

        // El generador tarda, y en ese rato entra otra pregunta: es justo lo
        // que pasaba en producción y terminaba en dos mensajes seguidos.
        $this->mock(ReplyGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->andReturnUsing(function () {
                $this->preguntar('y cuanto cuestan?');

                return 'Respuesta a la primera pregunta';
            });
        });

        // Si esta respuesta se enviara, el cliente recibiría dos mensajes.
        $this->mock(Messenger::class, function ($mock) {
            $mock->shouldNotReceive('sendText');
        });

        Http::fake();

        app()->call([new AiAutoReplyJob($this->conversation->id), 'handle']);

        $this->conversation->refresh();

        $this->assertFalse($this->conversation->ai_pending, 'La burbuja tiene que apagarse igual.');
        $this->assertSame(0, $this->conversation->ai_reply_count, 'La descartada no cuenta como respuesta.');
    }

    public function test_sin_mensajes_nuevos_la_respuesta_se_envia(): void
    {
        $this->preguntar('que programas tienen?');

        $this->mock(ReplyGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->andReturn('Tenemos 3 programas abiertos.');
        });

        $this->mock(Messenger::class, function ($mock) {
            $mock->shouldReceive('sendText')->once()->andReturn(new Message());
        });

        Http::fake();

        app()->call([new AiAutoReplyJob($this->conversation->id), 'handle']);

        $this->assertSame(1, $this->conversation->refresh()->ai_reply_count);
    }
}
