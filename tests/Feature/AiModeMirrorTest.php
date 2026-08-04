<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Quién apagó la IA de una conversación, y que Komo se entere.
 *
 * El caso real que motivó esto: la conversación aparecía con la IA apagada,
 * sin ninguna falla en el log y con el contador de respuestas en 0. La causa
 * era la regla «si contesta un humano, el bot se calla» — correcta, pero
 * invisible: no quedaba registro de quién la apagó y Komo seguía mostrando
 * «IA activa». Se veía como un bot roto.
 */
class AiModeMirrorTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $agente;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agente = User::create(['name' => 'Daniel Rojas', 'email' => 'd@test.com', 'password' => bcrypt('x')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->agente->id]);
        $this->agente->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

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

    private function mensajeDelAgente(): void
    {
        Message::create([
            'account_id' => $this->account->id,
            'conversation_id' => $this->conversation->id,
            'sender_type' => Message::SENDER_AGENT,
            'sender_id' => $this->agente->id,
            'content_type' => 'text',
            'content_text' => 'Hola, te ayudo yo',
        ]);

        $this->conversation->refresh();
    }

    public function test_responder_a_mano_apaga_la_ia_y_deja_dicho_quien_fue(): void
    {
        $this->mensajeDelAgente();

        $this->assertTrue($this->conversation->ai_autoreply_disabled);
        $this->assertStringContainsString('Daniel Rojas', $this->conversation->ai_disabled_reason);
        $this->assertNotNull($this->conversation->ai_disabled_at);
    }

    public function test_el_apagado_se_avisa_a_komo(): void
    {
        Queue::fake();

        WebhookEndpoint::create([
            'account_id' => $this->account->id,
            'url' => 'https://komo.test/webhooks/wacrm/'.$this->account->id,
            'secret' => 'whsec_test',
            'events' => ['ai.mode_changed'],
            'is_active' => true,
        ]);

        $this->mensajeDelAgente();

        // Sin este aviso, la ficha de Komo sigue mostrando «IA activa» sobre
        // una conversación donde el bot ya no contesta.
        Queue::assertPushed(\App\Jobs\DeliverWebhookJob::class);
    }

    public function test_reactivar_limpia_pausa_contador_y_motivo(): void
    {
        Http::fake();
        $this->mensajeDelAgente();

        $this->conversation->update(['ai_paused_until' => now()->addHours(2), 'ai_reply_count' => 5, 'ai_failure_count' => 2]);

        $this->conversation->setAiEnabled(true);
        $this->conversation->refresh();

        $this->assertFalse($this->conversation->ai_autoreply_disabled);
        $this->assertNull($this->conversation->ai_disabled_reason);
        $this->assertNull($this->conversation->ai_paused_until);
        $this->assertSame(0, $this->conversation->ai_reply_count);
        $this->assertSame(0, $this->conversation->ai_failure_count);
    }

    public function test_el_toggle_del_inbox_deja_constancia(): void
    {
        $this->actingAs($this->agente)
            ->patchJson(route('inbox.ai-mode', $this->conversation), ['ai_enabled' => false])
            ->assertOk();

        $this->conversation->refresh();

        $this->assertTrue($this->conversation->ai_autoreply_disabled);
        $this->assertStringContainsString('Daniel Rojas', $this->conversation->ai_disabled_reason);
    }

    public function test_un_mensaje_del_bot_no_apaga_nada(): void
    {
        Message::create([
            'account_id' => $this->account->id,
            'conversation_id' => $this->conversation->id,
            'sender_type' => Message::SENDER_BOT,
            'content_type' => 'text',
            'content_text' => 'Respuesta automática',
        ]);

        $this->conversation->refresh();

        $this->assertFalse($this->conversation->ai_autoreply_disabled);
    }
}
