<?php

namespace App\Services\Komo;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente de la API del Komo (el CRM de leads).
 *
 * Hoy solo provisiona miembros del equipo: quien se da de alta acá tiene que
 * existir allá, porque allá es donde se asignan los contactos y se hace el
 * seguimiento. Antes el puente era de ida solamente (Komo creaba el user acá
 * al aceptar una invitación) y un miembro creado en este proyecto no aparecía
 * en ningún desplegable de responsable del Komo.
 *
 * Se configura en `.env` con KOMO_URL y KOMO_API_KEY (scope `team:write`).
 */
class Client
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {}

    /** null si la integración no está configurada — el llamador se abstiene. */
    public static function fromConfig(): ?self
    {
        $url = config('services.komo.url');
        $key = config('services.komo.api_key');

        if (! $url || ! $key) {
            return null;
        }

        return new self(rtrim($url, '/'), $key);
    }

    /**
     * Alta idempotente de un miembro en el Komo. Si ya existe allá actualiza
     * nombre y rol sin tocar su password.
     *
     * @return array<string, mixed>
     */
    public function provisionUser(string $email, string $name, ?string $password = null, string $role = 'agent'): array
    {
        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->timeout(15)
            ->post($this->baseUrl.'/api/v1/team/provision', array_filter([
                'email' => $email,
                'name' => $name,
                'password' => $password,
                'role' => $role,
            ]));

        if ($response->failed()) {
            throw new RuntimeException('Komo: '.$this->readableError($response));
        }

        return $response->json();
    }

    /**
     * Mensaje legible del error de Komo.
     *
     * En un 422 Laravel devuelve `message` con la CLAVE de traducción cruda
     * ("validation.email") si el idioma no tiene el archivo de mensajes, que
     * no le dice nada a nadie. Se arma el detalle desde el bag `errors`, que
     * sí trae el campo que falló.
     */
    private function readableError(Response $response): string
    {
        $errors = $response->json('errors');

        if (is_array($errors) && $errors !== []) {
            $campos = [];
            foreach ($errors as $campo => $mensajes) {
                $campos[] = $campo.' ('.(is_array($mensajes) ? reset($mensajes) : $mensajes).')';
            }

            return 'datos rechazados → '.implode('; ', $campos);
        }

        return $response->json('message') ?: 'HTTP '.$response->status();
    }
}
