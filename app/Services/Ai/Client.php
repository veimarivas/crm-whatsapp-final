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

            return filled($this->config->api_key);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<int, array{role: 'user'|'assistant', content: string}>  $messages
     */
    public function chat(array $messages, ?string $system = null, int $maxTokens = 500): string
    {
        return match ($this->config->provider) {
            'anthropic' => $this->anthropic($messages, $system, $maxTokens),
            'ollama' => $this->ollama($messages, $system, $maxTokens),
            default => $this->openai($messages, $system, $maxTokens),
        };
    }

    /**
     * Ollama local (o cualquier endpoint compatible con /api/chat).
     * No requiere API key; se conecta al base_url configurado en la cuenta.
     */
    private function ollama(array $messages, ?string $system, int $maxTokens): string
    {
        $baseUrl = rtrim($this->config->base_url ?: 'http://127.0.0.1:11434', '/');

        $payload = [
            'model' => $this->config->model,
            'stream' => false,
            'options' => [
                'num_predict' => $maxTokens,
                // Qwen2.5 soporta hasta 32k. Ollama por defecto usa solo 4096
                // que se queda corto con nuestro RAG (15 chunks × 3000 chars).
                // 16k da margen para system prompt + historial + knowledge + respuesta.
                'num_ctx' => 16384,
                'temperature' => 0.2, // más determinístico: menos alucinación de datos
            ],
            'messages' => [
                ...($system ? [['role' => 'system', 'content' => $system]] : []),
                ...$messages,
            ],
        ];

        $response = Http::timeout(120)
            ->post($baseUrl.'/api/chat', $payload);

        if ($response->failed()) {
            throw new RuntimeException('Ollama: '.($response->json('error') ?? $response->status()));
        }

        return trim($response->json('message.content') ?? '');
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
