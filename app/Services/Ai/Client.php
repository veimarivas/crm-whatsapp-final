<?php

namespace App\Services\Ai;

use App\Models\AiConfig;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente BYO-key para OpenAI y Anthropic. Equivalente a
 * src/lib/ai/providers del original: la clave vive cifrada en la
 * cuenta y las llamadas salen directo al proveedor elegido.
 */
class Client
{
    public function __construct(private readonly AiConfig $config) {}

    public static function for(AiConfig $config): self
    {
        return new self($config);
    }

    /**
     * ¿El proveedor está alcanzable ahora mismo?
     *
     * Ollama corre en el mismo servidor y es el que se cae o se queda sin RAM,
     * así que se lo consulta de verdad (`/api/tags`, barato, sin inferencia)
     * con timeout corto: esto se llama desde el render de una página.
     *
     * Para los proveedores cloud no se gasta una llamada — se comprueba que
     * haya API key, que es el modo realista de que estén mal configurados.
     */
    public function isReachable(): bool
    {
        try {
            if ($this->config->provider === 'ollama') {
                $baseUrl = rtrim($this->config->base_url ?: 'http://127.0.0.1:11434', '/');

                return Http::timeout(3)->get($baseUrl.'/api/tags')->successful();
            }

            // Groq sí se consulta de verdad: es un servicio externo y su plan
            // gratuito puede estar limitado o la clave revocada, y eso no se
            // ve mirando si el campo está lleno. `/models` no consume cuota de
            // inferencia.
            if ($this->config->provider === 'groq') {
                return Http::withToken($this->config->api_key)
                    ->timeout(5)
                    ->get(self::GROQ_URL.'/models')
                    ->successful();
            }

            return filled($this->config->api_key);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Modelos que ofrece el proveedor ahora mismo.
     *
     * Los catálogos cambian y un nombre inventado falla recién al responderle
     * a un cliente. Esto lo lista para elegir uno que exista.
     *
     * @return array<int, string>
     */
    public function availableModels(): array
    {
        $base = match ($this->config->provider) {
            'groq' => self::GROQ_URL,
            'openai' => 'https://api.openai.com/v1',
            'ollama' => null,
            default => null,
        };

        if ($this->config->provider === 'ollama') {
            $url = rtrim($this->config->base_url ?: 'http://127.0.0.1:11434', '/').'/api/tags';

            return collect(Http::timeout(10)->get($url)->json('models') ?? [])
                ->pluck('name')
                ->all();
        }

        if (! $base) {
            return [];
        }

        return collect(Http::withToken($this->config->api_key)->timeout(10)->get($base.'/models')->json('data') ?? [])
            ->pluck('id')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{role: 'user'|'assistant', content: string}>  $messages
     */
    public function chat(array $messages, ?string $system = null, int $maxTokens = 500, bool $sinRazonamiento = false): string
    {
        return match ($this->config->provider) {
            'anthropic' => $this->anthropic($messages, $system, $maxTokens),
            'ollama' => $this->ollama($messages, $system, $maxTokens),
            'groq' => $this->openaiCompatible($messages, $system, $maxTokens, self::GROQ_URL, 'Groq', $sinRazonamiento),
            default => $this->openai($messages, $system, $maxTokens),
        };
    }

    /** Groq habla el mismo protocolo que OpenAI; solo cambia el host. */
    public const GROQ_URL = 'https://api.groq.com/openai/v1';

    /**
     * Ollama local (o cualquier endpoint compatible con /api/chat).
     * No requiere API key; se conecta al base_url configurado en la cuenta.
     */
    /**
     * El escalón de contexto que hace falta, ni más ni menos.
     *
     * Estaba fijo en 16384 para todos los casos, y eso se paga en cada
     * consulta: `num_ctx` reserva la caché de atención y hace más lenta
     * incluso una pregunta de cinco palabras. La mayoría de los prompts entran
     * holgados en 4096.
     *
     * Quedarse corto es peor que pasarse: Ollama trunca en silencio por el
     * principio, que es donde están las reglas y el catálogo. Por eso se
     * estima con margen (3 caracteres por token, conservador para el español)
     * y se redondea hacia arriba.
     */
    private function contextSize(array $chat, int $maxTokens): int
    {
        $caracteres = array_sum(array_map(fn ($m) => mb_strlen($m['content'] ?? ''), $chat));
        $necesario = (int) ($caracteres / 3) + $maxTokens + 256; // margen

        $techo = (int) config('services.ollama.max_ctx', 16384);

        foreach ([4096, 8192, 16384, 32768] as $escalon) {
            if ($necesario <= $escalon) {
                return min($escalon, $techo);
            }
        }

        return $techo;
    }

    private function ollama(array $messages, ?string $system, int $maxTokens): string
    {
        $baseUrl = rtrim($this->config->base_url ?: 'http://127.0.0.1:11434', '/');

        $chat = [
            ...($system ? [['role' => 'system', 'content' => $system]] : []),
            ...$messages,
        ];

        $payload = [
            'model' => $this->config->model,
            'stream' => false,
            // Sin esto Ollama descarga el modelo a los 5 minutos de inactividad
            // y el siguiente cliente paga la recarga entera — que es
            // justamente lo que se comía el timeout.
            'keep_alive' => config('services.ollama.keep_alive', '30m'),
            'options' => [
                'num_predict' => $maxTokens,
                'num_ctx' => $this->contextSize($chat, $maxTokens),
                'temperature' => 0.2, // más determinístico: menos alucinación de datos
            ],
            'messages' => $chat,
        ];

        $response = Http::timeout((int) config('services.ollama.timeout', 180))
            ->post($baseUrl.'/api/chat', $payload);

        if ($response->failed()) {
            throw new RuntimeException('Ollama: '.($response->json('error') ?? $response->status()));
        }

        return trim($response->json('message.content') ?? '');
    }

    /**
     * Cualquier proveedor que hable el protocolo de OpenAI.
     *
     * Groq entra por acá: mismo formato de request y de respuesta, solo cambia
     * el host. La diferencia real no es el protocolo sino el hardware — corre
     * en aceleradores propios y devuelve en un par de segundos lo que en este
     * VPS por CPU tardaba minutos.
     */
    private function openaiCompatible(array $messages, ?string $system, int $maxTokens, string $baseUrl, string $etiqueta, bool $sinRazonamiento = false): string
    {
        $payload = [
            'model' => $this->config->model,
            'max_tokens' => $maxTokens,
            'temperature' => 0.2, // igual que en Ollama: menos margen para inventar
            'messages' => [
                ...($system ? [['role' => 'system', 'content' => $system]] : []),
                ...$messages,
            ],
        ];

        // Modelos que "piensan" antes de responder (qwen3, gpt-oss…): sin esto
        // gastan TODO el presupuesto de tokens deliberando en inglés y la
        // respuesta al cliente nunca llega a escribirse. `hidden` pide que el
        // proveedor devuelva solo la conclusión.
        //  es el reintento: el modelo gasto todo el
        // presupuesto deliberando y devolvio contenido vacio. Sin razonar,
        // contesta directo.
        $razonamiento = $sinRazonamiento
            ? ['reasoning_effort' => 'none']
            : array_filter([
                'reasoning_format' => config('services.ai_context.reasoning_format', 'hidden'),
                'reasoning_effort' => config('services.ai_context.reasoning_effort'),
            ]);

        $response = $this->postChat($baseUrl, $payload + $razonamiento);

        // No todos los modelos aceptan esos parámetros y responden 400. Se
        // reintenta sin ellos: el filtro de `<think>` del sanitizador cubre el
        // caso igual, así que perder esta optimización no rompe nada.
        if ($response->status() === 400 && $razonamiento !== [] && str_contains(mb_strtolower((string) $response->body()), 'reasoning')) {
            $response = $this->postChat($baseUrl, $payload);
        }

        if ($response->failed()) {
            // El 429 se nombra aparte: en un plan gratuito es lo más probable
            // y no se arregla mirando la API key.
            $mensaje = $response->status() === 429
                ? 'límite de uso alcanzado (plan gratuito): reintentá en unos minutos'
                : ($response->json('error.message') ?? 'HTTP '.$response->status());

            throw new RuntimeException("{$etiqueta}: {$mensaje}");
        }

        return trim($response->json('choices.0.message.content') ?? '');
    }

    private function postChat(string $baseUrl, array $payload): \Illuminate\Http\Client\Response
    {
        return Http::withToken($this->config->api_key)
            ->timeout((int) config('services.ai_context.cloud_timeout', 45))
            ->post(rtrim($baseUrl, '/').'/chat/completions', $payload);
    }

    private function openai(array $messages, ?string $system, int $maxTokens): string
    {
        $payload = [
            'model' => $this->config->model,
            'max_tokens' => $maxTokens,
            'messages' => [
                ...($system ? [['role' => 'system', 'content' => $system]] : []),
                ...$messages,
            ],
        ];

        $response = Http::withToken($this->config->api_key)
            ->timeout(30)
            ->post('https://api.openai.com/v1/chat/completions', $payload);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI: '.($response->json('error.message') ?? $response->status()));
        }

        return trim($response->json('choices.0.message.content') ?? '');
    }

    private function anthropic(array $messages, ?string $system, int $maxTokens): string
    {
        $response = Http::withHeaders([
            'x-api-key' => $this->config->api_key,
            'anthropic-version' => '2023-06-01',
        ])
            ->timeout(30)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->config->model,
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => $messages,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Anthropic: '.($response->json('error.message') ?? $response->status()));
        }

        return trim($response->json('content.0.text') ?? '');
    }
}
