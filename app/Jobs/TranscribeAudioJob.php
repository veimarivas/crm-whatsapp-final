<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\WhatsappConfig;
use App\Services\WhatsApp\MetaApi;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Transcribe un mensaje de audio del cliente usando whisper.cpp en el servidor.
 *
 * Flujo:
 * 1. Descarga el archivo de audio desde Meta usando el media_id guardado.
 * 2. Lo guarda temporalmente en disco.
 * 3. Ejecuta whisper.cpp con el modelo configurado en `services.whisper.model`.
 * 4. Parsea el texto de salida y lo guarda en `messages.transcript`.
 * 5. Borra el archivo temporal.
 *
 * Requiere whisper.cpp instalado en el servidor. Ver docs para el setup.
 */
class TranscribeAudioJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 180; // audios largos pueden tardar

    public function __construct(public readonly string $messageId)
    {
    }

    public function handle(): void
    {
        $message = Message::find($this->messageId);

        if (! $message || $message->content_type !== 'audio' || $message->transcript || ! $message->media_url) {
            return; // no aplica o ya fue transcrito
        }

        $binary = config('services.whisper.binary');
        $model = config('services.whisper.model');
        $language = config('services.whisper.language', 'es');

        if (! $binary || ! is_executable($binary)) {
            Log::info('TranscribeAudioJob: whisper.cpp no configurado, skip', ['message_id' => $message->id]);
            return; // silencioso si no está instalado
        }

        // 1. Conseguir el audio.
        //
        // F1: los canales que NO se resuelven por proxy dejan una copia local
        // (`media_path`) — Telegram, porque su link caduca y lleva el bot token
        // adentro. Si está, se usa esa y no se le pide nada a Meta: pedírselo
        // fallaría, porque el `media_url` de esos mensajes es un `file_id` de
        // otro proveedor.
        if ($message->media_path && Storage::disk('local')->exists($message->media_path)) {
            $contents = Storage::disk('local')->get($message->media_path);
        } else {
            $config = WhatsappConfig::forAccount($message->conversation->account_id)
                ->where('status', 'connected')
                ->first();

            if (! $config) {
                return;
            }

            try {
                $api = MetaApi::for($config);
                $url = $api->getMediaUrl($message->media_url);
                if (! $url) {
                    Log::warning('TranscribeAudioJob: no se pudo obtener URL del media', ['message_id' => $message->id]);
                    return;
                }

                $contents = $api->downloadMedia($url)->body();
            } catch (\Throwable $e) {
                Log::warning('TranscribeAudioJob: falló descarga', ['message_id' => $message->id, 'error' => $e->getMessage()]);
                return;
            }
        }

        // 2. Guardar temporal (whisper.cpp requiere archivo en disco)
        $tempDir = storage_path('app/transcribe');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }
        $oggFile = $tempDir.'/'.$message->id.'.ogg';
        $wavFile = $tempDir.'/'.$message->id.'.wav';
        file_put_contents($oggFile, $contents);

        try {
            // 3. Convertir OGG → WAV 16kHz mono con ffmpeg (formato nativo de whisper).
            //    Sin este paso, whisper.cpp compilado sin soporte de decodificación
            //    devuelve texto vacío en vez de error. WhatsApp siempre manda .ogg/opus.
            $ffmpegCmd = sprintf(
                'ffmpeg -y -i %s -ar 16000 -ac 1 -c:a pcm_s16le %s 2>&1',
                escapeshellarg($oggFile),
                escapeshellarg($wavFile)
            );
            exec($ffmpegCmd, $ffmpegOut, $ffmpegCode);

            if ($ffmpegCode !== 0 || ! is_file($wavFile)) {
                Log::warning('TranscribeAudioJob: conversión ffmpeg falló', [
                    'message_id' => $message->id,
                    'return_code' => $ffmpegCode,
                    'output' => implode("\n", array_slice($ffmpegOut, -5)),
                ]);
                return;
            }

            // 4. Ejecutar whisper.cpp sobre el WAV.
            $outputPrefix = $tempDir.'/'.$message->id;
            $cmd = sprintf(
                '%s -m %s -f %s -l %s -otxt -nt --output-file %s 2>&1',
                escapeshellcmd($binary),
                escapeshellarg($model),
                escapeshellarg($wavFile),
                escapeshellarg($language),
                escapeshellarg($outputPrefix)
            );
            exec($cmd, $output, $returnCode);

            if ($returnCode !== 0) {
                Log::warning('TranscribeAudioJob: whisper.cpp falló', [
                    'message_id' => $message->id,
                    'return_code' => $returnCode,
                    'output' => implode("\n", array_slice($output, -10)),
                ]);
                return;
            }

            // 5. Leer archivo .txt de salida
            $txtFile = $outputPrefix.'.txt';
            $transcript = is_file($txtFile) ? trim(file_get_contents($txtFile)) : '';

            if ($transcript !== '') {
                $message->update(['transcript' => $transcript]);

                // Notifica a integraciones (Komo) que el audio ya tiene texto.
                // El receptor debe encontrar el evento previo por wamid y
                // actualizar su payload con la transcripción.
                try {
                    app(\App\Services\Webhooks\Dispatcher::class)->dispatch(
                        $message->conversation->account_id,
                        'message.transcribed',
                        [
                            'conversation_id' => $message->conversation_id,
                            'message' => [
                                'id' => $message->id,
                                'wamid' => $message->message_id,
                                'transcript' => $transcript,
                                'sender_type' => $message->sender_type,
                            ],
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::warning('Webhook message.transcribed falló', ['error' => $e->getMessage()]);
                }

                // Recién ahora que el audio tiene texto la IA puede responder:
                // se difiere acá (InboundProcessor no encola el bot para
                // audios) para que la respuesta use la transcripción.
                AiAutoReplyJob::dispatch($message->conversation_id);
            } else {
                Log::info('TranscribeAudioJob: whisper devolvió texto vacío', ['message_id' => $message->id]);
            }

            @unlink($txtFile);
        } finally {
            @unlink($oggFile);
            @unlink($wavFile);
        }
    }
}
