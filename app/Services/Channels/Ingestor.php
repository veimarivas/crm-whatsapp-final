<?php

namespace App\Services\Channels;

use App\Jobs\AiAutoReplyJob;
use App\Jobs\ProcessAutomationEventJob;
use App\Jobs\ProcessFlowMessageJob;
use App\Jobs\TranscribeAudioJob;
use App\Models\AiReplyAttempt;
use App\Models\AutoTagRule;
use App\Models\BroadcastRecipient;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Deal;
use App\Models\Message;
use App\Models\Pipeline;
use App\Services\Webhooks\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Lo que pasa cuando entra un mensaje, **sin importar por dónde entró**.
 *
 * Extraído de `InboundProcessor::handleInboundMessage()` en F0/T0.2b. Aquel
 * método hacía dos cosas mezcladas: parsear el sobre de Meta y procesar el
 * mensaje. Lo primero es específico de WhatsApp; lo segundo —contacto,
 * conversación, guardado, broadcasts, auto-etiquetas y el orden de los jobs—
 * es igual para Telegram, Messenger o un widget web. Sin esta separación, cada
 * canal nuevo habría copiado ese método entero.
 *
 * **El orden de encolado es parte del contrato, no un detalle.** La cola es
 * FIFO y viene de un bug histórico: con la IA primero, el CRM externo veía el
 * mensaje del cliente 60 segundos tarde. `InboundProcessorParityTest` lo fija.
 */
class Ingestor
{
    public function __construct(private readonly Dispatcher $dispatcher) {}

    /**
     * @return Message|null  el mensaje guardado, o null si era repetido
     */
    public function handle(InboundMessage $in): ?Message
    {
        // Idempotencia por id externo DENTRO del canal: dos proveedores pueden
        // emitir el mismo identificador y eso no es una repetición.
        if ($in->externalMessageId && Message::where('channel', $in->channel)
            ->where('external_message_id', $in->externalMessageId)
            ->exists()
        ) {
            return null;
        }

        [$contact, $conversation, $storedMessage, $isNewContact] = DB::transaction(
            fn () => $this->persist($in)
        );

        $text = $storedMessage->content_text;

        // Auto-etiquetado ANTES del webhook, para que el CRM externo reciba las
        // etiquetas ya actualizadas y no una foto vieja del contacto.
        if ($text) {
            $this->applyAutoTags($contact, $text, $isNewContact);
        }

        $this->enqueue($in, $contact, $conversation, $storedMessage, $isNewContact);

        return $storedMessage;
    }

    /**
     * Todo lo que se escribe en la base, en una transacción.
     *
     * @return array{0:Contact,1:Conversation,2:Message,3:bool}
     */
    private function persist(InboundMessage $in): array
    {
        [$contact, $isNewContact] = $this->resolveContact($in);

        $conversation = Conversation::firstOrCreate(
            [
                'account_id' => $in->accountId,
                'contact_id' => $contact->id,
                'channel' => $in->channel,
            ],
            [
                'status' => Conversation::STATUS_OPEN,
                'channel_conversation_id' => $in->threadExternalId ?? $in->senderExternalId,
            ],
        );

        $replyTo = null;
        if ($in->replyToExternalId) {
            // Acotado al canal: sin eso, dos ids externos de canales distintos
            // podrían colisionar y una respuesta quedaría enlazada al mensaje
            // de otra conversación.
            $replyTo = Message::where('channel', $in->channel)
                ->where('external_message_id', $in->replyToExternalId)
                ->value('id');
        }

        $storedMessage = Message::create([
            'conversation_id' => $conversation->id,
            'channel' => $in->channel,
            'external_message_id' => $in->externalMessageId,
            // `message_id` es la columna vieja, específica de Meta, y se sigue
            // escribiendo SOLO para WhatsApp: hay consultas que todavía la usan
            // (estados de entrega, correlación de broadcasts) y no filtran por
            // canal. Escribir ahí un id de Telegram las haría matchear de más.
            'message_id' => $in->channel === ChannelRules::WHATSAPP ? $in->externalMessageId : null,
            'sender_type' => Message::SENDER_CUSTOMER,
            'content_type' => $in->contentType,
            'content_text' => $in->contentText,
            'media_url' => $in->mediaRef,
            'referral' => $in->referral,
            'interactive_reply_id' => $in->interactiveReplyId,
            'reply_to_message_id' => $replyTo,
            'status' => 'delivered',
        ]);

        // El anuncio de ENTRADA se conserva: solo se escribe si la conversación
        // todavía no tiene uno, para no pisar la atribución original.
        if (($in->referral['source_id'] ?? null) && ! $conversation->entry_ad_id) {
            $conversation->entry_ad_id = $in->referral['source_id'];
        }

        $conversation->update([
            'last_message_text' => $in->contentText ?? "[{$in->contentType}]",
            'last_message_at' => now(),
            'unread_count' => $conversation->unread_count + 1,
            'status' => Conversation::STATUS_OPEN,
            // OJO: acá NO se reinicia `ai_reply_count`. Antes se ponía a 0 con
            // cada mensaje entrante, lo que volvía inalcanzable el «máximo N
            // respuestas por conversación» de Ajustes — el tope no limitaba
            // nada. Lo reinicia `AiAutoReplyJob` cuando vence la pausa.
        ]);

        $this->correlateBroadcasts($contact);

        return [$contact, $conversation, $storedMessage, $isNewContact];
    }

