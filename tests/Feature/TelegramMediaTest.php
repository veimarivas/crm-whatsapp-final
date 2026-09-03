<?php

namespace Tests\Feature;

use App\Jobs\DownloadTelegramMediaJob;
use App\Models\Account;
use App\Models\ChannelConfig;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Channels\ChannelRouter;
use App\Services\Channels\ChannelRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * F1.c — adjuntos de Telegram.
 *
 * **La descarga va en cola, no en el webhook.** Telegram reintenta lo que
 * tarda, así que bajar un video de 20 MB antes de devolver el 200 convierte un
 * adjunto grande en el mismo mensaje procesado tres veces.
 *
 * Y la copia local no es una optimización: el link de Telegram **caduca y lleva
 * el bot token adentro**, así que no se puede guardar ni exponer al navegador.
 */
class TelegramMediaTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private const SECRETO = 'sec_telegram_123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        ChannelConfig::create([
            'account_id' => $this->account->id,
            'channel' => ChannelRules::TELEGRAM,
            'is_enabled' => true,
            'credentials' => ['bot_token' => '123:ABC', 'webhook_secret' => self::SECRETO],
            'connected_at' => now(),
        ]);

        Storage::fake('local');
    }

    private function update(array $mensaje, int $updateId = 1)
    {
        return $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::SECRETO)
            ->postJson("/webhooks/telegram/{$this->account->id}", [
                'update_id' => $updateId,
                'message' => array_merge([
                    'message_id' => 100,
                    'from' => ['id' => 99887766, 'first_name' => 'Ana'],
                    'chat' => ['id' => 99887766, 'type' => 'private'],
                ], $mensaje),
            ]);
    }

    public function test_una_foto_guarda_el_tamano_mas_grande_y_encola_la_descarga(): void
    {
        Queue::fake();

        $this->update([
            // Telegram manda un ARRAY de tamaños, del más chico al más grande.
            'photo' => [
                ['file_id' => 'miniatura', 'width' => 90],
                ['file_id' => 'original', 'width' => 1280],
            ],
            'caption' => 'mirá esto',
        ])->assertOk();

        $message = Message::firstOrFail();

        // Quedarse con el primero guardaría una miniatura ilegible.
        $this->assertSame('original', $message->media_url);
        $this->assertSame('image', $message->content_type);
        // El pie de foto ES el texto del mensaje.
        $this->assertSame('mirá esto', $message->content_text);

        Queue::assertPushed(DownloadTelegramMediaJob::class);
    }

    public function test_un_mensaje_de_texto_no_encola_ninguna_descarga(): void
    {
        Queue::fake();

        $this->update(['text' => 'hola'])->assertOk();

        Queue::assertNotPushed(DownloadTelegramMediaJob::class);
    }

    public function test_la_descarga_guarda_el_archivo_y_anota_la_copia(): void
    {
        Queue::fake();
        $this->update(['document' => ['file_id' => 'doc-1'], 'caption' => 'el contrato'])->assertOk();

        $message = Message::firstOrFail();
        $this->assertSame('document', $message->content_type);

        Http::fake([
            '*/getFile' => Http::response(['ok' => true, 'result' => ['file_path' => 'documents/contrato.pdf']]),
            '*/file/bot*' => Http::response('%PDF-1.4 contenido'),
        ]);

        (new DownloadTelegramMediaJob($message->id))->handle();

        $message->refresh();

        $this->assertNotNull($message->media_path);
        Storage::disk('local')->assertExists($message->media_path);
        $this->assertSame('%PDF-1.4 contenido', Storage::disk('local')->get($message->media_path));
    }

    public function test_la_descarga_no_se_repite_en_un_reintento(): void
    {
        Queue::fake();
        $this->update(['document' => ['file_id' => 'doc-1']])->assertOk();
        $message = Message::firstOrFail();

        Http::fake([
            '*/getFile' => Http::response(['ok' => true, 'result' => ['file_path' => 'documents/x.pdf']]),
            '*/file/bot*' => Http::response('contenido'),
        ]);

        (new DownloadTelegramMediaJob($message->id))->handle();
        (new DownloadTelegramMediaJob($message->id))->handle();

        // `media_path` presente = ya se bajó. Un reintento de la cola no vuelve
        // a pedirle el archivo a Telegram.
        $this->assertSame(1, Http::recorded(fn ($r) => str_contains($r->url(), '/getFile'))->count());
    }

    public function test_un_audio_dispara_la_transcripcion_recien_al_tenerlo_en_disco(): void
    {
        Queue::fake();
        $this->update(['voice' => ['file_id' => 'voz-1']])->assertOk();

        $message = Message::firstOrFail();
        $this->assertSame('audio', $message->content_type);

        // La IA NO se encoló con el mensaje: el ingestor la difiere para audios
        // justamente para que no conteste algo que no escuchó.
        Queue::assertNotPushed(\App\Jobs\AiAutoReplyJob::class);

        Http::fake([
            '*/getFile' => Http::response(['ok' => true, 'result' => ['file_path' => 'voice/a.ogg']]),
            '*/file/bot*' => Http::response('OggS...'),
        ]);

        (new DownloadTelegramMediaJob($message->id))->handle();

        Queue::assertPushed(\App\Jobs\TranscribeAudioJob::class);
    }

    public function test_el_adjunto_se_sirve_desde_disco_y_solo_a_su_cuenta(): void
    {
        Queue::fake();
        $this->update(['document' => ['file_id' => 'doc-1']])->assertOk();
        $message = Message::firstOrFail();

        Http::fake([
            '*/getFile' => Http::response(['ok' => true, 'result' => ['file_path' => 'documents/x.pdf']]),
            '*/file/bot*' => Http::response('contenido del pdf'),
        ]);
        (new DownloadTelegramMediaJob($message->id))->handle();

        $this->actingAs($this->owner)
            ->get(route('channel.media', $message))
            ->assertOk()
            ->assertStreamedContent('contenido del pdf');

        // Un uuid de mensaje no alcanza como autorización: sin el corte por
        // cuenta, alguien podría leer el adjunto de otra empresa.
        $otroOwner = User::create(['name' => 'Otro', 'email' => 'otro@test.com', 'password' => bcrypt('x')]);
        $otraCuenta = Account::create(['name' => 'Otra', 'owner_user_id' => $otroOwner->id]);
        $otroOwner->update(['account_id' => $otraCuenta->id, 'account_role' => 'owner']);

        $this->actingAs($otroOwner)->get(route('channel.media', $message))->assertForbidden();
    }

    public function test_enviar_un_archivo_sale_por_telegram_y_deja_copia(): void
    {
        Queue::fake();
        $this->update(['text' => 'hola'])->assertOk();
        $conversation = Conversation::firstOrFail();

        Http::fake(['*/sendPhoto' => Http::response(['ok' => true, 'result' => ['message_id' => 777]])]);

        $message = app(ChannelRouter::class)->forConversation($conversation)
            ->sendMedia($conversation, 'binario-jpg', 'image/jpeg', 'foto.jpg', 'ahí va');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/bot123:ABC/sendPhoto'));

        $this->assertSame('sent', $message->status);
        $this->assertSame('777', $message->external_message_id);
        $this->assertSame('ahí va', $message->content_text);

        // La copia local también para lo que SALE: quien mire la conversación
        // dentro de un mes tiene que poder ver lo que se mandó, y del lado de
        // Telegram el link ya no va a existir.
        Storage::disk('local')->assertExists($message->media_path);
    }
}
