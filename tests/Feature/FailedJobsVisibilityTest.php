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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Jobs que revientan sin dejar rastro visible.
 *
 * El diagnóstico decía «Fallidos en las últimas 24 h: 3» y a la vez «Sin
 * bloqueos detectados»: contaba los fallos pero no los explicaba, así que el
 * dato más importante de la pantalla era el único ilegible.
 *
 * Y el motivo de esos fallos fue una lección: un error ANTES del try/catch
 * —como un almacén de caché sin candados atómicos— mata el job entero. No
 * apaga la burbuja, no registra motivo en la conversación, no cuenta como
 * falla de la IA. Solo aparece un job fallido más, mientras el cliente espera.
 */
class FailedJobsVisibilityTest extends TestCase
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
            'provider' => 'groq',
            'model' => 'qwen/qwen3.6-27b',
            'api_key' => 'gsk_test',
            'is_active' => true,
            'auto_reply_enabled' => true,
            'auto_reply_max_per_conversation' => 20,
        ]);

        $contact = Contact::create(['account_id' => $account->id, 'name' => 'Ana', 'phone' => '59171865104', 'phone_normalized' => '59171865104']);
        $this->conversation = Conversation::create(['account_id' => $account->id, 'contact_id' => $contact->id, 'status' => 'open']);

        Message::create([
            'account_id' => $account->id,
            'conversation_id' => $this->conversation->id,
            'sender_type' => Message::SENDER_CUSTOMER,
            'content_type' => 'text',
            'content_text' => 'hola',
        ]);

        Http::fake(['*/models' => Http::response(['data' => []])]);
    }

    public function test_el_diagnostico_muestra_por_que_fallaron(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\AiAutoReplyJob']),
            'exception' => "BadMethodCallException: This cache store does not support locks.\n#0 /var/www/...",
            'failed_at' => now(),
        ]);

        $this->artisan('wacrm:ai-doctor', ['--conversation' => $this->conversation->id, '--skip-worker' => true])
            ->expectsOutputToContain('AiAutoReplyJob')
            ->expectsOutputToContain('does not support locks');
    }

    public function test_si_el_cache_no_soporta_candados_la_ia_responde_igual(): void
    {
        // Un almacén sin candados atómicos hacía morir el job ANTES del
        // try/catch. Que dos consultas se pisen es menos grave que no
        // responder nunca.
        Cache::shouldReceive('lock')->andThrow(new \BadMethodCallException('This cache store does not support locks.'));

        $this->mock(ReplyGenerator::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn('Tenemos 11 programas abiertos.');
        });

        $this->mock(Messenger::class, function ($mock) {
            $mock->shouldReceive('sendText')->once()->andReturn(new Message());
        });

        app()->call([new AiAutoReplyJob($this->conversation->id), 'handle']);

        $this->assertSame(1, $this->conversation->refresh()->ai_reply_count);
    }
}
