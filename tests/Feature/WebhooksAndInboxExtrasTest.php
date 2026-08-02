<?php

namespace Tests\Feature;

use App\Jobs\DeliverWebhookJob;
use App\Models\Account;
use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\WhatsappConfig;
use App\Services\Webhooks\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhooksAndInboxExtrasTest extends TestCase
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

    private function makeEndpoint(array $events = ['message.received'], string $secret = 'whsec_test'): WebhookEndpoint
    {
        return WebhookEndpoint::create([
            'account_id' => $this->account->id,
            'url' => 'https://hooks.cliente.test/crm',
            'secret' => $secret,
            'events' => $events,
        ]);
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

    public function test_dispatcher_solo_notifica_endpoints_suscritos_y_activos(): void
    {
        Queue::fake();

        $suscrito = $this->makeEndpoint(['message.received']);
        $otroEvento = $this->makeEndpoint(['broadcast.completed']);
        $inactivo = $this->makeEndpoint(['message.received']);
        $inactivo->update(['is_active' => false]);

        app(Dispatcher::class)->dispatch($this->account->id, 'message.received', ['x' => 1]);

        Queue::assertPushed(DeliverWebhookJob::class, 1);
        Queue::assertPushed(DeliverWebhookJob::class, fn ($job) => $job->endpointId === $suscrito->id);
    }

    public function test_entrega_firmada_y_contador_de_fallos(): void
    {
        $endpoint = $this->makeEndpoint(secret: 'whsec_secreto');

        Http::fake(['hooks.cliente.test/*' => Http::response(['ok' => true])]);

        (new DeliverWebhookJob($endpoint->id, 'message.received', ['hola' => 'mundo']))->handle();

        Http::assertSent(function ($request) {
            $expected = 'sha256='.hash_hmac('sha256', $request->body(), 'whsec_secreto');

            return $request->url() === 'https://hooks.cliente.test/crm'
                && $request->header('X-Webhook-Signature')[0] === $expected
                && $request->header('X-Webhook-Event')[0] === 'message.received';
        });

        $endpoint->refresh();
        $this->assertNotNull($endpoint->last_delivery_at);
        $this->assertSame(0, $endpoint->failure_count);

        // Receptor que siempre falla: acumula fallos y a los 10 se
        // desactiva. (Host distinto: los stubs de Http::fake se acumulan
        // y el primero que coincide gana.)
        Http::fake(['hooks.caido.test/*' => Http::response('error', 500)]);
        $caido = WebhookEndpoint::create([
            'account_id' => $this->account->id,
            'url' => 'https://hooks.caido.test/crm',
            'secret' => 'whsec_x',
            'events' => ['message.received'],
        ]);

        for ($i = 0; $i < 10; $i++) {
            (new DeliverWebhookJob($caido->id, 'message.received', []))->handle();
        }

        $caido->refresh();
        $this->assertSame(10, $caido->failure_count);
        $this->assertFalse($caido->is_active);
    }

    public function test_mensaje_entrante_dispara_webhooks_salientes(): void
    {
        Queue::fake();
        config(['services.meta.app_secret' => 'secret']);

        WhatsappConfig::create([
            'account_id' => $this->account->id,
            'phone_number_id' => '111222333',
            'access_token' => 'token',
            'status' => 'connected',
        ]);
        $this->makeEndpoint(['message.received', 'contact.created']);

        $payload = json_encode([
            'entry' => [['changes' => [['field' => 'messages', 'value' => [
                'metadata' => ['phone_number_id' => '111222333'],
                'contacts' => [['profile' => ['name' => 'Nuevo'], 'wa_id' => '584125559999']],
                'messages' => [[
                    'from' => '584125559999',
                    'id' => 'wamid.WH1',
                    'type' => 'text',
                    'text' => ['body' => 'hola'],
                ]],
            ]]]]],
        ]);

        $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $payload, 'secret'),
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertOk();

        // contact.created + message.received (contacto nuevo).
        Queue::assertPushed(DeliverWebhookJob::class, 2);
    }

    public function test_un_sticker_entrante_se_guarda_como_sticker(): void
    {
        Queue::fake();
        config(['services.meta.app_secret' => 'secret']);

        WhatsappConfig::create([
            'account_id' => $this->account->id,
            'phone_number_id' => '111222333',
            'access_token' => 'token',
            'status' => 'connected',
        ]);

        $payload = json_encode([
            'entry' => [['changes' => [['field' => 'messages', 'value' => [
                'metadata' => ['phone_number_id' => '111222333'],
                'contacts' => [['profile' => ['name' => 'Ana'], 'wa_id' => '584125559999']],
                'messages' => [[
                    'from' => '584125559999',
                    'id' => 'wamid.STK1',
                    'type' => 'sticker',
                    'sticker' => ['mime_type' => 'image/webp', 'id' => 'sticker-media-123'],
                ]],
            ]]]]],
        ]);

        $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $payload, 'secret'),
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertOk();

        $msg = Message::where('message_id', 'wamid.STK1')->first();
        $this->assertNotNull($msg);
        $this->assertSame('sticker', $msg->content_type);
        $this->assertNull($msg->content_text, 'Un sticker no trae texto.');
        $this->assertSame('sticker-media-123', $msg->media_url);
    }

    public function test_crud_de_webhooks_desde_la_ui(): void
    {
        $this->actingAs($this->user)
            ->post(route('team.webhooks.store'), [
                'url' => 'https://miapp.test/hook',
                'events' => ['message.received'],
            ])
            ->assertRedirect()
            ->assertSessionHas('webhook_secret', fn ($s) => str_starts_with($s, 'whsec_'));

        $hook = WebhookEndpoint::first();
        $secretoPlano = session('webhook_secret');
        $this->assertSame($secretoPlano, $hook->secret); // cast encrypted descifra

        // URL http (no https) se rechaza.
        $this->actingAs($this->user)
            ->post(route('team.webhooks.store'), [
                'url' => 'http://inseguro.test/hook',
                'events' => ['message.received'],
            ])
            ->assertSessionHasErrors('url');

        $this->actingAs($this->user)
            ->post(route('team.webhooks.toggle', $hook))
            ->assertRedirect();
        $this->assertFalse($hook->fresh()->is_active);
    }

    public function test_rate_limit_de_la_api_publica(): void
    {
        [, $plaintext] = \App\Models\ApiKey::issue($this->account->id, $this->user->id, 't', ['contacts:read']);

        for ($i = 0; $i < 120; $i++) {
            $this->withToken($plaintext)->getJson('/api/v1/contacts')->assertOk();
        }

        $this->withToken($plaintext)->getJson('/api/v1/contacts')->assertStatus(429);
    }

    public function test_responder_citando_y_reaccionar(): void
    {
        WhatsappConfig::create([
            'account_id' => $this->account->id,
            'phone_number_id' => '111222333',
            'access_token' => 'token',
            'status' => 'connected',
        ]);
        $conversation = $this->makeConversation();
        $customerMsg = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'customer',
            'content_text' => '¿Tienen envíos?',
            'message_id' => 'wamid.C1',
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.R1']]])]);

        // Enviar citando el mensaje del cliente.
        $this->actingAs($this->user)
            ->postJson("/inbox/conversations/{$conversation->id}/send", [
                'text' => 'Sí, a todo el país',
                'reply_to_message_id' => $customerMsg->id,
            ])
            ->assertOk()
            ->assertJsonPath('reply_to_message_id', $customerMsg->id);

        // Meta recibió el context con el wamid citado.
        Http::assertSent(fn ($request) => ($request['context']['message_id'] ?? null) === 'wamid.C1'
            || ! str_contains($request->url(), '/messages'));

        // Reaccionar al mensaje del cliente.
        $this->actingAs($this->user)
            ->postJson("/inbox/messages/{$customerMsg->id}/react", ['emoji' => '👍'])
            ->assertOk();

        $reaction = MessageReaction::where('message_id', $customerMsg->id)->first();
        $this->assertSame('agent', $reaction->actor_type);
        $this->assertSame('👍', $reaction->emoji);
    }

    public function test_notas_del_contacto(): void
    {
        $conversation = $this->makeConversation();

        $this->actingAs($this->user)
            ->postJson("/inbox/conversations/{$conversation->id}/notes", ['text' => 'Cliente frecuente, prefiere llamadas por la tarde'])
            ->assertCreated();

        $this->actingAs($this->user)
            ->getJson("/inbox/conversations/{$conversation->id}/notes")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.author.name', 'Admin');

        $this->assertSame(1, ContactNote::where('contact_id', $conversation->contact_id)->count());
    }
}
