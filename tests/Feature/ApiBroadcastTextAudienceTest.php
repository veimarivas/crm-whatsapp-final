<?php

namespace Tests\Feature;

use App\Jobs\SendBroadcastJob;
use App\Models\Account;
use App\Models\ApiKey;
use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WhatsappConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * D1a — el motor de broadcasts acepta cuerpo de texto y audiencia por
 * teléfonos, que es lo que le permite a Komo apagar su motor paralelo.
 *
 * Lo que estos tests fijan, en orden de importancia:
 *
 *  1. Un mensaje de texto NO sale a quien tiene la ventana cerrada. Ese era el
 *     agujero: el motor de Komo lo intentaba igual, Meta lo rechazaba o lo
 *     cobraba como plantilla, y nadie se enteraba.
 *  2. La ventana se vuelve a mirar EN EL ENVÍO, no solo al crear: entre que se
 *     programa un broadcast y sale, la ventana se cierra sola.
 *  3. Un teléfono que este proyecto no conoce no rompe el envío del resto —
 *     Komo manda audiencias con gente que llegó por formulario o correo.
 *  4. El camino viejo (plantilla, audiencia all/tags) no cambió.
 */
class ApiBroadcastTextAudienceTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        WhatsappConfig::create([
            'account_id' => $this->account->id,
            'phone_number_id' => '111',
            'access_token' => 'token',
            'status' => 'connected',
        ]);

        [, $this->token] = ApiKey::issue($this->account->id, $this->owner->id, 'komo', ['broadcasts:write', 'broadcasts:read']);
    }

    /**
     * Contacto con conversación. `$lastInboundHoursAgo` decide la ventana: a 2 h
     * está abierta, a 30 h ya se cerró (la de servicio dura 24).
     */
    private function contact(string $phone, string $name, ?int $lastInboundHoursAgo = 2): Contact
    {
        $contact = Contact::create([
            'account_id' => $this->account->id,
            'phone' => $phone,
            'name' => $name,
        ]);

        if ($lastInboundHoursAgo !== null) {
            $conversation = Conversation::create([
                'account_id' => $this->account->id,
                'contact_id' => $contact->id,
                'status' => 'open',
            ]);

            $message = Message::create([
                'account_id' => $this->account->id,
                'conversation_id' => $conversation->id,
                'sender_type' => Message::SENDER_CUSTOMER,
                'content_type' => 'text',
                'content_text' => 'hola',
            ]);

            // `created_at` no es fillable: hay que pisarlo después de crear, o
            // el mensaje queda con la hora del test y la ventana siempre abierta.
            $message->forceFill(['created_at' => now()->subHours($lastInboundHoursAgo)])->save();
        }

        return $contact;
    }

    public function test_crea_broadcast_de_texto_por_telefonos_y_descarta_a_los_de_ventana_cerrada(): void
    {
        Queue::fake();

        $this->contact('584125550001', 'Ana', lastInboundHoursAgo: 2);   // dentro
        $this->contact('584125550002', 'Beto', lastInboundHoursAgo: 30); // fuera

        $response = $this->withToken($this->token)->postJson('/api/v1/broadcasts', [
            'name' => 'Aviso de inicio',
            'body_type' => 'text',
            'body_text' => 'Hola {name}, arrancamos el lunes.',
            'audience' => 'phones',
            'recipients' => [
                ['phone' => '584125550001', 'external_ref' => 'lead-1'],
                ['phone' => '584125550002', 'external_ref' => 'lead-2'],
                // Nunca escribió por WhatsApp: no existe como contacto acá.
                ['phone' => '584125550003', 'external_ref' => 'lead-3'],
            ],
        ])->assertCreated();

        // El informe es la parte que Komo muestra en pantalla: pedidos 3, sale 1.
        $response->assertJsonPath('report.requested', 3)
            ->assertJsonPath('report.unknown_contacts', 1)
            ->assertJsonPath('report.out_of_window', 2)
            ->assertJsonPath('report.sending_to', 1);

        // La lista de excluidos con el motivo por teléfono: es lo que le permite
        // a Komo marcar SUS filas en vez de mostrar un total sin detalle.
        $excluded = collect($response->json('report.excluded'))->keyBy('phone');

        $this->assertSame('ventana_cerrada', $excluded['584125550002']['reason']);
        $this->assertSame('sin_conversacion', $excluded['584125550003']['reason']);
        $this->assertSame('lead-2', $excluded['584125550002']['external_ref']);

        $broadcast = Broadcast::firstOrFail();

        $this->assertSame(1, $broadcast->total_recipients);
        $this->assertSame(['584125550001'], $broadcast->recipients()->pluck('phone')->all());
        // El id del lead de Komo viaja de vuelta: sin él, correlacionar la fila
        // obligaría a adivinar por teléfono.
        $this->assertSame('lead-1', $broadcast->recipients()->value('external_ref'));

        Queue::assertPushed(SendBroadcastJob::class);
    }

    public function test_texto_a_una_audiencia_entera_fuera_de_ventana_es_422_con_motivo(): void
    {
        $this->contact('584125550002', 'Beto', lastInboundHoursAgo: 30);

        $this->withToken($this->token)->postJson('/api/v1/broadcasts', [
            'name' => 'Aviso',
            'body_type' => 'text',
            'body_text' => 'Hola',
            'audience' => 'phones',
            'recipients' => [['phone' => '584125550002']],
        ])
            ->assertStatus(422)
            // El motivo tiene que decir qué hacer, no solo que falló.
            ->assertJsonPath('message', 'Ningún destinatario tiene la ventana de 24 h abierta: un mensaje de texto no les llegaría. Usá una plantilla aprobada.');

        $this->assertSame(0, Broadcast::count());
    }

    public function test_la_ventana_se_revisa_de_nuevo_al_enviar(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

        $contact = $this->contact('584125550001', 'Ana', lastInboundHoursAgo: 2);

        $this->withToken($this->token)->postJson('/api/v1/broadcasts', [
            'name' => 'Aviso',
            'body_type' => 'text',
            'body_text' => 'Hola',
            'audience' => 'phones',
            'recipients' => [['phone' => '584125550001']],
            // Programado: se crea ahora y sale después.
            'scheduled_at' => now()->addHour()->toIso8601String(),
        ])->assertCreated();

        $broadcast = Broadcast::firstOrFail();
        $this->assertSame(1, $broadcast->total_recipients);

        // Entre el alta y el envío, la ventana se cerró: el entrante que la
        // sostenía pasa a tener 30 h.
        Message::where('conversation_id', $contact->conversations()->value('id'))
            ->update(['created_at' => now()->subHours(30)]);

        (new SendBroadcastJob($broadcast->id))->handle();

        $broadcast->refresh();

        $this->assertSame(0, $broadcast->sent_count);
        $this->assertSame(1, $broadcast->failed_count);
        $this->assertStringContainsString('Ventana de 24 h cerrada', $broadcast->recipients()->value('error_message'));

        // Y sobre todo: no se gastó una llamada a Meta.
        Http::assertNothingSent();
    }

    public function test_una_plantilla_si_sale_fuera_de_ventana(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

        MessageTemplate::create([
            'account_id' => $this->account->id,
            'name' => 'recordatorio',
            'language' => 'es',
            'body_text' => 'Hola {{1}}',
            'status' => 'APPROVED',
        ]);

        $this->contact('584125550002', 'Beto', lastInboundHoursAgo: 30);

        $this->withToken($this->token)->postJson('/api/v1/broadcasts', [
            'name' => 'Recordatorio',
            'body_type' => 'template',
            'template_name' => 'recordatorio',
            'template_language' => 'es',
            'template_variables' => ['{name}'],
            'audience' => 'phones',
            'recipients' => [['phone' => '584125550002']],
        ])->assertCreated()
            // Con plantilla la ventana no recorta: se puede escribir igual.
            ->assertJsonPath('report.out_of_window', 0)
            ->assertJsonPath('report.sending_to', 1);

        $broadcast = Broadcast::firstOrFail();
        (new SendBroadcastJob($broadcast->id))->handle();

        $this->assertSame(1, $broadcast->refresh()->sent_count);
    }

    public function test_el_contrato_viejo_no_cambio(): void
    {
        Queue::fake();

        MessageTemplate::create([
            'account_id' => $this->account->id,
            'name' => 'promo',
            'language' => 'es',
            'body_text' => 'Hola',
            'status' => 'APPROVED',
        ]);

        $this->contact('584125550001', 'Ana');

        // Sin `body_type` ni `recipients`: exactamente el payload de antes de D1a.
        $this->withToken($this->token)->postJson('/api/v1/broadcasts', [
            'name' => 'Promo',
            'template_name' => 'promo',
            'template_language' => 'es',
            'audience' => 'all',
        ])->assertCreated();

        $broadcast = Broadcast::firstOrFail();

        $this->assertSame('template', $broadcast->body_type);
        $this->assertSame(1, $broadcast->total_recipients);
    }

    public function test_texto_sin_cuerpo_no_valida(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/broadcasts', [
            'name' => 'Aviso',
            'body_type' => 'text',
            'audience' => 'phones',
            'recipients' => [['phone' => '584125550001']],
        ])->assertStatus(422)->assertJsonValidationErrors('body_text');
    }

    public function test_el_detalle_agrupa_los_motivos_de_fallo(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

        $this->contact('584125550001', 'Ana', lastInboundHoursAgo: 2);

        MessageTemplate::create([
            'account_id' => $this->account->id,
            'name' => 'promo',
            'language' => 'es',
            'body_text' => 'Hola',
            'status' => 'APPROVED',
        ]);

        // Plantilla a un teléfono desconocido: se registra sin contacto y sale.
        $this->withToken($this->token)->postJson('/api/v1/broadcasts', [
            'name' => 'Promo',
            'body_type' => 'template',
            'template_name' => 'promo',
            'template_language' => 'es',
            'audience' => 'phones',
            'recipients' => [['phone' => '584125559999']],
        ])->assertCreated();

        $broadcast = Broadcast::firstOrFail();
        (new SendBroadcastJob($broadcast->id))->handle();

        $this->withToken($this->token)->getJson("/api/v1/broadcasts/{$broadcast->id}")
            ->assertOk()
            ->assertJsonPath('report.unknown_contacts', 1)
            ->assertJsonPath('recipients_by_status.sent', 1);
    }
}
