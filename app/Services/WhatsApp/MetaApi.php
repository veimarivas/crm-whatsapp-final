<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsappConfig;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cliente de la WhatsApp Cloud API (Meta Graph).
 * Equivalente a src/lib/whatsapp/meta-api.ts del original.
 */
class MetaApi
{
    public function __construct(private readonly WhatsappConfig $config) {}

    public static function for(WhatsappConfig $config): self
    {
        return new self($config);
    }

    private function base(): string
    {
        $version = config('services.meta.graph_version');

        return "https://graph.facebook.com/{$version}";
    }

    private function request()
    {
        return Http::withToken($this->config->access_token)->acceptJson();
    }

    /** POST /{phone_number_id}/messages con un payload arbitrario. */
    public function sendMessage(array $payload): array
    {
        $response = $this->request()->post(
            "{$this->base()}/{$this->config->phone_number_id}/messages",
            array_merge(['messaging_product' => 'whatsapp', 'recipient_type' => 'individual'], $payload),
        );

        return $this->unwrap($response);
    }

    public function sendText(string $to, string $text, ?string $replyToWamid = null): array
    {
        $payload = [
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $text, 'preview_url' => true],
        ];

        if ($replyToWamid) {
            $payload['context'] = ['message_id' => $replyToWamid];
        }

        return $this->sendMessage($payload);
    }

    public function sendTemplate(string $to, string $name, string $language, array $components = []): array
    {
        return $this->sendMessage([
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $name,
                'language' => ['code' => $language],
                'components' => $components,
            ],
        ]);
    }

    /** Sube un binario a Meta y devuelve su media id. */
    public function uploadMedia(string $contents, string $mimeType, string $filename): string
    {
        $response = $this->request()
            ->attach('file', $contents, $filename, ['Content-Type' => $mimeType])
            ->post("{$this->base()}/{$this->config->phone_number_id}/media", [
                'messaging_product' => 'whatsapp',
            ]);

        $data = $this->unwrap($response);

        return $data['id'] ?? throw new RuntimeException('Meta no devolvió un media id.');
    }

    /** Envía un media ya subido (image|video|audio|document). */
    public function sendMedia(string $to, string $type, string $mediaId, ?string $caption = null, ?string $filename = null): array
    {
        $media = ['id' => $mediaId];

        if ($caption && in_array($type, ['image', 'video', 'document'], true)) {
            $media['caption'] = $caption;
        }

        if ($filename && $type === 'document') {
            $media['filename'] = $filename;
        }

        return $this->sendMessage(['to' => $to, 'type' => $type, $type => $media]);
    }

    /** Mensaje interactivo con hasta 3 botones de respuesta rápida. */
    public function sendButtons(string $to, string $text, array $buttons): array
    {
        return $this->sendMessage([
            'to' => $to,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => $text],
                'action' => [
                    'buttons' => array_map(fn (array $b) => [
                        'type' => 'reply',
                        'reply' => ['id' => $b['id'], 'title' => mb_substr($b['title'], 0, 20)],
                    ], array_slice($buttons, 0, 3)),
                ],
            ],
        ]);
    }

    /** Mensaje interactivo de lista (hasta 10 filas). */
    public function sendList(string $to, string $text, string $buttonLabel, array $rows): array
    {
        return $this->sendMessage([
            'to' => $to,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'body' => ['text' => $text],
                'action' => [
                    'button' => mb_substr($buttonLabel ?: 'Ver opciones', 0, 20),
                    'sections' => [[
                        'title' => mb_substr($buttonLabel ?: 'Opciones', 0, 24),
                        'rows' => array_map(fn (array $r) => [
                            'id' => $r['id'],
                            'title' => mb_substr($r['title'], 0, 24),
                            'description' => mb_substr($r['description'] ?? '', 0, 72) ?: null,
                        ], array_slice($rows, 0, 10)),
                    ]],
                ],
            ],
        ]);
    }

    public function sendReaction(string $to, string $wamid, string $emoji): array
    {
        return $this->sendMessage([
            'to' => $to,
            'type' => 'reaction',
            'reaction' => ['message_id' => $wamid, 'emoji' => $emoji],
        ]);
    }

    public function markAsRead(string $wamid): void
    {
        $this->request()->post("{$this->base()}/{$this->config->phone_number_id}/messages", [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $wamid,
        ]);
    }

    /**
     * Envía el indicador "escribiendo..." al cliente en WhatsApp.
     * Meta lo entrega piggy-back del markAsRead: se marca leído el mensaje
     * del cliente y a la vez se le muestra el typing indicator (dura ~25s).
     * Si el wamid no es válido o el cliente no está en foreground, se ignora.
     * Silencioso: no lanza excepción si falla, no debe bloquear al bot.
     */
    public function sendTypingIndicator(string $wamid): void
    {
        try {
            $this->request()->post("{$this->base()}/{$this->config->phone_number_id}/messages", [
                'messaging_product' => 'whatsapp',
                'status' => 'read',
                'message_id' => $wamid,
                'typing_indicator' => ['type' => 'text'],
            ]);
        } catch (\Throwable $e) {
            // Los typing indicators son best-effort: log y seguir.
            Log::info('Typing indicator falló (best-effort)', ['error' => $e->getMessage()]);
        }
    }

    /** Lista las plantillas del WABA en Meta (para sincronizar). */
    public function listTemplates(): array
    {
        if (! $this->config->waba_id) {
            throw new RuntimeException('Configura el WABA ID para sincronizar plantillas.');
        }

        $templates = [];
        $url = "{$this->base()}/{$this->config->waba_id}/message_templates?limit=100";

        // Pagina el listado completo (Meta devuelve cursores).
        while ($url) {
            $data = $this->unwrap($this->request()->get($url));
            $templates = array_merge($templates, $data['data'] ?? []);
            $url = $data['paging']['next'] ?? null;
        }

        return $templates;
    }

    /** Envía una plantilla a revisión de Meta. */
    public function createTemplate(array $payload): array
    {
        if (! $this->config->waba_id) {
            throw new RuntimeException('Configura el WABA ID para enviar plantillas a Meta.');
        }

        return $this->unwrap($this->request()->post(
            "{$this->base()}/{$this->config->waba_id}/message_templates",
            $payload,
        ));
    }

    /** Resuelve la URL temporal de descarga de un media id de Meta. */
    public function getMediaUrl(string $mediaId): ?string
    {
        $data = $this->unwrap($this->request()->get("{$this->base()}/{$mediaId}"));

        return $data['url'] ?? null;
    }

    /** Descarga el binario de un media (la URL de Meta exige el token). */
    public function downloadMedia(string $url): Response
    {
        return Http::withToken($this->config->access_token)->get($url);
    }

    private function unwrap(Response $response): array
    {
        if ($response->failed()) {
            $error = $response->json('error.message') ?? $response->body();

            throw new RuntimeException("Meta API error ({$response->status()}): {$error}");
        }

        return $response->json() ?? [];
    }
}
