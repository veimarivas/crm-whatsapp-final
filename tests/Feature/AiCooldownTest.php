<?php

namespace Tests\Feature;

use App\Jobs\AiAutoReplyJob;
use App\Models\Account;
use App\Models\AiConfig;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Notification;
use App\Models\User;
use App\Models\WhatsappConfig;
use App\Services\Ai\ReplyGenerator;
use App\Services\Channels\ChannelRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tope de respuestas de la IA con pausa y reactivación automática.
 *
 * Antes el tope era inalcanzable: `InboundProcessor` reseteaba
 * `ai_reply_count` a 0 con cada entrante, así que el "máximo N por
 * conversación" de Ajustes no limitaba nada. Ahora el contador se acumula,
 * al llegar al tope la IA queda en pausa, y cuando la pausa vence retoma
 * sola sin que nadie toque nada.
 */
class AiCooldownTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        WhatsappConfig::create([
            'account_id' => $this->account->id,
            'phone_number_id' => '111',
            'access_token' => 'token',
            'status' => 'connected',
        ]);

        AiConfig::create([
            'account_id' => $this->account->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-test',
            'system_prompt' => 'Sos un asesor.',
            'is_active' => true,
            'auto_reply_enabled' => true,
            'auto_reply_max_per_conversation' => 2,
            'auto_reply_cooldown_hours' => 3,
        ]);

        $contact = Contact::create(['account_id' => $this->account->id, 'name' => 'Ana', 'phone' => '59170000000']);
        $this->conversation = Conversation::create([
            'account_id' => $this->account->id,
            'contact_id' => $contact->id,
            'status' => Conversation::STATUS_OPEN,
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Respuesta IA']]]]),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]]),
        ]);
    }

    private function inbound(string $text = 'Hola'): void
    {
        Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => Message::SENDER_CUSTOMER,
            'content_text' => $text,
        ]);
    }

    private function runBot(): void
    {
        (new AiAutoReplyJob($this->conversation->id))
            ->handle(app(ReplyGenerator::class), app(ChannelRouter::class));

        $this->conversation->refresh();
    }

    private function botReplies(): int
    {
        return Message::where('conversation_id', $this->conversation->id)
            ->where('sender_type', Message::SENDER_BOT)
            ->count();
    }

    public function test_al_agotar_el_tope_la_ia_queda_en_pausa(): void
    {
        $this->inbound();
        $this->runBot();
        $this->inbound();
        $this->runBot();

        $this->assertSame(2, $this->botReplies());
        $this->assertNull($this->conversation->ai_paused_until, 'Todavía no llegó al tope.');

        // Tercer intento: el tope (2) ya se alcanzó.
        $this->inbound();
        $this->runBot();

        $this->assertSame(2, $this->botReplies(), 'No responde una tercera vez.');
        $this->assertNotNull($this->conversation->ai_paused_until);
        // La pausa es de 3 h según la config.
        $this->assertEqualsWithDelta(3 * 3600, now()->diffInSeconds($this->conversation->ai_paused_until), 60);
    }

    public function test_durante_la_pausa_no_responde_ni_vuelve_a_avisar(): void
    {
        $this->conversation->update([
            'ai_reply_count' => 2,
            'ai_paused_until' => now()->addHours(2),
            'ai_limit_notified_at' => now(),
        ]);

        $this->inbound();
        $this->runBot();

        $this->assertSame(0, $this->botReplies());
        // El aviso ya salió cuando se alcanzó el tope: repetirlo sería ruido.
        $this->assertSame(0, Notification::count());
    }

    public function test_vencida_la_pausa_la_ia_retoma_sola(): void
    {
        $this->conversation->update([
            'ai_reply_count' => 2,
            'ai_paused_until' => now()->subMinute(),
        ]);

        $this->inbound();
        $this->runBot();

        $this->assertSame(1, $this->botReplies(), 'Volvió a responder sin que nadie tocara nada.');
        $this->assertNull($this->conversation->ai_paused_until);
        $this->assertSame(1, $this->conversation->ai_reply_count, 'El contador arrancó de cero.');
    }

    public function test_el_contador_ya_no_se_reinicia_con_cada_mensaje(): void
    {
        // Esta era la razón por la que el tope nunca se alcanzaba.
        $this->inbound();
        $this->runBot();

        $this->inbound('Otro mensaje');
        $this->conversation->refresh();

        $this->assertSame(1, $this->conversation->ai_reply_count);
    }

    public function test_reactivar_la_ia_a_mano_levanta_la_pausa(): void
    {
        $owner = User::where('account_id', $this->account->id)->first();

        $this->conversation->update([
            'ai_reply_count' => 2,
            'ai_paused_until' => now()->addHours(2),
        ]);

        $this->actingAs($owner)
            ->patchJson(route('inbox.ai-mode', $this->conversation), ['ai_enabled' => true])
            ->assertOk();

        $this->conversation->refresh();

        $this->assertNull($this->conversation->ai_paused_until);
        $this->assertSame(0, $this->conversation->ai_reply_count);
    }

    public function test_la_pausa_viaja_a_la_ui(): void
    {
        $owner = User::where('account_id', $this->account->id)->first();
        $this->conversation->update(['ai_paused_until' => now()->addHours(3)]);

        $data = $this->actingAs($owner)
            ->getJson(route('inbox.conversations'))
            ->assertOk()
            ->json();

        $this->assertNotNull($data[0]['ai_paused_until'], 'El inbox necesita el dato para mostrar el aviso.');
    }
}
