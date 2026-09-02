<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Channels\ChannelRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F0/T0.5 — el Inbox distingue canales.
 *
 * **El filtro va en el servidor.** El listado se corta en 100 conversaciones,
 * así que filtrar en pantalla mostraría «3 de Telegram» cuando en realidad hay
 * treinta más que nunca se pidieron — un número que se lee como un dato y es
 * un artefacto del recorte.
 */
class InboxChannelFilterTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);
    }

    private function conversacion(string $channel, string $phone, ?int $inboundHoursAgo = 2): Conversation
    {
        $contact = Contact::create([
            'account_id' => $this->account->id,
            'phone' => $phone,
            'name' => 'Contacto '.$phone,
        ]);

        $conversation = Conversation::create([
            'account_id' => $this->account->id,
            'contact_id' => $contact->id,
            'channel' => $channel,
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        if ($inboundHoursAgo !== null) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'channel' => $channel,
                'sender_type' => Message::SENDER_CUSTOMER,
                'content_type' => 'text',
                'content_text' => 'hola',
            ]);
            $message->forceFill(['created_at' => now()->subHours($inboundHoursAgo)])->save();
        }

        return $conversation;
    }

    public function test_filtra_por_canal_en_el_servidor(): void
    {
        $this->conversacion(ChannelRules::WHATSAPP, '584125550001');
        $tg = $this->conversacion(ChannelRules::TELEGRAM, '584125550002');

        $this->actingAs($this->owner)
            ->getJson(route('inbox.conversations', ['channel' => 'telegram']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $tg->id);

        // Sin filtro vuelven las dos.
        $this->actingAs($this->owner)
            ->getJson(route('inbox.conversations'))
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_el_canal_viaja_en_cada_conversacion(): void
    {
        $this->conversacion(ChannelRules::TELEGRAM, '584125550002');

        $this->actingAs($this->owner)
            ->getJson(route('inbox.conversations'))
            ->assertOk()
            ->assertJsonPath('0.channel', 'telegram');
    }

    public function test_la_ventana_del_listado_respeta_el_canal(): void
    {
        // El mismo escenario en los dos canales: un entrante de hace 30 h.
        $this->conversacion(ChannelRules::WHATSAPP, '584125550001', inboundHoursAgo: 30);
        $this->conversacion(ChannelRules::TELEGRAM, '584125550002', inboundHoursAgo: 30);

        $data = collect($this->actingAs($this->owner)
            ->getJson(route('inbox.conversations'))
            ->assertOk()
            ->json())->keyBy('channel');

        // En WhatsApp la ventana de 24 h venció; en Telegram no hay ventana que
        // vencer. Sin el canal en el cálculo, el hilo de Telegram mostraría una
        // cuenta regresiva inventada y diría «Cerrada».
        $this->assertFalse($data['whatsapp']['service_window']['is_open']);
        $this->assertTrue($data['telegram']['service_window']['is_open']);
        $this->assertNull($data['telegram']['service_window']['window_hours']);
    }

    public function test_un_canal_inexistente_devuelve_vacio_y_no_rompe(): void
    {
        $this->conversacion(ChannelRules::WHATSAPP, '584125550001');

        $this->actingAs($this->owner)
            ->getJson(route('inbox.conversations', ['channel' => 'canal_del_futuro']))
            ->assertOk()
            ->assertJsonCount(0);
    }
}
