<?php

namespace App\Services\WhatsApp;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Channels\ChannelRules;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Ventana de servicio de WhatsApp: cuánto tiempo queda para escribirle al
 * contacto SIN que Meta cobre.
 *
 * Las dos reglas de Meta, que se comportan DISTINTO:
 *
 *  - **24 h de servicio — se reinicia con cada mensaje.** Cada entrante del
 *    cliente abre (o renueva) 24 h en las que el negocio responde texto libre
 *    sin costo, contadas desde ese mensaje. Vencida, solo se puede escribir
 *    con plantilla aprobada, y eso sí se cobra.
 *  - **72 h de free entry point — NO se reinicia.** Corren desde el clic en
 *    el anuncio Click-to-WhatsApp y punto: que el cliente siga escribiendo no
 *    las estira. Dentro de esas 72 h todo es gratis, incluidas las plantillas.
 *    Meta lo marca con un `referral` en el mensaje entrante, así que solo un
 *    clic NUEVO en un anuncio abre otras 72 h.
 *
 * Ambas corren a la vez, así que la ventana real es **la que venza más
 * tarde**. El caso que hay que tener claro: si el cliente toca el anuncio y
 * escribe recién en la hora 71, al vencer las 72 h la conversación NO se
 * corta — quedan las 24 h estándar desde su último mensaje, o sea hasta la
 * hora 95. Por eso se toma el máximo de las dos y no solo la del anuncio.
 *
 * Todo se calcula desde los mensajes ya guardados: no se consulta a Meta.
 */
class ServiceWindow
{
    public const STANDARD_HOURS = 24;

    public const AD_REFERRAL_HOURS = 72;

    /** Debajo de esto la UI avisa en ámbar: queda poco para responder gratis. */
    public const WARNING_HOURS = 4;

    /**
     * @return array{
     *   source: 'meta_ad'|'whatsapp'|'none',
     *   window_hours: int|null,
     *   expires_at: string|null,
     *   remaining_seconds: int,
     *   is_open: bool,
     *   is_expiring: bool,
     *   last_inbound_at: string|null,
     *   ad_referral_at: string|null,
     * }
     */
    public function for(Conversation $conversation): array
    {
        $lastInbound = Message::where('conversation_id', $conversation->id)
            ->where('sender_type', Message::SENDER_CUSTOMER)
            ->latest('created_at')
            ->first(['created_at', 'referral']);

        // Último entrante que llegó desde un anuncio. No tiene por qué ser el
        // último mensaje: el cliente pudo tocar el anuncio y seguir escribiendo.
        $lastAdInbound = Message::where('conversation_id', $conversation->id)
            ->where('sender_type', Message::SENDER_CUSTOMER)
            ->whereNotNull('referral')
            ->latest('created_at')
            ->first(['created_at']);

        return $this->build($lastInbound?->created_at, $lastAdInbound?->created_at);
    }

    /**
     * Mismo cálculo a partir de dos fechas sueltas. Lo usa el CRM externo,
     * que espeja los mensajes pero no la tabla `messages`.
     *
     * @return array<string, mixed>
     */
    public function build(?CarbonInterface $lastInboundAt, ?CarbonInterface $adReferralAt, string $channel = ChannelRules::DEFAULT): array
    {
        // F0 — el corte de canal va acá y en ningún otro lado: los cuatro
        // métodos públicos terminan en `build()`, así que una sola línea cubre
        // el Inbox, los listados y los contactos. El default mantiene
        // compatibles a todos los llamadores que no saben de canales.
        if (! ChannelRules::hasServiceWindow($channel)) {
            return $this->alwaysOpen($channel, $lastInboundAt);
        }

        $standardExpiry = $lastInboundAt?->copy()->addHours(self::STANDARD_HOURS);

        // Las 72 h del anuncio son de Click-to-WhatsApp y de nada más. En
        // Messenger e Instagram rigen las 24 h y punto: extenderlas diría
        // «todavía es gratis» cuando ya no lo es.
        $adExpiry = ChannelRules::hasAdReferralWindow($channel)
            ? $adReferralAt?->copy()->addHours(self::AD_REFERRAL_HOURS)
            : null;

        // La que venza más tarde manda: las dos ventanas corren en paralelo.
        $expiry = match (true) {
            $standardExpiry && $adExpiry => $standardExpiry->max($adExpiry),
            default => $standardExpiry ?? $adExpiry,
        };

        $remaining = $expiry ? (int) now()->diffInSeconds($expiry, false) : 0;
        $isOpen = $remaining > 0;

        return [
            // De dónde vino: si la ventana vigente la sostiene el anuncio, se
            // reporta como tal — es lo que explica por qué son 72 h y no 24.
            'source' => match (true) {
                $adExpiry && $expiry?->equalTo($adExpiry) => 'meta_ad',
                $lastInboundAt !== null => 'whatsapp',
                default => 'none',
            },
            'window_hours' => match (true) {
                ! $expiry => null,
                $adExpiry && $expiry->equalTo($adExpiry) => self::AD_REFERRAL_HOURS,
                default => self::STANDARD_HOURS,
            },
            'expires_at' => $expiry?->toIso8601String(),
            'remaining_seconds' => max(0, $remaining),
            'is_open' => $isOpen,
            'is_expiring' => $isOpen && $remaining <= self::WARNING_HOURS * 3600,
            'last_inbound_at' => $lastInboundAt?->toIso8601String(),
            'ad_referral_at' => $adReferralAt?->toIso8601String(),
        ];
    }

