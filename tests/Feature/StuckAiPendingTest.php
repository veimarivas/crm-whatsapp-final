<?php

namespace Tests\Feature;

use App\Jobs\AiAutoReplyJob;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La burbuja «Pensando respuesta…» que no se apaga nunca.
 *
 * El job la enciende al arrancar y la apaga al terminar, pero si al job LO
 * MATAN —se pasa del timeout del worker, un OOM, un reinicio en pleno
 * despliegue— su `catch` no corre y la bandera queda encendida para siempre.
 * Desde la pantalla se ve como una IA que está a punto de contestar y nunca
 * contesta.
 *
 * El bug que lo destapó: el timeout del job (130 s) era MENOR que el del HTTP
 * (180 s), así que el worker mataba el job en pleno request, siempre.
 */
class StuckAiPendingTest extends TestCase
{
    use RefreshDatabase;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create(['name' => 'Admin', 'email' => 'a@test.com', 'password' => bcrypt('x')]);
        $account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $account->id, 'account_role' => User::ROLE_OWNER]);

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

        Http::fake();
    }

    public function test_el_job_espera_mas_que_la_peticion_http(): void
    {
        config(['services.ollama.timeout' => 240]);

        $job = new AiAutoReplyJob($this->conversation->id);

        // Si el worker mata el job antes de que el cliente HTTP corte, no hay
        // `catch` que valga: no se apaga la burbuja ni se cuenta la falla.
        $this->assertGreaterThan(240, $job->timeout);
    }

    public function test_barre_la_burbuja_que_quedo_colgada(): void
    {
        $this->conversation->update([
            'ai_pending' => true,
            'ai_pending_at' => now()->subMinutes(30),
        ]);

        $this->artisan('wacrm:ai-clear-stuck-pending')
            ->expectsOutputToContain('1 burbuja(s) colgada(s) apagada(s)')
            ->assertSuccessful();

        $this->conversation->refresh();

        $this->assertFalse($this->conversation->ai_pending);
        $this->assertNull($this->conversation->ai_pending_at);
    }

    public function test_no_toca_a_la_ia_que_esta_trabajando_ahora(): void
    {
        $this->conversation->update([
            'ai_pending' => true,
            'ai_pending_at' => now()->subSeconds(30),
        ]);

        $this->artisan('wacrm:ai-clear-stuck-pending')
            ->expectsOutputToContain('Sin burbujas colgadas')
            ->assertSuccessful();

        $this->assertTrue($this->conversation->refresh()->ai_pending);
    }

    public function test_una_burbuja_sin_marca_de_tiempo_se_considera_vieja(): void
    {
        // Las que quedaron colgadas ANTES de que existiera `ai_pending_at`:
        // sin fecha no hay forma de saber su edad, y llevan ahí desde antes
        // del despliegue.
        $this->conversation->update(['ai_pending' => true, 'ai_pending_at' => null]);

        $this->artisan('wacrm:ai-clear-stuck-pending')->assertSuccessful();

        $this->assertFalse($this->conversation->refresh()->ai_pending);
    }

    public function test_el_doctor_la_reporta(): void
    {
        $this->conversation->update(['ai_pending' => true, 'ai_pending_at' => now()->subMinutes(20)]);

        \App\Models\AiConfig::create([
            'account_id' => $this->conversation->account_id,
            'provider' => 'ollama',
            'model' => 'qwen2.5:3b',
            'base_url' => 'http://127.0.0.1:11434',
            'is_active' => true,
            'auto_reply_enabled' => true,
        ]);

        Http::fake(['*/api/tags' => Http::response(['models' => []])]);

        $this->artisan('wacrm:ai-doctor', [
            '--conversation' => $this->conversation->id,
            '--skip-worker' => true,
        ])->expectsOutputToContain('Pensando respuesta')->assertFailed();
    }
}