    /**
     * El contacto detrás del remitente.
     *
     * **Por identidad primero**, que es lo que permite que exista un contacto
     * sin teléfono. El camino por teléfono queda como respaldo para los canales
     * que sí lo traen: cubre a los contactos anteriores a F0 cuya identidad
     * creó el backfill, y a cualquier fila que se haya insertado por otra vía.
     *
     * @return array{0:Contact,1:bool}
     */
    private function resolveContact(InboundMessage $in): array
    {
        $contact = ContactIdentity::resolverContacto($in->accountId, $in->channel, $in->senderExternalId);
        $isNew = false;

        if (! $contact && $in->phone) {
            $normalized = Contact::normalizePhone($in->phone);

            $contact = Contact::firstOrCreate(
                ['account_id' => $in->accountId, 'phone_normalized' => $normalized],
                ['phone' => $in->phone, 'name' => $in->displayName()],
            );
            $isNew = $contact->wasRecentlyCreated;
        }

        if (! $contact) {
            // Canal sin teléfono y sin identidad previa: contacto nuevo, sin
            // número. Esto es lo que el esquema de T0.1 hizo posible.
            $contact = Contact::create([
                'account_id' => $in->accountId,
                'name' => $in->displayName(),
            ]);
            $isNew = true;
        }

        // El nombre se completa pero no se pisa: puede llegar recién en el
        // segundo mensaje, y alguien pudo haberlo corregido a mano.
        if ($in->displayName() && ! $contact->name) {
            $contact->update(['name' => $in->displayName()]);
        }

        ContactIdentity::registrar($contact, $in->channel, $in->senderExternalId, $in->displayName());

        return [$contact, $isNew];
    }

    /** Marca como respondidos los envíos masivos que este contacto contestó. */
    private function correlateBroadcasts(Contact $contact): void
    {
        BroadcastRecipient::where('contact_id', $contact->id)
            ->whereNotNull('sent_at')
            ->whereNull('replied_at')
            ->get()
            ->each(function (BroadcastRecipient $recipient) {
                $recipient->update(['status' => 'replied', 'replied_at' => now()]);
                $recipient->broadcast?->increment('replied_count');
            });
    }

