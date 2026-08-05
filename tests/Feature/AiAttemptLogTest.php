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
use App\Services\Ai\ReplyGenerator;
use App\Services\WhatsApp\Messenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Registro de lo que decide el bot ante cada mensaje.
 *
 * Todas las formas en que la IA no contesta se ven IGUAL desde afuera: no
 * llega nada. Estaba en pausa, había un flow activo, el modelo estaba ocupado,
 * descartó una respuesta vieja, falló… mismo silencio. Sin registro, cada
 * diagnóstico es una conjetura nueva — y en este proyecto ya costó varios
 * días de ida y vuelta.
 */
class AiAttemptLogTest extends TestCase
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
            'provider' => 'groq',
            'model' => 'qwen/qwen3.6-27b',
            'api_key' => 'gsk_test',
            'is_active' => true,
            'auto_reply_enabled' => true,
            'auto_reply_max_per_conversation' => 20,
        ]);

        $contact = Contact::create(['account_id' => $this->account->id, 'name' => 'Ana', 'phone' => '59177117771', 'phone_normalized' => '59177117771']);
        $this->conversation = Conversation::create(['account_id' => $this->account->id, 'contact_id' => $contact->id, 'status' => 'open']);

        Message::create([
            'account_id' => $this->account->id,
            'conversation_id' => $this->conversation->id,
            'sender_type' => Message::SENDER_CUSTOMER,
            'content_type' => 'text',
            'content_text' => 'hola',
        ]);

        Http::fake();
    }

    private function correr(): void
    {
        app()->call([new AiAutoReplyJob($this->conversation->id), 'handle']);
    }

    private function ultimaDecision(): ?string
    {
        return AiReplyAttempt::where('conversation_id', $this->conversation->id)
            ->latest('created_at')
            ->value('decision');
    }

    public function test_registra_la_respuesta_enviada_con_su_duracion(): void
    {
        $this->mock(ReplyGenerator::class, fn ($m) => $m->shouldReceive('generate')->andReturn('Tenemos 11 programas.'));
        $this->mock(Messenger::class, fn ($m) => $m->shouldReceive('sendText')->andReturn(new Message()));

        $this->correr();

        $intento = AiReplyAttempt::where('conversation_id', $this->conversation->id)->first();

        $this->assertSame('enviada', $intento->decision);
        $this->assertStringContainsString('11 programas', $intento->detail);
        $this->assertNotNull($intento->duration_ms);
    }

    public function test_registra_la_ia_apagada_con_su_motivo(): void
    {
        $this->conversation->update([
            'ai_autoreply_disabled' => true,
            'ai_disabled_reason' => 'Se apagó sola cuando Daniel respondió manualmente',
        ]);

        $this->correr();

        $intento = AiReplyAttempt::where('conversation_id', $this->conversation->id)->first();

        $this->assertSame('ia_apagada', $intento->decision);
        $this->assertStringContainsString('Daniel', $intento->detail);
    }

    public function test_registra_la_pausa(): void
    {
        $this->conversation->update(['ai_paused_until' => now()->addHours(2)]);

        $this->correr();

        $this->assertSame('pausa', $this->ultimaDecision());
    }

    public function test_registra_el_modelo_ocupado(): void
    {
        // Sin fake, el reencolado corre al instante (la cola de los tests es
        // `sync`) y recursiona hasta abandonar: se mediría el final de la
        // cadena en vez del primer intento.
        \Illuminate\Support\Facades\Queue::fake();

        Cache::lock('ai:generando:'.$this->account->id, 300)->get();

        $this->correr();

        $this->assertSame('ocupado', $this->ultimaDecision());
    }

    public function test_registra_el_fallo_con_el_error(): void
    {
        $this->mock(ReplyGenerator::class, fn ($m) => $m->shouldReceive('generate')->andThrow(new \RuntimeException('Groq: límite de uso alcanzado')));

        $this->correr();

        $intento = AiReplyAttempt::where('conversation_id', $this->conversation->id)
            ->where('decision', 'fallo')
            ->first();

        $this->assertStringContainsString('límite de uso', $intento->detail);
    }

    public function test_el_diagnostico_muestra_el_historial(): void
    {
        AiReplyAttempt::registrar($this->conversation, 'pausa', 'hasta 05/08 10:00');
        AiReplyAttempt::registrar($this->conversation, 'enviada', 'Tenemos 11 programas.');

        Http::fake(['*/models' => Http::response(['data' => []])]);

        $this->artisan('wacrm:ai-doctor', ['--conversation' => $this->conversation->id, '--skip-worker' => true])
            ->expectsOutputToContain('Qué hizo la IA con cada mensaje')
            ->expectsOutputToContain('Respondió');
    }

    public function test_un_fallo_al_registrar_no_rompe_la_respuesta(): void
    {
        // Es un registro, no una función del producto: si la tabla no existe
        // todavía (despliegue a medias), la IA tiene que responder igual.
        \Illuminate\Support\Facades\Schema::drop('ai_reply_attempts');

        $this->mock(ReplyGenerator::class, fn ($m) => $m->shouldReceive('generate')->andReturn('Respuesta'));
        $this->mock(Messenger::class, fn ($m) => $m->shouldReceive('sendText')->once()->andReturn(new Message()));

        $this->correr();

        $this->assertSame(1, $this->conversation->refresh()->ai_reply_count);
    }
}
