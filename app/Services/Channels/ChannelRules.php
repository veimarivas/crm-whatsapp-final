<?php

namespace App\Services\Channels;

/**
 * Reglas por canal — GEMELO: este archivo es IDENTICO en laravel_crm_whatsapp
 * y laravel_komo_crm. Si cambia una regla hay que tocar los DOS repos y la
 * fixture `tests/Fixtures/twins/channel-rules.json` de los dos.
 *
 * Clase **sin dependencias**: solo constantes y funciones estáticas. Es a
 * propósito y es lo que la hace posible como gemelo — la consumen
 * `ServiceWindow` y `MessagingCost` en ambos proyectos, y en el wacrm además
 * los adapters de canal. Si dependiera de un adapter, el lado del Komo tendría
 * que conocer clases que allá no existen.
 *
 * Las cuatro preguntas que separan un canal de otro, y ninguna es cosmética:
 *
 *  - **¿tiene ventana de servicio?** Solo los canales de Meta cobran por
 *    escribir fuera de plazo. En Telegram, correo o SMS no hay ventana que
 *    vencer, así que la conversación está siempre abierta.
 *  - **¿tiene la ventana de 72 h del anuncio?** Solo WhatsApp. El free entry
 *    point es de Click-to-WhatsApp; Messenger e Instagram tienen las 24 h y
 *    NADA más. Aplicarles las 72 h diría que se puede escribir gratis cuando
 *    en realidad ya no se puede.
 *  - **¿exige plantilla aprobada?** Solo WhatsApp.
 *  - **¿se puede escribir primero?** Un bot de Telegram solo puede escribirle
 *    a quien lo inició (lección del módulo de avisos que se eliminó el
 *    2026-07-28). Un webchat, a nadie: el visitante es anónimo hasta que deja
 *    un dato.
 */
final class ChannelRules
{
    public const WHATSAPP = 'whatsapp';

    public const TELEGRAM = 'telegram';

    public const MESSENGER = 'messenger';

    public const INSTAGRAM = 'instagram';

    public const EMAIL = 'email';

    public const SMS = 'sms';

    public const WEBCHAT = 'webchat';

    /** El canal de todo lo que existía antes de que hubiera canales. */
    public const DEFAULT = self::WHATSAPP;

    /** @var array<int, string> */
    public const ALL = [
        self::WHATSAPP, self::TELEGRAM, self::MESSENGER,
        self::INSTAGRAM, self::EMAIL, self::SMS, self::WEBCHAT,
    ];

    /**
     * Un canal desconocido NO es un error: los canales nacen en el wacrm y los
     * deploys no son simultáneos, así que el Komo puede recibir un evento de
     * algo que todavía no conoce. Se trata con el criterio más conservador
     * posible — sin ventana, sin costo, sin escribir primero— que como mucho
     * hace que el sistema no ofrezca algo, nunca que gaste de más.
     */
    public static function isKnown(string $channel): bool
    {
        return in_array($channel, self::ALL, true);
    }

    /**
     * ¿Hay un plazo después del cual escribir cuesta o no se puede?
     *
     * Solo los canales de Meta. Para el resto la ventana está siempre abierta,
     * y por eso `ServiceWindow` corta antes de calcular nada.
     */
    public static function hasServiceWindow(string $channel): bool
    {
        return in_array($channel, [self::WHATSAPP, self::MESSENGER, self::INSTAGRAM], true);
    }

    /**
     * ¿Aplica la ventana de 72 h del free entry point (clic en anuncio)?
     *
     * **Solo WhatsApp.** Es la regla más fácil de generalizar mal: Messenger e
     * Instagram comparten la app de Meta y las 24 h, pero NO las 72 h. Darles
     * la extensión del anuncio diría «todavía es gratis» cuando ya no lo es.
     */
    public static function hasAdReferralWindow(string $channel): bool
    {
        return $channel === self::WHATSAPP;
    }

    /** ¿Hace falta una plantilla aprobada para escribir fuera de la ventana? */
    public static function requiresApprovedTemplates(string $channel): bool
    {
        return $channel === self::WHATSAPP;
    }

    /**
     * ¿El proveedor cobra por mensaje?
     *
     * WhatsApp cobra por conversación iniciada; SMS, por mensaje. El resto es
     * gratis, y decir lo contrario en la pantalla de un envío sería inventar
     * una factura.
     */
    public static function hasCost(string $channel): bool
    {
        return in_array($channel, [self::WHATSAPP, self::SMS], true);
    }

    /**
     * ¿Se le puede escribir a alguien que nunca escribió?
     *
     * Telegram no: un bot solo alcanza a quien lo inició. Webchat tampoco: el
     * visitante no tiene identidad hasta que deja un dato. Messenger e
     * Instagram exigen etiquetas de mensaje que no cubren el caso comercial.
     * Esto es lo que decide el tamaño real de la audiencia de un envío.
     */
    public static function supportsOutboundFirst(string $channel): bool
    {
        return in_array($channel, [self::WHATSAPP, self::EMAIL, self::SMS], true);
    }
}
