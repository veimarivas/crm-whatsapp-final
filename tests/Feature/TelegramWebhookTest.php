<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ChannelConfig;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Channels\ChannelRouter;
use App\Services\Channels\ChannelRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * F1 — Telegram, de punta a punta.
 *
 * **El controlador de webhook es corto y ese es el punto**: traduce el `update`
 * de Telegram a un `InboundMessage` y se lo pasa al `Ingestor`. Contacto,
 * identidad, conversación, auto-etiquetas y el orden de la cola ya los hace el
 * motor sin saber de canales. Es el pago de F0.
 */
class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private const SECRETO = 'sec_telegram_123';

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        ChannelConfig::create([
            'account_id' => $this->account->id,
            'channel' => ChannelRules::TELEGRAM,
            'is_enabled' => true,
            'credentials' => ['bot_token' => '123:ABC', 'webhook_secret' => self::SECRETO],
            'connected_at' => now(),
        ]);
    }

    /**
     * ⚠️ El doble de HTTP se arma en cada test y NO en `setUp`.
     *
     * `Http::fake()` llamado dos veces **acumula** stubs y gana el PRIMERO que
     * matchea, así que un `fake` de fallo declarado después de uno de éxito
     * nunca se aplica — y el test que quería medir un error pasa midiendo un
     * acierto. El camino de entrada no llama a Telegram, así que la mayoría no
     * lo necesita.
     */
    private function fakeTelegramOk(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 555]])]);
    }

    private function update(array $payload, ?string $secreto = self::SECRETO)
    {
        return $this->withHeaders(array_filter([
            'X-Telegram-Bot-Api-Secret-Token' => $secreto,
        ]))->postJson("/webhooks/telegram/{$this->account->id}", $payload);
    }

    private function mensajeDeTexto(int $updateId = 1, string $texto = 'hola', array $from = []): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => 100 + $updateId,
                'from' => array_merge(['id' => 99887766, 'first_name' => 'Ana', 'last_name' => 'Pérez'], $from),
                'chat' => ['id' => 99887766, 'type' => 'private'],
                'text' => $texto,
            ],
        ];
    }

    // ── Seguridad ──────────────────────────────────────────────────────────

    public function test_sin_el_secreto_correcto_es_403(): void
    {
        $this->update($this->mensajeDeTexto(), secreto: 'otro')->assertForbidden();
        $this->update($this->mensajeDeTexto(), secreto: null)->assertForbidden();

        $this->assertSame(0, Contact::count());
    }

    public function test_una_cuenta_sin_telegram_conectado_es_404(): void
    {
        // 404 y no 403: a quien prueba URLs no se le confirma que la cuenta
        // existe ni que tiene Telegram conectado.
        ChannelConfig::query()->update(['is_enabled' => false]);

        $this->update($this->mensajeDeTexto())->assertNotFound();
    }

    // ── El E2E ─────────────────────────────────────────────────────────────

    public function test_un_mensaje_crea_contacto_identidad_conversacion_y_mensaje(): void
    {
        $this->update($this->mensajeDeTexto())->assertOk();

        $contact = Contact::firstOrFail();
        $this->assertSame('Ana Pérez', $contact->name);
        // Lo que era imposible antes de F0: un contacto sin teléfono.
        $this->assertNull($contact->phone);

        $identity = ContactIdentity::firstOrFail();
        $this->assertSame(ChannelRules::TELEGRAM, $identity->channel);
        $this->assertSame('99887766', $identity->external_id);

        $conversation = Conversation::firstOrFail();
        $this->assertSame(ChannelRules::TELEGRAM, $conversation->channel);
        // El chat_id es a DÓNDE se responde.
        $this->assertSame('99887766', $conversation->channel_conversation_id);

        $message = Message::firstOrFail();
        $this->assertSame('hola', $message->content_text);
        $this->assertSame('101', $message->external_message_id);
        // `message_id` es la columna vieja de Meta: no se escribe para otros
        // canales, porque hay consultas que la usan sin filtrar por canal.
        $this->assertNull($message->message_id);
    }

    public function test_el_mismo_update_dos_veces_se_procesa_una(): void
    {
        // Telegram reintenta hasta recibir un 200, así que un timeout nuestro
        // se convertiría en el mismo mensaje procesado tres veces — y en tres
        // respuestas de la IA.
        $this->update($this->mensajeDeTexto())->assertOk();
        $this->update($this->mensajeDeTexto())->assertOk();

        $this->assertSame(1, Message::count());
    }

    public function test_el_nombre_cae_al_username_cuando_no_hay_nombre(): void
    {
        // El contacto no tiene teléfono, así que el nombre es lo único con lo
        // que un asesor lo va a reconocer.
        $this->update($this->mensajeDeTexto(from: [
            'first_name' => null, 'last_name' => null, 'username' => 'ana_p',
        ]))->assertOk();

        $this->assertSame('ana_p', Contact::firstOrFail()->name);
    }

    public function test_el_toque_a_un_boton_entra_como_respuesta_interactiva(): void
    {
        $this->update([
            'update_id' => 7,
            'callback_query' => [
                'id' => 'cb-1',
                'from' => ['id' => 99887766, 'first_name' => 'Ana'],
                'message' => ['chat' => ['id' => 99887766]],
                // Lo que importa es el `data`, no el rótulo: es el
                // identificador con el que el flow decide la rama.
                'data' => 'opcion_maestrias',
            ],
        ])->assertOk();

        $message = Message::firstOrFail();
        $this->assertSame('opcion_maestrias', $message->content_text);
        $this->assertSame('opcion_maestrias', $message->interactive_reply_id);
    }

    public function test_un_mensaje_editado_se_toma_como_nuevo(): void
    {
        $update = $this->mensajeDeTexto(texto: 'me equivoqué');
        $update['edited_message'] = $update['message'];
        unset($update['message']);

        $this->update($update)->assertOk();

        // Descartarlo dejaría la conversación mostrando el texto que el propio
        // cliente corrigió.
        $this->assertSame('me equivoqué', Message::firstOrFail()->content_text);
    }

    public function test_un_adjunto_deja_rastro_en_vez_de_un_salto_inexplicable(): void
    {
        $update = $this->mensajeDeTexto();
        unset($update['message']['text']);
        $update['message']['photo'] = [['file_id' => 'f1']];

        $this->update($update)->assertOk();

        $this->assertStringContainsString('Archivo recibido', Message::firstOrFail()->content_text);
    }

    public function test_un_update_que_no_procesamos_devuelve_200(): void
    {
        // SIEMPRE 200: devolver otra cosa hace que Telegram reintente para
        // siempre y termine desactivando el webhook.
        $this->update(['update_id' => 9, 'poll' => ['id' => 'p1']])->assertOk();

        $this->assertSame(0, Message::count());
    }

    // ── La salida ──────────────────────────────────────────────────────────

    public function test_responder_sale_por_telegram_al_chat_correcto(): void
    {
        $this->update($this->mensajeDeTexto())->assertOk();
        $this->fakeTelegramOk();

        $conversation = Conversation::firstOrFail();

        app(ChannelRouter::class)->forConversation($conversation)
            ->sendText($conversation, 'gracias por escribir');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/bot123:ABC/sendMessage')
                && $request->data()['chat_id'] === '99887766'
                && $request->data()['text'] === 'gracias por escribir';
        });

        $saliente = Message::where('content_text', 'gracias por escribir')->firstOrFail();
        $this->assertSame('sent', $saliente->status);
        $this->assertSame('555', $saliente->external_message_id);
    }

    public function test_un_fallo_de_telegram_deja_el_mensaje_como_fallido_con_el_motivo(): void
    {
        $this->update($this->mensajeDeTexto())->assertOk();
        $conversation = Conversation::firstOrFail();

        // El motivo de Telegram explica mucho más que un código HTTP, y es lo
        // que va a leer quien mire por qué no salió un mensaje.
        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'bot was blocked by the user'], 403)]);

        // ⚠️ `$this->fail()` NO puede ir dentro del `try`: la excepción de
        // PHPUnit extiende `RuntimeException`, así que este mismo `catch` la
        // atraparía y el test pasaría diciendo lo contrario de lo que mide.
        $lanzo = false;

        try {
            app(ChannelRouter::class)->forConversation($conversation)->sendText($conversation, 'intento fallido');
        } catch (\RuntimeException $e) {
            $lanzo = true;
            $this->assertStringContainsString('bot was blocked by the user', $e->getMessage());
        }

        $this->assertTrue($lanzo, 'Un fallo de Telegram tiene que propagarse.');

        // La fila queda como fallida y NO se borra: que se haya intentado es
        // parte del historial.
        // Texto distinto del entrante a propósito: buscar por «hola» habría
        // encontrado el mensaje que llegó, no el que no salió.
        $this->assertSame('failed', Message::where('content_text', 'intento fallido')->firstOrFail()->status);
    }

    public function test_la_ventana_de_una_conversacion_de_telegram_no_vence(): void
    {
        $this->update($this->mensajeDeTexto())->assertOk();

        $conversation = Conversation::firstOrFail();
        Message::where('conversation_id', $conversation->id)
            ->update(['created_at' => now()->subDays(10)]);

        $window = app(\App\Services\WhatsApp\ServiceWindow::class)->for($conversation->fresh());

        $this->assertTrue($window['is_open']);
        $this->assertNull($window['window_hours']);
    }
}
