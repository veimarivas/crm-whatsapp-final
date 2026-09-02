<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Channels\ChannelRules;
use App\Services\Channels\InboundMessage;
use App\Services\Channels\Ingestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * F0/T0.2b — el motor procesa un mensaje **sin saber por dónde entró**.
 *
 * Es el pago de todo el refactor: hasta acá, procesar un mensaje exigía pasar
 * por `InboundProcessor`, que necesitaba el sobre de Meta y un `WhatsappConfig`
 * para deducir la cuenta. Un canal sin teléfono era literalmente
 * irrepresentable.
 *
 * El `InboundProcessorParityTest` prueba que WhatsApp no cambió. Este prueba
 * que ahora existe lo otro. Los dos hacen falta: sin el primero el refactor
 * sería un salto de fe; sin este, no habría servido para nada.
 */
class IngestorMultiChannelTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        config(['queue.default' => 'database']);
    }

    private function telegram(string $chatId = '99887766', array $extra = []): InboundMessage
    {
        return new InboundMessage(...array_merge([
            'accountId' => $this->account->id,
            'channel' => ChannelRules::TELEGRAM,
            'senderExternalId' => $chatId,
            'senderName' => 'Ana',
            'threadExternalId' => $chatId,
            'contentType' => 'text',
            'contentText' => 'hola por telegram',
            'externalMessageId' => 'tg-1',
        ], $extra));
    }

    public function test_un_mensaje_sin_telefono_crea_contacto_conversacion_y_mensaje(): void
    {
        app(Ingestor::class)->handle($this->telegram());

        $contact = Contact::firstOrFail();
        $this->assertSame('Ana', $contact->name);
        // Lo que era imposible antes de F0: el identificador de un contacto ERA
        // su teléfono, así que un canal que no lo trae no podía existir.
        $this->assertNull($contact->phone);

        $identity = ContactIdentity::firstOrFail();
        $this->assertSame(ChannelRules::TELEGRAM, $identity->channel);
        $this->assertSame('99887766', $identity->external_id);

        $conversation = Conversation::firstOrFail();
        $this->assertSame(ChannelRules::TELEGRAM, $conversation->channel);
        $this->assertSame('99887766', $conversation->channel_conversation_id);
        $this->assertSame('hola por telegram', $conversation->last_message_text);

        $message = Message::firstOrFail();
        $this->assertSame(ChannelRules::TELEGRAM, $message->channel);
        $this->assertSame('tg-1', $message->external_message_id);

        // `message_id` es la columna vieja de Meta y NO se escribe para otros
        // canales: hay consultas que la usan sin filtrar por canal, y un id de
        // Telegram ahí las haría matchear de más.
        $this->assertNull($message->message_id);
    }

    public function test_el_segundo_mensaje_reusa_contacto_y_conversacion(): void
    {
        $ingestor = app(Ingestor::class);

        $ingestor->handle($this->telegram());
        $ingestor->handle($this->telegram(extra: ['externalMessageId' => 'tg-2', 'contentText' => 'sigo acá']));

        $this->assertSame(1, Contact::count());
        $this->assertSame(1, Conversation::count());
        $this->assertSame(1, ContactIdentity::count());
        $this->assertSame(2, Message::count());
        $this->assertSame(2, Conversation::firstOrFail()->unread_count);
    }

    public function test_la_idempotencia_es_por_canal(): void
    {
        $ingestor = app(Ingestor::class);

        $this->assertNotNull($ingestor->handle($this->telegram()));
        // El mismo id externo, otra vez: se ignora.
        $this->assertNull($ingestor->handle($this->telegram()));

        $this->assertSame(1, Message::count());

        // Pero el MISMO id en otro canal no es una repetición: dos proveedores
        // distintos pueden emitir el mismo identificador.
        $ingestor->handle($this->telegram(extra: [
            'channel' => ChannelRules::WEBCHAT,
            'senderExternalId' => 'visitante-1',
            'threadExternalId' => 'visitante-1',
        ]));

        $this->assertSame(2, Message::count());
    }

    public function test_una_persona_en_dos_canales_tiene_un_hilo_por_canal(): void
    {
        $ingestor = app(Ingestor::class);

        // Primero escribe por Telegram: nace sin teléfono.
        $ingestor->handle($this->telegram());
        $contact = Contact::firstOrFail();

        // Después alguien le registra la identidad de WhatsApp al mismo humano
        // (es lo que hará el merge de contactos cuando exista).
        ContactIdentity::registrar($contact, ChannelRules::WHATSAPP, '584125550001');

        $ingestor->handle(new InboundMessage(
            accountId: $this->account->id,
            channel: ChannelRules::WHATSAPP,
            senderExternalId: '584125550001',
            threadExternalId: '584125550001',
            contentText: 'ahora por whatsapp',
            externalMessageId: 'wamid.1',
            phone: '584125550001',
        ));

        // Un solo contacto, un historial unificado, pero DOS hilos: el canal
        // forma parte de la identidad de la conversación.
        $this->assertSame(1, Contact::count());
        $this->assertSame(2, Conversation::count());
        $this->assertSame(2, $contact->identities()->count());
    }

    public function test_un_contacto_con_telefono_conocido_no_se_duplica(): void
    {
        // El respaldo por teléfono: el contacto existe desde antes de F0 y su
        // identidad la creó el backfill de la migración… o no la creó, si la
        // fila entró por otra vía. El camino por teléfono lo cubre igual.
        $previo = Contact::create([
            'account_id' => $this->account->id,
            'phone' => '584125550001',
            'name' => 'Ana',
        ]);

        ContactIdentity::where('contact_id', $previo->id)->delete();

        app(Ingestor::class)->handle(new InboundMessage(
            accountId: $this->account->id,
            channel: ChannelRules::WHATSAPP,
            senderExternalId: '584125550001',
            threadExternalId: '584125550001',
            contentText: 'hola',
            externalMessageId: 'wamid.1',
            phone: '584125550001',
        ));

        $this->assertSame(1, Contact::count());
        $this->assertSame($previo->id, Conversation::firstOrFail()->contact_id);
        // Y de paso le deja la identidad que le faltaba.
        $this->assertSame(1, ContactIdentity::where('contact_id', $previo->id)->count());
    }

    public function test_el_orden_de_la_cola_es_el_mismo_para_cualquier_canal(): void
    {
        app(Ingestor::class)->handle($this->telegram());

        $cola = DB::table('jobs')->orderBy('id')->pluck('payload')
            ->map(fn ($p) => class_basename(json_decode($p, true)['displayName'] ?? '?'))
            ->all();

        // El mismo contrato que fija `InboundProcessorParityTest` para
        // WhatsApp: la IA siempre al final. El orden es del motor, no del canal.
        $this->assertSame([
            'ProcessFlowMessageJob',
            'ProcessAutomationEventJob',  // new_contact
            'ProcessAutomationEventJob',  // inbound_message
            'ProcessAutomationEventJob',  // keyword
            'AiAutoReplyJob',
        ], $cola);
    }
}
