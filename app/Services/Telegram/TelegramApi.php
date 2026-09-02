<?php

namespace App\Services\Telegram;

use App\Models\ChannelConfig;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Bot API de Telegram.
 *
 * El token vive **por cuenta** en `channel_configs.credentials` (cifrado), no
 * en el `.env`: un token en el entorno sería un solo bot para todas las cuentas
 * de la instalación.
 *
 * ⚠️ **El token va en la URL** — así funciona la Bot API. Eso obliga a dos
 * cuidados que no son opcionales:
 *  - nunca loguear la URL completa (`redact()` la recorta);
 *  - nunca exponer al navegador un link de `getFile`, que también lo lleva
 *    dentro.
 */
class TelegramApi
{
    private const BASE = 'https://api.telegram.org';

    public function __construct(private readonly string $botToken) {}

    public static function for(ChannelConfig $config): self
    {
        $token = $config->credential('bot_token');

        if (! $token) {
            throw new RuntimeException('El canal de Telegram no tiene bot token configurado.');
        }

        return new self($token);
    }

    /** Datos del bot. Sirve para validar el token al conectarlo. */
    public function getMe(): array
    {
        return $this->call('getMe');
    }

    /**
     * Texto a un chat.
     *
     * `parse_mode=HTML` y no Markdown: el texto lo escriben personas y un
     * guion bajo suelto rompe el parseo de Markdown, que devuelve un 400 en vez
     * de mandar el mensaje.
     */
    public function sendMessage(string $chatId, string $text): array
    {
        return $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ]);
    }

    /** «Escribiendo…». Best-effort: que falle no puede cortar nada. */
    public function sendChatAction(string $chatId, string $action = 'typing'): void
    {
        rescue(fn () => $this->call('sendChatAction', ['chat_id' => $chatId, 'action' => $action]), report: false);
    }

    /**
     * Registra el webhook.
     *
     * El `secret_token` es lo único que separa un update de Telegram de
     * cualquiera que descubra la URL: viaja de vuelta en cada petición como
     * cabecera `X-Telegram-Bot-Api-Secret-Token`.
     */
    public function setWebhook(string $url, string $secretToken): array
    {
        return $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secretToken,
            // Sin esto Telegram manda todos los tipos de update, incluidos los
            // de canales y encuestas, que acá no se procesan.
            'allowed_updates' => ['message', 'edited_message', 'callback_query'],
            // Un webhook que se re-registra deja atrás los updates viejos en
            // vez de reproducir una avalancha de mensajes ya atendidos.
            'drop_pending_updates' => true,
        ]);
    }

    public function deleteWebhook(): array
    {
        return $this->call('deleteWebhook');
    }

    /** @param array<string, mixed> $params */
    private function call(string $method, array $params = []): array
    {
        $response = Http::asJson()
            ->timeout(30)
            ->post(self::BASE."/bot{$this->botToken}/{$method}", $params);

        $body = $response->json() ?? [];

        if ($response->failed() || ! ($body['ok'] ?? false)) {
            // El mensaje de Telegram («chat not found», «bot was blocked by the
            // user») explica mucho mejor que un código HTTP, y es lo que va a
            // leer quien mire por qué no salió un mensaje.
            $detalle = $body['description'] ?? "HTTP {$response->status()}";

            throw new RuntimeException("Telegram [{$method}]: {$detalle}");
        }

        $result = $body['result'] ?? [];

        // ⚠️ No todos los métodos devuelven un objeto: `setWebhook` y
        // `deleteWebhook` devuelven `result: true` a secas. Sin normalizarlo, el
        // tipo de retorno revienta con «Return value must be of type array, true
        // returned» — un error que habla de PHP y no de que el webhook sí se
        // registró, que es lo que en realidad pasó.
        return is_array($result) ? $result : ['result' => $result];
    }
}
