<?php

namespace Tests\Feature;

use App\Jobs\AiAutoReplyJob;
use App\Jobs\DeliverWebhookJob;
use App\Models\Account;
use App\Models\AccountInvitation;
use App\Models\AiConfig;
use App\Models\AiKnowledgeDocument;
use App\Models\ApiKey;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Notification;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\WhatsappConfig;
use App\Services\Ai\Chunker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class Phase6Test extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
        ]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->user->id]);
        $this->user->update(['account_id' => $this->account->id, 'account_role' => 'owner']);
        $this->user->refresh();
    }

    private function makeConversation(): Conversation
    {
        $contact = Contact::create([
            'account_id' => $this->account->id,
            'phone' => '584125550001',
            'name' => 'Ana',
        ]);

        return Conversation::create([
            'account_id' => $this->account->id,
            'contact_id' => $contact->id,
        ]);
    }

    private function makeAiConfig(array $overrides = []): AiConfig
    {
        return AiConfig::create(array_merge([
            'account_id' => $this->account->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-test',
            'is_active' => true,
        ], $overrides));
    }

    public function test_borrador_ia_usa_el_conocimiento_y_el_historial(): void
    {
        $this->makeAiConfig();

        // Documento indexado (FULLTEXT).
        $doc = AiKnowledgeDocument::create([
            'account_id' => $this->account->id,
            'title' => 'Horarios',
            'content' => 'Nuestro horario de atencion es de lunes a viernes de 8am a 5pm.',
        ]);
        app(Chunker::class)->reindex($doc);

        $conversation = $this->makeConversation();
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'customer',
            'content_text' => '¿Cuál es su horario de atencion?',
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Atendemos de lunes a viernes de 8am a 5pm.']]],
            ]),
        ]);

        $this->actingAs($this->user)
            ->postJson("/inbox/conversations/{$conversation->id}/ai-draft")
            ->assertOk()
            ->assertJsonPath('draft', 'Atendemos de lunes a viernes de 8am a 5pm.');

        // El fragmento de la base de conocimiento llegó al modelo. Va como
        // mensaje aparte, no dentro del system: el prompt de sistema tiene que
        // ser idéntico entre consultas para que el modelo reutilice su caché.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'openai.com')) {
                return false;
            }

            $todo = collect($request['messages'])->pluck('content')->implode("\n");

            return str_contains($todo, 'horario de atencion');
        });
    }

    public function test_auto_reply_respeta_tope_y_apagado(): void
    {
        WhatsappConfig::create([
            'account_id' => $this->account->id,
            'phone_number_id' => '111222333',
            'access_token' => 'token',
            'status' => 'connected',
        ]);
        $this->makeAiConfig(['auto_reply_enabled' => true, 'auto_reply_max_per_conversation' => 1]);

        $conversation = $this->makeConversation();
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'customer',
            'content_text' => 'Hola',
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Hola, ¿en qué te ayudo?']]],
            ]),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.AI1']]]),
        ]);

        (new AiAutoReplyJob($conversation->id))->handle(app(\App\Services\Ai\ReplyGenerator::class), app(\App\Services\Channels\ChannelRouter::class));

        $this->assertSame(1, $conversation->fresh()->ai_reply_count);
        $this->assertSame(1, Message::where('sender_type', 'bot')->count());

        // Segundo intento: el tope (1) ya se alcanzó → no responde.
        (new AiAutoReplyJob($conversation->id))->handle(app(\App\Services\Ai\ReplyGenerator::class), app(\App\Services\Channels\ChannelRouter::class));
        $this->assertSame(1, Message::where('sender_type', 'bot')->count());

        // Y si un agente responde, se apaga para siempre en la conversación.
        $this->actingAs($this->user)
            ->postJson("/inbox/conversations/{$conversation->id}/send", ['text' => 'Te atiendo yo'])
            ->assertOk();
        $this->assertTrue($conversation->fresh()->ai_autoreply_disabled);
    }

    public function test_api_publica_autentica_y_respeta_scopes(): void
    {
        [, $plaintext] = ApiKey::issue($this->account->id, $this->user->id, 'test', ['contacts:read']);

        Contact::create(['account_id' => $this->account->id, 'phone' => '584125550001', 'name' => 'Ana']);

        // Sin clave → 401.
        $this->getJson('/api/v1/contacts')->assertUnauthorized();

        // Con clave y scope correcto → 200 con datos.
        $this->withToken($plaintext)
            ->getJson('/api/v1/contacts')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Ana');

        // Scope que no tiene → 403.
        $this->withToken($plaintext)
            ->postJson('/api/v1/contacts', ['phone' => '584125550002'])
            ->assertForbidden();

        // Clave revocada → 401.
        ApiKey::first()->update(['revoked_at' => now()]);
        $this->withToken($plaintext)->getJson('/api/v1/contacts')->assertUnauthorized();
    }

    public function test_api_publica_envia_mensajes(): void
    {
        WhatsappConfig::create([
            'account_id' => $this->account->id,
            'phone_number_id' => '111222333',
            'access_token' => 'token',
            'status' => 'connected',
        ]);
        [, $plaintext] = ApiKey::issue($this->account->id, $this->user->id, 'sender', ['messages:write']);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.API1']]])]);

        $this->withToken($plaintext)
            ->postJson('/api/v1/messages', ['to' => '+58 412 555 9999', 'text' => 'Hola desde la API'])
            ->assertCreated()
            ->assertJsonPath('status', 'sent');

        // El contacto y la conversación se crearon solos.
        $this->assertSame(1, Contact::where('phone_normalized', '584125559999')->count());
    }

    public function test_el_webhook_message_sent_incluye_media_id(): void
    {
        Queue::fake();

        WhatsappConfig::create([
            'account_id' => $this->account->id,
            'phone_number_id' => '111222333',
            'access_token' => 'token',
            'status' => 'connected',
        ]);
        [, $plaintext] = ApiKey::issue($this->account->id, $this->user->id, 'sender', ['messages:write']);

        // Webhook que escucha message.sent (Komo usa media_id para el proxy).
        WebhookEndpoint::create([
            'account_id' => $this->account->id,
            'url' => 'https://komo.test/webhooks/wacrm/1',
            'secret' => 'whsec_test',
            'events' => ['message.sent'],
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['id' => 'media-123', 'messages' => [['id' => 'wamid.MEDIA1']]]),
        ]);

        $this->withToken($plaintext)
            ->postJson('/api/v1/messages/media', [
                'to' => '+58 412 555 9999',
                'file_base64' => base64_encode('fake-image-bytes'),
                'mime_type' => 'image/jpeg',
                'filename' => 'foto.jpg',
                'caption' => 'Hola',
            ])
            ->assertCreated();

        Queue::assertPushed(DeliverWebhookJob::class, function ($job) {
            return $job->event === 'message.sent'
                && ($job->data['message']['media_id'] ?? null) === 'media-123';
        });
    }

    public function test_flujo_de_invitacion_completo(): void
    {
        // El owner genera un link.
        $response = $this->actingAs($this->user)
            ->post(route('team.invite'), ['role' => 'agent', 'label' => 'Ventas']);

        $inviteUrl = session('invite_url');
        $this->assertNotNull($inviteUrl);
        $token = basename(parse_url($inviteUrl, PHP_URL_PATH));

        // Un visitante nuevo abre el link y se registra.
        auth()->logout();

        $this->get("/invite/{$token}")->assertOk();

        $this->post("/invite/{$token}", [
            'name' => 'Agente Nuevo',
            'email' => 'agente@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('dashboard'));

        $agent = User::where('email', 'agente@test.com')->first();
        $this->assertSame($this->account->id, $agent->account_id);
        $this->assertSame('agent', $agent->account_role);
        $this->assertNotNull(AccountInvitation::first()->accepted_at);

        // El link ya no sirve (single-use).
        auth()->logout();
        $this->post("/invite/{$token}", [
            'name' => 'Otro',
            'email' => 'otro@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('login'));
    }

    public function test_asignar_conversacion_notifica_al_agente(): void
    {
        $agent = User::create([
            'name' => 'Agente',
            'email' => 'agente@test.com',
            'password' => bcrypt('password'),
            'account_id' => $this->account->id,
            'account_role' => 'agent',
        ]);

        $conversation = $this->makeConversation();

        $this->actingAs($this->user)
            ->patchJson("/inbox/conversations/{$conversation->id}/assign", ['agent_id' => $agent->id])
            ->assertOk();

        $notification = Notification::where('user_id', $agent->id)->first();
        $this->assertNotNull($notification);
        $this->assertSame('conversation_assigned', $notification->type);
        $this->assertStringContainsString('Ana', $notification->body);

        // Un usuario de otra cuenta no puede asignarse.
        $this->actingAs($this->user)
            ->patchJson("/inbox/conversations/{$conversation->id}/assign", ['agent_id' => (string) \Illuminate\Support\Str::uuid()])
            ->assertStatus(422);
    }

    public function test_dashboard_carga_con_metricas(): void
    {
        $this->makeConversation();

        $this->actingAs($this->user)
            ->get('/dashboard')
            ->assertOk();
    }
}
