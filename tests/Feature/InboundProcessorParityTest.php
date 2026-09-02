<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AiReplyAttempt;
use App\Models\AutoTagRule;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Deal;
use App\Models\Message;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Tag;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\WhatsappConfig;
use App\Services\WhatsApp\InboundProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F0/T0.2b — test de CARACTERIZACIÓN del camino de entrada.
 *
 * **Se escribe ANTES de tocar `InboundProcessor`, y por eso no juzga si el
 * comportamiento actual está bien: lo FIJA.** El plan omnicanal va a extraer
 * todo lo que hoy vive de `DB::transaction` para abajo a un `Ingestor`
 * canal-agnóstico, y ese es —según el propio plan— «el cambio más riesgoso de
 * F0». Este test es lo único que después va a poder decir si el refactor
 * cambió algo.
 *
 * Un test de caracterización tiene una regla que lo distingue de los demás: si
 * falla durante el refactor, **el sospechoso es el refactor, no el test**.
 * Cambiarlo para que pase es exactamente lo que no hay que hacer.
 *
 * ## Lo más importante que fija: el ORDEN de la cola
 *
 * La cola es FIFO y el orden de encolado es una decisión deliberada, no un
 * accidente de escritura. Está documentada en el propio `InboundProcessor` y
 * viene de un bug histórico: con la IA encolada primero, el CRM externo veía
 * el mensaje del cliente **60 segundos tarde**, porque esperaba a que Ollama
 * terminara. El orden correcto es:
 *
 *   1. webhooks a integraciones (ligeros, tienen que salir YA)
 *   2. transcripción de audio
 *   3. flows y automatizaciones (reglas locales, rápidas)
 *   4. la IA al final (30-60 s con Qwen)
 *
 * Para capturar ese orden **no sirve `Queue::fake()`**: agrupa los jobs por
 * clase y pierde la secuencia global, que es justo el dato que importa. Se usa
 * la cola de base de datos y se lee la tabla `jobs` por `id`, que es el
 * mecanismo real.
 */
class InboundProcessorParityTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private WhatsappConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        $this->config = WhatsappConfig::create([
            'account_id' => $this->account->id,
            'phone_number_id' => '111222333',
            'access_token' => 'token',
            'status' => 'connected',
        ]);

        // La cola real (base de datos) en vez del doble: es el único modo de
        // leer el ORDEN global de encolado, que es lo que hay que congelar.
        config(['queue.default' => 'database']);

        Http::fake();
    }

    /** Sobre de Meta con un mensaje de texto, tal como llega del webhook. */
    private function sobre(array $message, array $contacts = []): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => '111222333'],
                        'contacts' => $contacts ?: [['wa_id' => '584125550001', 'profile' => ['name' => 'Ana']]],
                        'messages' => [$message],
                    ],
                ]],
            ]],
        ];
    }

    private function texto(string $text = 'hola', array $extra = []): array
    {
        return array_merge([
            'id' => 'wamid.'.Str::random(10),
            'from' => '584125550001',
            'type' => 'text',
            'text' => ['body' => $text],
        ], $extra);
    }

    private function procesar(array $payload): void
    {
        app(InboundProcessor::class)->process($payload);
    }

    /**
     * Los jobs encolados, EN ORDEN, por su nombre corto.
     *
     * @return array<int, string>
     */
    private function colaEnOrden(): array
    {
        return DB::table('jobs')->orderBy('id')->pluck('payload')
            ->map(fn ($p) => class_basename(json_decode($p, true)['displayName'] ?? '?'))
            ->all();
    }

    private function suscribirWebhook(array $events): void
    {
        WebhookEndpoint::create([
            'account_id' => $this->account->id,
            'url' => 'https://komo.test/webhooks/wacrm/x',
            'secret' => 'whsec_s',
            'events' => $events,
            'is_active' => true,
        ]);
    }

    // ── El orden de la cola ────────────────────────────────────────────────

    public function test_contacto_nuevo_encola_en_el_orden_documentado(): void
    {
        $this->suscribirWebhook(['contact.created', 'message.received']);

        $this->procesar($this->sobre($this->texto()));

        // Este array ES el contrato. Si el refactor lo cambia, cambia el
        // comportamiento observable del sistema, no un detalle interno.
        $this->assertSame([
            'DeliverWebhookJob',        // 1. contact.created
            'DeliverWebhookJob',        // 2. message.received
            'ProcessFlowMessageJob',    // 3. flows
            'ProcessAutomationEventJob', // 4. new_contact
            'ProcessAutomationEventJob', // 5. inbound_message
            'ProcessAutomationEventJob', // 6. keyword
            'AiAutoReplyJob',           // 7. la IA SIEMPRE al final
        ], $this->colaEnOrden());
    }

    public function test_contacto_conocido_no_encola_los_jobs_de_alta(): void
    {
        $this->suscribirWebhook(['contact.created', 'message.received']);

        Contact::create([
            'account_id' => $this->account->id,
            'phone' => '584125550001',
            'name' => 'Ana',
        ]);

        $this->procesar($this->sobre($this->texto()));

        // Sin `contact.created` y sin la automatización `new_contact`.
        $this->assertSame([
            'DeliverWebhookJob',
            'ProcessFlowMessageJob',
            'ProcessAutomationEventJob',
            'ProcessAutomationEventJob',
            'AiAutoReplyJob',
        ], $this->colaEnOrden());
    }

    public function test_un_audio_difiere_la_ia_hasta_tener_la_transcripcion(): void
    {
        $this->suscribirWebhook(['message.received']);

        $this->procesar($this->sobre($this->texto(extra: [
            'type' => 'audio',
            'audio' => ['id' => 'media-123'],
            'text' => null,
        ])));

        $cola = $this->colaEnOrden();

        // La transcripción va después del webhook y antes de los flows.
        $this->assertSame('TranscribeAudioJob', $cola[1]);

        // Y la IA NO se encola: contestaría a un audio que no escuchó. La
        // encola `TranscribeAudioJob` cuando guarda el transcript.
        $this->assertNotContains('AiAutoReplyJob', $cola);
    }

    public function test_sin_webhooks_suscritos_la_cola_arranca_por_los_flows(): void
    {
        // Nadie suscrito: no hay DeliverWebhookJob, pero el resto del orden se
        // conserva. Fija que el orden no dependa de que exista una integración.
        $this->procesar($this->sobre($this->texto()));

        $this->assertSame([
            'ProcessFlowMessageJob',
            'ProcessAutomationEventJob',
            'ProcessAutomationEventJob',
            'ProcessAutomationEventJob',
            'AiAutoReplyJob',
        ], $this->colaEnOrden());
    }

    // ── El estado que deja en la base ──────────────────────────────────────

    public function test_crea_contacto_conversacion_y_mensaje(): void
    {
        $this->procesar($this->sobre($this->texto('quiero información')));

        $contact = Contact::firstOrFail();
        $this->assertSame('584125550001', $contact->phone_normalized);
        $this->assertSame('Ana', $contact->name);

        $conversation = Conversation::firstOrFail();
        $this->assertSame($contact->id, $conversation->contact_id);
        $this->assertSame('open', $conversation->status);
        $this->assertSame(1, $conversation->unread_count);
        $this->assertSame('quiero información', $conversation->last_message_text);

        $message = Message::firstOrFail();
        $this->assertSame(Message::SENDER_CUSTOMER, $message->sender_type);
        $this->assertSame('text', $message->content_type);
        $this->assertSame('quiero información', $message->content_text);
        $this->assertSame('delivered', $message->status);
    }

    public function test_el_mismo_wamid_dos_veces_no_duplica_nada(): void
    {
        // Meta reintenta entregas: la idempotencia es la que evita que el
        // cliente reciba dos veces la respuesta de la IA.
        $mensaje = $this->texto();

        $this->procesar($this->sobre($mensaje));
        $this->procesar($this->sobre($mensaje));

        $this->assertSame(1, Message::count());
        $this->assertSame(1, Conversation::firstOrFail()->unread_count);
        $this->assertCount(5, $this->colaEnOrden());
    }

    public function test_no_reinicia_el_contador_de_respuestas_de_la_ia(): void
    {
        // Regresión histórica: al reiniciarlo con cada entrante, el tope de
        // «máximo N respuestas por conversación» no limitaba nada.
        $contact = Contact::create(['account_id' => $this->account->id, 'phone' => '584125550001']);
        $conversation = Conversation::create([
            'account_id' => $this->account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'ai_reply_count' => 3,
        ]);

        $this->procesar($this->sobre($this->texto()));

        $this->assertSame(3, $conversation->fresh()->ai_reply_count);
    }

    public function test_el_anuncio_de_entrada_no_se_pisa(): void
    {
        // La atribución original es la que vale: si el contacto vuelve por otro
        // anuncio, el primero sigue explicando de dónde salió el negocio.
        $this->procesar($this->sobre($this->texto(extra: [
            'referral' => ['source_id' => 'AD_PRIMERO', 'headline' => 'Maestrías'],
        ])));

        $this->procesar($this->sobre($this->texto(extra: [
            'referral' => ['source_id' => 'AD_SEGUNDO'],
        ])));

        $this->assertSame('AD_PRIMERO', Conversation::firstOrFail()->entry_ad_id);
    }

    public function test_responder_a_un_mensaje_lo_enlaza(): void
    {
        $primero = $this->texto('primero');
        $this->procesar($this->sobre($primero));

        $this->procesar($this->sobre($this->texto('segundo', [
            'context' => ['id' => $primero['id']],
        ])));

        $original = Message::where('message_id', $primero['id'])->firstOrFail();
        $respuesta = Message::where('content_text', 'segundo')->firstOrFail();

        $this->assertSame($original->id, $respuesta->reply_to_message_id);
    }

    public function test_aplica_las_reglas_de_auto_etiquetado(): void
    {
        $tag = Tag::create(['account_id' => $this->account->id, 'name' => 'Precio']);
        AutoTagRule::create([
            'account_id' => $this->account->id,
            'tag_id' => $tag->id,
            'keyword' => 'cuanto cuesta',
        ]);

        $this->procesar($this->sobre($this->texto('hola, cuanto cuesta la maestría?')));

        $this->assertSame(1, Contact::firstOrFail()->tags()->count());
    }

    public function test_correlaciona_la_respuesta_con_el_broadcast(): void
    {
        $contact = Contact::create(['account_id' => $this->account->id, 'phone' => '584125550001']);

        $broadcast = Broadcast::create([
            'account_id' => $this->account->id,
            'name' => 'Campaña',
            'template_name' => 'promo',
            'template_language' => 'es',
            'status' => 'sent',
            'total_recipients' => 1,
            'sent_count' => 1,
        ]);

        $recipient = BroadcastRecipient::create([
            'broadcast_id' => $broadcast->id,
            'contact_id' => $contact->id,
            'phone' => '584125550001',
            'status' => 'sent',
            'sent_at' => now()->subHour(),
        ]);

        $this->procesar($this->sobre($this->texto('me interesa')));

        $this->assertSame('replied', $recipient->fresh()->status);
        $this->assertNotNull($recipient->fresh()->replied_at);
        $this->assertSame(1, $broadcast->fresh()->replied_count);
    }

    public function test_un_contacto_nuevo_estrena_deal_en_el_pipeline_por_defecto(): void
    {
        $pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas', 'is_default' => true]);
        PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Nuevo', 'position' => 0]);

        $this->procesar($this->sobre($this->texto()));

        $deal = Deal::firstOrFail();
        $this->assertSame($pipeline->id, $deal->pipeline_id);
        $this->assertSame(Conversation::firstOrFail()->id, $deal->conversation_id);

        // Y el segundo mensaje del mismo contacto no crea otro.
        $this->procesar($this->sobre($this->texto('otra vez')));
        $this->assertSame(1, Deal::count());
    }

    public function test_deja_registro_de_que_la_ia_se_encolo(): void
    {
        // Sin esta fila, «el job corrió y decidió callarse» y «el job nunca
        // corrió» se ven igual: un registro vacío. Son problemas distintos.
        $this->procesar($this->sobre($this->texto()));

        $this->assertSame(1, AiReplyAttempt::count());
        $this->assertSame('encolada', AiReplyAttempt::firstOrFail()->decision);
    }

    public function test_un_phone_number_id_desconocido_no_hace_nada(): void
    {
        $payload = $this->sobre($this->texto());
        $payload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] = 'otro';

        $this->procesar($payload);

        $this->assertSame(0, Contact::count());
        $this->assertSame(0, Message::count());
        $this->assertSame([], $this->colaEnOrden());
    }

    public function test_el_payload_del_webhook_lleva_lo_que_komo_necesita(): void
    {
        $this->suscribirWebhook(['message.received']);

        $this->procesar($this->sobre($this->texto('hola', [
            'referral' => ['source_id' => 'AD_1'],
        ])));

        $payload = json_decode(DB::table('jobs')->orderBy('id')->value('payload'), true);
        $job = unserialize($payload['data']['command']);

        // El contrato con Komo: si el refactor deja de mandar una de estas
        // claves, allá se rompe algo y acá no falla nada.
        $this->assertSame('message.received', $job->event);
        $this->assertArrayHasKey('conversation_id', $job->data);
        $this->assertArrayHasKey('contact', $job->data);
        $this->assertSame(['id', 'type', 'text', 'wamid', 'media_id', 'referral'], array_keys($job->data['message']));
        $this->assertSame('AD_1', $job->data['message']['referral']['source_id']);

        // T0.3 — campos AGREGADOS al contrato (el resto no cambió). Un receptor
        // viejo los ignora sin romperse; uno nuevo deja de asumir WhatsApp y
        // puede resolver un contacto sin teléfono.
        $this->assertSame('whatsapp', $job->data['channel']);
        $this->assertSame('584125550001', $job->data['contact']['channel_external_id']);
    }
}
