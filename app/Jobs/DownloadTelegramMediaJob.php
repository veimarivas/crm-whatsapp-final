<?php

namespace App\Jobs;

use App\Models\ChannelConfig;
use App\Models\Message;
use App\Services\Channels\ChannelRules;
use App\Services\Telegram\TelegramApi;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Baja a almacenamiento propio el adjunto de un mensaje de Telegram.
 *
 * **Va en cola y no en el webhook**, y no es una preferencia: el webhook tiene
 * que devolver 200 rápido. Telegram reintenta lo que tarda, así que descargar
 * un video de 20 MB antes de responder convierte un adjunto grande en el mismo
 * mensaje procesado tres veces.
 *
 * El link de descarga de Telegram **caduca en ~1 h y lleva el bot token
 * adentro**, así que la copia local no es una optimización: es la única forma
 * de que el archivo siga estando dentro de un mes sin exponer la credencial.
 */
class DownloadTelegramMediaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 180;

    public function __construct(public readonly string $messageId) {}

    public function handle(): void
    {
        $message = Message::find($this->messageId);

        // `media_path` ya presente = ya se bajó. Un reintento de la cola no
        // vuelve a pedirle el archivo a Telegram.
        if (! $message || $message->channel !== ChannelRules::TELEGRAM || ! $message->media_url || $message->media_path) {
            return;
        }

        $config = ChannelConfig::activa($message->conversation->account_id, ChannelRules::TELEGRAM);

        if (! $config) {
            return;
        }

        try {
            [$contents, $filename] = TelegramApi::for($config)->downloadFile($message->media_url);
        } catch (\Throwable $e) {
            // Se loguea y se deja reintentar: el link caduca, pero `file_id` no
            // — se puede volver a pedir más tarde.
            Log::warning('Telegram: falló la descarga del adjunto', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: 'bin';
        $path = 'channel-media/'.Str::uuid().'.'.$extension;

        Storage::disk('local')->put($path, $contents);

        $message->update([
            'media_path' => $path,
            'media_mime' => Storage::disk('local')->mimeType($path) ?: null,
        ]);

        // Recién ahora se puede transcribir: el audio ya está en disco. La IA
        // está esperando esto — el ingestor no la encola para audios
        // justamente para que no conteste algo que no escuchó.
        if ($message->content_type === 'audio') {
            TranscribeAudioJob::dispatch($message->id);
        }
    }
}
