<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aviso en vivo de mensajes entrantes (`/notifications/recent-inbound`).
 *
 * Se consulta desde toda la app, así que el scope por rol tiene que ser tan
 * estricto como el del Inbox: un agente no puede enterarse por un toast de
 * conversaciones que no le corresponden.
 */
class LiveInboundTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private User $agente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        $this->agente = User::create([
            'name' => 'Daniel', 'email' => 'daniel@test.com', 'password' => bcrypt('password'),
            'account_id' => $this->account->id, 'account_role' => User::ROLE_AGENT,
        ]);
    }

    private function conversationWithMessage(?User $agent, string $name, string $text = 'Hola'): Conversation
    {
        $contact = Contact::create([
            'account_id' => $this->account->id,
            'name' => $name,
            'phone' => '5917'.random_int(1000000, 9999999),
        ]);

        $conversation = Conversation::create([
            'account_id' => $this->account->id,
            'contact_id' => $contact->id,
            'status' => Conversation::STATUS_OPEN,
            'assigned_agent_id' => $agent?->id,
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => Message::SENDER_CUSTOMER,
            'content_text' => $text,
        ]);

        return $conversation;
    }

    private function poll(User $as, ?string $since = null): array
    {
        return $this->actingAs($as)
            ->getJson(route('notifications.recent-inbound', array_filter(['since' => $since])))
            ->assertOk()
            ->json();
    }

    public function test_devuelve_los_entrantes_nuevos_con_su_contacto(): void
    {
        $this->conversationWithMessage($this->agente, 'Ana', 'Quiero información');

        $data = $this->poll($this->agente, now()->subMinutes(5)->toIso8601String());

        $this->assertCount(1, $data['messages']);
        $this->assertSame('Ana', $data['messages'][0]['contact']);
        $this->assertSame('Quiero información', $data['messages'][0]['preview']);
        $this->assertNotEmpty($data['now'], 'El reloj lo manda el servidor.');
    }

    public function test_el_agente_no_se_entera_de_conversaciones_ajenas(): void
    {
        $this->conversationWithMessage($this->owner, 'Ajena');
        $this->conversationWithMessage(null, 'Sin asignar');
        $this->conversationWithMessage($this->agente, 'Mia');

        $data = $this->poll($this->agente, now()->subMinutes(5)->toIso8601String());

        $this->assertSame(['Mia'], collect($data['messages'])->pluck('contact')->all());
    }

    public function test_el_admin_ve_todas(): void
    {
        $this->conversationWithMessage($this->agente, 'Una');
        $this->conversationWithMessage(null, 'Otra');

        $data = $this->poll($this->owner, now()->subMinutes(5)->toIso8601String());

        $this->assertCount(2, $data['messages']);
    }

    public function test_los_salientes_no_disparan_aviso(): void
    {
        $conversation = $this->conversationWithMessage($this->agente, 'Ana');

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => Message::SENDER_AGENT,
            'sender_id' => $this->agente->id,
            'content_text' => 'Le respondo',
        ]);

        $data = $this->poll($this->agente, now()->subMinutes(5)->toIso8601String());

        // Solo el entrante del cliente: nadie quiere un toast de su propia respuesta.
        $this->assertCount(1, $data['messages']);
        $this->assertSame('Hola', $data['messages'][0]['preview']);
    }

    public function test_un_since_viejo_no_trae_una_avalancha(): void
    {
        $conversation = $this->conversationWithMessage($this->agente, 'Ana');
        Message::whereKey($conversation->messages()->first()->id)
            ->update(['created_at' => now()->subDays(3)]);

        // Pestaña dormida días: se muestra lo reciente, no tres días de historial.
        $data = $this->poll($this->agente, now()->subDays(5)->toIso8601String());

        $this->assertCount(0, $data['messages']);
    }

    public function test_los_adjuntos_se_describen_por_su_tipo(): void
    {
        $conversation = $this->conversationWithMessage($this->agente, 'Ana', '');
        $conversation->messages()->first()->update(['content_type' => 'audio', 'content_text' => null]);

        $data = $this->poll($this->agente, now()->subMinutes(5)->toIso8601String());

        $this->assertSame('🎙 Audio', $data['messages'][0]['preview']);
    }
}
