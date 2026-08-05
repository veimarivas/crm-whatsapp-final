<?php

namespace Tests\Feature;

use App\Jobs\AiAutoReplyJob;
use App\Models\Account;
use App\Models\AiConfig;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «Worker caído» vs «worker ocupado».
 *
 * El diagnóstico encolaba un ping y, si no volvía en 12 s, declaraba muerto al
 * worker. Pero el worker procesa UN job a la vez y una respuesta de la IA lo
 * tiene tomado hasta dos minutos: el ping espera detrás. Así acusaba de caído
 * a un servicio que estaba trabajando bien, y mandaba a reiniciarlo justo
 * cuando estaba generando una respuesta.
 */
class WorkerProbeTest extends TestCase
{
    use RefreshDatabase;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create(['name' => 'Admin', 'email' => 'a@test.com', 'password' => bcrypt('x')]);
        $account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $account->id, 'account_role' => User::ROLE_OWNER]);

        AiConfig::create([
            'account_id' => $account->id,
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'base_url' => 'http://127.0.0.1:11434',
            'is_active' => true,
            'auto_reply_enabled' => true,
        ]);

        $contact = Contact::create(['account_id' => $account->id, 'name' => 'Ana', 'phone' => '5917000', 'phone_normalized' => '5917000']);
        $this->conversation = Conversation::create(['account_id' => $account->id, 'contact_id' => $contact->id, 'status' => 'open']);

        Http::fake(['*/api/tags' => Http::response(['models' => []])]);
    }

    public function test_un_job_en_curso_significa_worker_vivo_y_no_caido(): void
    {
        // Nadie procesa el ping, igual que con un worker ocupado en un job
        // largo. En los tests la cola es `sync` y se ejecutaría al instante,
        // lo que taparía justo el caso que se quiere probar.
        \Illuminate\Support\Facades\Queue::fake();

        // Un job RESERVADO es un job que alguien está procesando ahora mismo:
        // prueba de que el worker está vivo, por más que el ping no vuelva.
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 1,
            'reserved_at' => now()->timestamp,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $this->artisan('wacrm:ai-doctor', ['--conversation' => $this->conversation->id])
            ->expectsOutputToContain('VIVO pero ocupado')
            ->doesntExpectOutputToContain('El worker NO procesó');
    }

    public function test_sin_nada_en_curso_y_sin_ping_si_es_worker_caido(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $this->artisan('wacrm:ai-doctor', ['--conversation' => $this->conversation->id])
            ->expectsOutputToContain('El worker NO procesó')
            ->assertFailed();
    }

    public function test_la_ia_va_a_la_cola_dedicada_cuando_se_configura(): void
    {
        config(['services.ai_context.queue' => 'ia']);

        $job = new AiAutoReplyJob($this->conversation->id);

        // Sacar la IA de la cola general es lo que evita que una respuesta de
        // dos minutos tape los webhooks y los envíos que esperan detrás.
        $this->assertSame('ia', $job->queue);
    }

    public function test_sin_configurar_usa_la_cola_general(): void
    {
        config(['services.ai_context.queue' => null]);

        // Mandarla a una cola que nadie consume la dejaría sin responder nunca.
        $this->assertNull((new AiAutoReplyJob($this->conversation->id))->queue);
    }
}
