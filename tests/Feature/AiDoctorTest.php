<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AiConfig;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `wacrm:ai-doctor`: por qué la IA no contesta.
 *
 * El síntoma que motivó el comando: el toggle del chat dice «IA activa» y el
 * bot no responde. Pasa cuando la conversación agotó el tope y quedó en pausa
 * — el toggle mira `ai_autoreply_disabled`, que sigue en false, así que se ve
 * encendida. Sin este diagnóstico no hay forma de distinguir ese caso de un
 * Ollama caído, un flow activo o un worker muerto.
 */
class AiDoctorTest extends TestCase
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
            'auto_reply_max_per_conversation' => 3,
        ]);

        $contact = Contact::create([
            'account_id' => $this->account->id,
            'name' => 'Ana',
            'phone' => '59171234567',
            'phone_normalized' => '59171234567',
        ]);

        $this->conversation = Conversation::create([
            'account_id' => $this->account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

    }

    /** Ollama responde: así el diagnóstico no acusa al proveedor. */
    private function ollamaArriba(): void
    {
        // Un solo fake por test: los stubs se acumulan y gana el primero que
        // coincide, así que refakear después no reemplaza nada.
        Http::fake(['*/api/tags' => Http::response(['models' => []])]);
    }

    public function test_sin_bloqueos_da_via_libre(): void
    {
        $this->ollamaArriba();

        $this->artisan('wacrm:ai-doctor', ['--conversation' => $this->conversation->id, '--skip-worker' => true])
            ->expectsOutputToContain('Sin bloqueos detectados')
            ->assertSuccessful();
    }

    public function test_detecta_la_pausa_por_tope_aunque_el_toggle_diga_activa(): void
    {
        $this->ollamaArriba();
        $this->conversation->update([
            'ai_autoreply_disabled' => false, // el toggle se ve ENCENDIDO
            'ai_reply_count' => 3,
            'ai_paused_until' => now()->addHours(2),
        ]);

        $this->artisan('wacrm:ai-doctor', ['--conversation' => $this->conversation->id, '--skip-worker' => true])
            ->expectsOutputToContain('EN PAUSA hasta')
            ->assertFailed();
    }

    public function test_detecta_la_ia_apagada_en_esa_conversacion(): void
    {
        $this->ollamaArriba();
        $this->conversation->update(['ai_autoreply_disabled' => true]);

        $this->artisan('wacrm:ai-doctor', ['--conversation' => $this->conversation->id, '--skip-worker' => true])
            ->expectsOutputToContain('IA APAGADA en esta conversación')
            ->assertFailed();
    }

    public function test_detecta_la_auto_respuesta_desactivada_en_la_cuenta(): void
    {
        $this->ollamaArriba();
        AiConfig::forAccount($this->account->id)->update(['auto_reply_enabled' => false]);

        $this->artisan('wacrm:ai-doctor', ['--skip-worker' => true])
            ->expectsOutputToContain('Auto-respuesta activada')
            ->assertFailed();
    }

    public function test_detecta_el_proveedor_caido(): void
    {
        Http::fake(['*/api/tags' => Http::response('', 500)]);

        $this->artisan('wacrm:ai-doctor', ['--conversation' => $this->conversation->id, '--skip-worker' => true])
            ->expectsOutputToContain('El proveedor responde')
            ->assertFailed();
    }

    public function test_encuentra_la_conversacion_por_telefono(): void
    {
        $this->ollamaArriba();
        $this->artisan('wacrm:ai-doctor', ['--phone' => '59171234567', '--skip-worker' => true])
            ->expectsOutputToContain('Ana')
            ->assertSuccessful();
    }

    public function test_detecta_el_worker_caido(): void
    {
        $this->ollamaArriba();

        // Cola falsa: el job se encola y nadie lo procesa, que es exactamente
        // lo que pasa con el worker muerto. «0 jobs en cola» no lo delataba.
        \Illuminate\Support\Facades\Queue::fake();

        $this->artisan('wacrm:ai-doctor', ['--conversation' => $this->conversation->id])
            ->expectsOutputToContain('El worker NO procesó el job de prueba')
            ->assertFailed();
    }

    public function test_da_por_vivo_el_worker_que_procesa(): void
    {
        $this->ollamaArriba();

        // Cola sincrónica: el job corre al encolarse, como haría un worker sano.
        config(['queue.default' => 'sync']);

        $this->artisan('wacrm:ai-doctor', ['--conversation' => $this->conversation->id])
            ->expectsOutputToContain('El worker está procesando la cola')
            ->assertSuccessful();
    }

    public function test_avisa_que_el_proximo_mensaje_cae_en_pausa(): void
    {
        $this->ollamaArriba();
        // Tope alcanzado pero sin pausa todavía: el próximo entrante la activa.
        $this->conversation->update(['ai_reply_count' => 3, 'ai_paused_until' => null]);

        $this->artisan('wacrm:ai-doctor', ['--conversation' => $this->conversation->id, '--skip-worker' => true])
            ->expectsOutputToContain('la manda a pausa')
            ->assertFailed();
    }
}