    /**
     * ⚠️ **El orden de este método es el contrato.** La cola es FIFO:
     *
     *   1. Webhooks a integraciones: ligeros, tienen que salir YA para que el
     *      CRM externo vea el mensaje casi en tiempo real.
     *   2. Transcripción de audio.
     *   3. Flows y automatizaciones: reglas locales, rápidas.
     *   4. La IA al final: puede tardar 30-60 s con Qwen. Si fuera antes, el
     *      CRM externo esperaría a que el modelo termine para ver el mensaje
     *      del cliente — que es el bug que este orden existe para evitar.
     */
    private function enqueue(
        InboundMessage $in,
        Contact $contact,
        Conversation $conversation,
        Message $storedMessage,
        bool $isNewContact,
    ): void {
        $text = $storedMessage->content_text;

        // T0.3 — aditivo: `channel_external_id` es lo que permite al CRM externo
        // resolver el contacto **sin teléfono**. Sin este campo, un contacto de
        // Telegram llegaría allá sin ningún identificador y se descartaría en
        // silencio, que es exactamente el bloqueante que F0b vino a arreglar.
        $contactData = [
            ...$contact->only(['id', 'phone', 'name', 'email', 'company']),
            'channel_external_id' => $in->senderExternalId,
        ];

        if ($isNewContact) {
            $this->dispatcher->dispatch($in->accountId, 'contact.created', [
                'channel' => $in->channel,
                'conversation_id' => $conversation->id,
                'contact' => $contactData,
            ]);
        }

        $this->dispatcher->dispatch($in->accountId, 'message.received', [
            // El canal viaja en la raíz del payload. Un receptor viejo lo
            // ignora sin romperse; uno nuevo deja de asumir WhatsApp.
            'channel' => $in->channel,
            'conversation_id' => $conversation->id,
            'contact' => $contactData,
            'message' => [
                'id' => $storedMessage->id,
                'type' => $storedMessage->content_type,
                'text' => $storedMessage->content_text,
                'wamid' => $storedMessage->message_id,
                'media_id' => $storedMessage->media_url,
                'referral' => $storedMessage->referral,
            ],
        ]);

        if ($storedMessage->content_type === 'audio' && $storedMessage->media_url) {
            TranscribeAudioJob::dispatch($storedMessage->id);
        }

        ProcessFlowMessageJob::dispatch($contact->id, $conversation->id, $storedMessage->id);

        if ($isNewContact) {
            $this->createLeadDeal($contact, $conversation);
            ProcessAutomationEventJob::dispatch('new_contact', $contact->id, $conversation->id, $text);
        }

        ProcessAutomationEventJob::dispatch('inbound_message', $contact->id, $conversation->id, $text);

        if ($text) {
            ProcessAutomationEventJob::dispatch('keyword', $contact->id, $conversation->id, $text);
        }

        // Para audios NO se encola acá: la respuesta se difiere hasta tener la
        // transcripción, así la IA nunca contesta a un audio que no escuchó.
        // La encola `TranscribeAudioJob` al guardar el transcript.
        if ($storedMessage->content_type !== 'audio') {
            AiAutoReplyJob::dispatch($conversation->id);

            // Marca del ENCOLADO, no del resultado: es lo que distingue «el job
            // corrió y decidió callarse» de «el job nunca corrió». Sin esta
            // fila las dos cosas se ven igual —un registro vacío— y son
            // problemas completamente distintos.
            AiReplyAttempt::registrar($conversation, 'encolada', 'cola: '.(config('services.ai_context.queue') ?: 'default'));
        }
    }

    /**
     * Cada regla que matchea agrega su etiqueta (silencioso si ya la tenía).
     * `first_message_only` evita llenar de etiquetas al contacto en cada
     * mensaje siguiente.
     */
    private function applyAutoTags(Contact $contact, string $text, bool $isNewContact): void
    {
        $textLower = mb_strtolower($text);

        $rules = AutoTagRule::forAccount($contact->account_id)
            ->where('is_active', true)
            ->when(! $isNewContact, fn ($q) => $q->where('first_message_only', false))
            ->get();

        $tagIds = [];
        foreach ($rules as $rule) {
            if (str_contains($textLower, mb_strtolower($rule->keyword))) {
                $tagIds[] = $rule->tag_id;
            }
        }

        if (! empty($tagIds)) {
            // syncWithoutDetaching: agrega los nuevos sin tocar los existentes.
            $contact->tags()->syncWithoutDetaching(array_unique($tagIds));
        }
    }

    /**
     * Deal en la primera etapa abierta del pipeline por defecto. Silencioso si
     * la cuenta no tiene pipelines o si el contacto ya tiene un deal abierto.
     */
    private function createLeadDeal(Contact $contact, Conversation $conversation): void
    {
        if (Deal::where('contact_id', $contact->id)->where('status', 'open')->exists()) {
            return;
        }

        $pipeline = Pipeline::forAccount($contact->account_id)
            ->with(['stages' => fn ($q) => $q->orderBy('position')])
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->first();

        // Nunca una etapa terminal como Ganado/Perdido.
        $firstStage = $pipeline?->stages->where('stage_type', 'open')->first() ?? $pipeline?->stages->first();

        if (! $pipeline || ! $firstStage) {
            return;
        }

        Deal::create([
            'account_id' => $contact->account_id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $firstStage->id,
            'contact_id' => $contact->id,
            'conversation_id' => $conversation->id,
            'title' => $contact->name ?: $contact->phone,
            'assigned_to' => $conversation->assigned_agent_id,
        ]);
    }
}
