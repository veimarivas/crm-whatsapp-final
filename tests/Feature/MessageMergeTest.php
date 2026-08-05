<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AiConfig;
use App\Models\User;
use App\Services\Ai\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Mensajes seguidos del mismo rol.
 *
 * El detalle de la oferta se inserta como un mensaje `user` justo antes de la
 * pregunta del cliente, así que quedan dos `user` seguidos. OpenAI y Groq lo
 * toleran; Gemini viene de una API que exige alternancia estricta y su capa de
 * compatibilidad puede rechazarlo con un 400 — que desde afuera se ve como
 * «la IA no responde».
 */
class MessageMergeTest extends TestCase
{
    use RefreshDatabase;

    private function config(string $provider): AiConfig
    {
        $owner = User::create(['name' => 'Admin', 'email' => $provider.'@test.com', 'password' => bcrypt('x')]);
        $account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $account->id, 'account_role' => User::ROLE_OWNER]);

        return AiConfig::create([
            'account_id' => $account->id,
            'provider' => $provider,
            'model' => 'un-modelo',
            'api_key' => 'clave',
            'is_active' => true,
        ]);
    }

    public function test_dos_mensajes_seguidos_del_cliente_se_unen(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        Client::for($this->config('gemini'))->chat([
            ['role' => 'user', 'content' => '[Datos de nuestra base] Módulo 1: 10/09/2026'],
            ['role' => 'user', 'content' => 'y los horarios?'],
        ], 'Reglas', 300);

        Http::assertSent(function ($request) {
            $roles = array_column($request['messages'], 'role');

            $this->assertSame(['system', 'user'], $roles);
            // Unir no puede perder contenido: el modelo tiene que ver las dos
            // cosas, los datos y la pregunta.
            $this->assertStringContainsString('Módulo 1', $request['messages'][1]['content']);
            $this->assertStringContainsString('y los horarios?', $request['messages'][1]['content']);

            return true;
        });
    }

    public function test_una_conversacion_alternada_no_se_toca(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        Client::for($this->config('groq'))->chat([
            ['role' => 'user', 'content' => 'hola'],
            ['role' => 'assistant', 'content' => 'buenas'],
            ['role' => 'user', 'content' => 'qué programas hay?'],
        ], null, 300);

        Http::assertSent(function ($request) {
            $this->assertSame(['user', 'assistant', 'user'], array_column($request['messages'], 'role'));

            return true;
        });
    }
}
