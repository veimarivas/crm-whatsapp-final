<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Models\WhatsappConfig;
use App\Services\WhatsApp\MetaApi;
use Illuminate\Http\Request;

/**
 * Proxy de media: las URLs de descarga de Meta exigen el access token
 * y expiran, así que el navegador nunca las toca directamente.
 * Equivalente a /api/whatsapp/media/[mediaId] del original.
 */
class MediaController extends Controller
{
    /**
     * Sirve el adjunto de un mensaje **de cualquier canal**.
     *
     * Los de Meta se resuelven por proxy en vivo (`show()`); los que dejaron
     * copia local —Telegram— se sirven desde disco. **El archivo nunca se
     * expone por una URL de Telegram**: la suya caduca y lleva el bot token
     * adentro, así que quien la viera tendría control total del bot.
     */
    public function channelMedia(Request $request, \App\Models\Message $message)
    {
        // El corte de cuenta va acá y no en la ruta: un uuid de mensaje es
        // adivinable de a poco, y sin esto un usuario podría leer el adjunto de
        // otra empresa.
        abort_if($message->conversation?->account_id !== $request->user()->account_id, 403);

        abort_if(! $message->media_path || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($message->media_path), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response(
            $message->media_path,
            null,
            [
                'Content-Type' => $message->media_mime ?: 'application/octet-stream',
                // `private`: es contenido de una conversación, no de un CDN.
                'Cache-Control' => 'private, max-age=3600',
            ],
        );
    }

    public function show(Request $request, string $mediaId)
    {
        $config = WhatsappConfig::forAccount($request->user()->account_id)->firstOrFail();
        $api = MetaApi::for($config);

        $url = $api->getMediaUrl($mediaId);
        abort_if(! $url, 404);

        $media = $api->downloadMedia($url);
        abort_if($media->failed(), 502);

        return response($media->body(), 200)
            ->header('Content-Type', $media->header('Content-Type') ?: 'application/octet-stream')
            ->header('Cache-Control', 'private, max-age=3600');
    }
}
