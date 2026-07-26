<?php

namespace App\Services\WhatsApp;

use App\Models\Conversation;
use App\Models\Message;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Ventana de servicio de WhatsApp: cuánto tiempo queda para escribirle al
 * contacto SIN que Meta cobre.
 *
 * Las dos reglas de Meta que importan acá:
 *
 *  - **24 h de servicio**: cada mensaje entrante del cliente abre (o renueva)
 *    una ventana de 24 h en la que el negocio puede responder texto libre sin
 *    costo. Vencida, solo se puede escribir con una plantilla aprobada, y eso
 *    sí se cobra.
 *  - **72 h de free entry point**: si el cliente llegó tocando un anuncio
 *    Click-to-WhatsApp, esa conversación es gratuita durante 72 h. Meta lo
 *    marca con un `referral` en el mensaje entrante.
 *
 * Ambas corren a la vez, así que la ventana real es **la que venza más
 * tarde**: un clic en el anuncio hace hace 60 h todavía cubre aunque el
 * último mensaje del cliente sea de hace 2 h... y al revés, un mensaje de
 * hace 2 h cubre aunque el anuncio ya haya vencido. Por eso se toma el
 * máximo de las dos y no solo la del último mensaje.
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
    public function build(?CarbonInterface $lastInboundAt, ?CarbonInterface $adReferralAt): array
    {
        $standardExpiry = $lastInboundAt?->copy()->addHours(self::STANDARD_HOURS);
        $adExpiry = $adReferralAt?->copy()->addHours(self::AD_REFERRAL_HOURS);

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