    /**
     * Canal sin plazo: siempre se puede escribir y no cuesta.
     *
     * **⚠️ Devuelve TODAS las claves del contrato, no un array corto.** La UI
     * ya consume `window_hours`, `remaining_seconds` y `source`; un array
     * incompleto rompe el badge con un error que no señala esta línea.
     * `window_hours = null` es la señal de «sin límite» y la pantalla la lee
     * para no dibujar una cuenta regresiva que no existe.
     *
     * @return array<string, mixed>
     */
    private function alwaysOpen(string $channel, ?CarbonInterface $lastInboundAt): array
    {
        return [
            'source' => $channel,
            'window_hours' => null,
            'expires_at' => null,
            'remaining_seconds' => 0,
            'is_open' => true,
            'is_expiring' => false,
            'last_inbound_at' => $lastInboundAt?->toIso8601String(),
            'ad_referral_at' => null,
        ];
    }

    /**
     * Ventana por CONTACTO (no por conversación): la de su conversación con
     * actividad más reciente. La usan las vistas que listan contactos o deals
     * —contactos, pipelines, dashboard— donde no hay una conversación a mano.
     *
     * @param  array<int, string>  $contactIds
     * @return array<string, array<string, mixed>>
     */
    public function forContacts(array $contactIds): array
    {
        if ($contactIds === []) {
            return [];
        }

        $latest = fn (bool $onlyAds) => Message::query()
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->whereIn('conversations.contact_id', $contactIds)
            ->where('messages.sender_type', Message::SENDER_CUSTOMER)
            ->when($onlyAds, fn ($q) => $q->whereNotNull('messages.referral'))
            ->selectRaw('conversations.contact_id as cid, MAX(messages.created_at) as last_at')
            ->groupBy('conversations.contact_id')
            ->pluck('last_at', 'cid');

        $lastInbound = $latest(false);
        $lastAd = $latest(true);

        $out = [];

        foreach ($contactIds as $id) {
            $out[$id] = $this->build(
                isset($lastInbound[$id]) ? Carbon::parse($lastInbound[$id]) : null,
                isset($lastAd[$id]) ? Carbon::parse($lastAd[$id]) : null,
            );
        }

        return $out;
    }

    /**
     * Versión en lote para listados: una query para todas las conversaciones
     * en vez de dos por cada una.
     *
     * @param  array<int, string>  $conversationIds
     * @return array<string, array<string, mixed>>
     */
    public function forMany(array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        $lastInbound = Message::whereIn('conversation_id', $conversationIds)
            ->where('sender_type', Message::SENDER_CUSTOMER)
            ->selectRaw('conversation_id, MAX(created_at) as last_at')
            ->groupBy('conversation_id')
            ->pluck('last_at', 'conversation_id');

        $lastAd = Message::whereIn('conversation_id', $conversationIds)
            ->where('sender_type', Message::SENDER_CUSTOMER)
            ->whereNotNull('referral')
            ->selectRaw('conversation_id, MAX(created_at) as last_at')
            ->groupBy('conversation_id')
            ->pluck('last_at', 'conversation_id');

        $out = [];

        foreach ($conversationIds as $id) {
            $out[$id] = $this->build(
                isset($lastInbound[$id]) ? Carbon::parse($lastInbound[$id]) : null,
                isset($lastAd[$id]) ? Carbon::parse($lastAd[$id]) : null,
            );
        }

        return $out;
    }
}
