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

    /**
     * Descarga un archivo por su `file_id`.
     *
     * Son **dos pasos** porque así es la Bot API: `getFile` devuelve un
     * `file_path` temporal y recién con él se arma la URL de descarga.
     *
     * ⚠️ **Esa URL lleva el bot token adentro y caduca (~1 h).** No se puede
     * guardar en la base ni mandar al navegador: quien la viera tendría control
     * total del bot. Por eso este método devuelve **el binario**, no un link —
     * la firma misma impide el error.
     *
     * @return array{0:string,1:string} [contenido, nombre de archivo sugerido]
     */
    public function downloadFile(string $fileId): array
    {
        $file = $this->call('getFile', ['file_id' => $fileId]);
        $path = $file['file_path'] ?? throw new RuntimeException('Telegram no devolvió la ruta del archivo.');

        $response = Http::timeout(120)->get(self::BASE."/file/bot{$this->botToken}/{$path}");

        if ($response->failed()) {
            throw new RuntimeException("Telegram: no se pudo descargar el archivo (HTTP {$response->status()}).");
        }

        return [$response->body(), basename($path)];
    }

    /** Envía un archivo ya descargado. El tipo decide el método de la Bot API. */
    public function sendFile(string $chatId, string $tipo, string $contents, string $filename, ?string $caption = null): array
    {
        $metodo = match ($tipo) {
            'image' => 'sendPhoto',
            'video' => 'sendVideo',
            'audio' => 'sendAudio',
            default => 'sendDocument',
        };

        $campo = match ($tipo) {
            'image' => 'photo',
            'video' => 'video',
            'audio' => 'audio',
            default => 'document',
        };

        $response = Http::timeout(120)
            ->attach($campo, $contents, $filename)
            ->post(self::BASE."/bot{$this->botToken}/{$metodo}", array_filter([
                'chat_id' => $chatId,
                'caption' => $caption,
            ]));

        $body = $response->json() ?? [];

        if ($response->failed() || ! ($body['ok'] ?? false)) {
            throw new RuntimeException("Telegram [{$metodo}]: ".($body['description'] ?? "HTTP {$response->status()}"));
        }

        return $body['result'] ?? [];
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
