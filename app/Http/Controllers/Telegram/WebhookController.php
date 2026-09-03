<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Jobs\DownloadTelegramMediaJob;
use App\Models\ChannelConfig;
use App\Services\Channels\ChannelRules;
use App\Services\Channels\InboundMessage;
use App\Services\Channels\Ingestor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Entrada de Telegram.
 *
 * Es un **borde**: traduce el `update` de Telegram a un `InboundMessage` y se
 * lo pasa al `Ingestor`. Todo lo demás —contacto, identidad, conversación,
 * lead, auto-etiquetas, el orden de la cola— ya lo hace el motor sin saber de
 * canales. Por eso este archivo es corto: es el pago de F0.
 *
 * **La cuenta va en la URL.** Telegram no manda nada que permita deducirla —no
 * hay equivalente del `phone_number_id` de Meta— y con un bot por cuenta un
 * webhook único no podría resolverla.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, string $accountId, Ingestor $ingestor): Response
    {
        $config = ChannelConfig::activa($accountId, ChannelRules::TELEGRAM);

        // 404 y no 403: a quien esté probando URLs no se le confirma que esta
        // cuenta existe ni que tiene Telegram conectado.
        if (! $config) {
            return response('', 404);
        }

        if (! $this->secretOk($request, $config)) {
            return response('', 403);
        }

        $update = $request->all();

        // Idempotencia por `update_id`. Telegram reintenta hasta recibir un
        // 200, así que sin esto un timeout nuestro se convierte en el mismo
        // mensaje procesado tres veces — y en tres respuestas de la IA.
        if ($this->yaProcesado($accountId, $update['update_id'] ?? null)) {
            return response('', 200);
        }

        $mensaje = $this->parse($accountId, $update);

        if ($mensaje) {
            $guardado = $ingestor->handle($mensaje);

            // Después del ingest y no antes: la descarga necesita la fila del
            // mensaje, y va en cola para que el 200 salga ya.
            if ($guardado && $mensaje->mediaRef) {
                DownloadTelegramMediaJob::dispatch($guardado->id);
            }
        }

        // SIEMPRE 200, incluso para lo que no se entiende. Un update de un tipo
        // que no procesamos no es un error, y devolver otra cosa hace que
        // Telegram reintente para siempre y termine desactivando el webhook.
        return response('', 200);
    }

    /**
     * `hash_equals` y no `===`: la comparación de un secreto tiene que tardar
     * lo mismo acierte o falle.
     */
    private function secretOk(Request $request, ChannelConfig $config): bool
    {
        $esperado = $config->credential('webhook_secret');

        return $esperado !== null
            && hash_equals($esperado, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'));
    }

    private function yaProcesado(string $accountId, mixed $updateId): bool
    {
        if (! $updateId) {
            return false;
        }

        // Caché y no base: es una marca efímera contra los reintentos de
        // Telegram (minutos), no un registro que haga falta conservar. El
        // mensaje en sí ya es idempotente por `external_message_id` en el
        // ingestor; esto evita además el trabajo de llegar hasta ahí.
        return ! Cache::add("tg:update:{$accountId}:{$updateId}", true, now()->addHours(6));
    }

    /**
     * Un `update` de Telegram → `InboundMessage`, o null si no es algo que
     * este proyecto procese.
     *
     * @param  array<string, mixed>  $update
     */
    private function parse(string $accountId, array $update): ?InboundMessage
    {
        // `callback_query` es el toque a un botón de un flow. El texto que
        // importa es el `data` del botón, no el rótulo: es el identificador con
        // el que el flow decide la rama.
        if ($callback = $update['callback_query'] ?? null) {
            $mensaje = $callback['message'] ?? [];

            return $this->build($accountId, [
                'from' => $callback['from'] ?? [],
                'chat' => $mensaje['chat'] ?? [],
                'message_id' => $callback['id'] ?? null,
                'text' => $callback['data'] ?? '',
            ], interactiveReplyId: $callback['data'] ?? null);
        }

        // Un mensaje editado se trata como uno nuevo: es lo que el cliente
        // quiso decir, y descartarlo dejaría la conversación mostrando el texto
        // que él mismo corrigió.
        $mensaje = $update['message'] ?? $update['edited_message'] ?? null;

        if (! $mensaje) {
            return null;
        }

        [$tipo, $fileId] = $this->adjunto($mensaje);

        return $this->build($accountId, [
            'from' => $mensaje['from'] ?? [],
            'chat' => $mensaje['chat'] ?? [],
            'message_id' => $mensaje['message_id'] ?? null,
            // El pie de foto ES el texto del mensaje cuando hay adjunto.
            'text' => $mensaje['text'] ?? $mensaje['caption'] ?? null,
            'reply_to' => $mensaje['reply_to_message']['message_id'] ?? null,
            'type' => $tipo,
            'file_id' => $fileId,
        ]);
    }

    /**
     * Qué adjunto trae el mensaje, si trae alguno.
     *
     * Devuelve el `file_id`, **no el archivo**: bajarlo acá haría que el 200 se
     * demore lo que tarde la descarga, y Telegram reintenta lo que tarda — un
     * video de 20 MB se convertiría en el mismo mensaje procesado tres veces.
     * Lo baja `DownloadTelegramMediaJob`.
     *
     * @param  array<string, mixed>  $mensaje
     * @return array{0:string,1:?string} [content_type, file_id]
     */
    private function adjunto(array $mensaje): array
    {
        // `photo` es un ARRAY de tamaños, del más chico al más grande. Se toma
        // el último: los primeros son miniaturas y guardar una en vez del
        // original daría una imagen ilegible.
        if ($fotos = $mensaje['photo'] ?? null) {
            return ['image', end($fotos)['file_id'] ?? null];
        }

        foreach (['video' => 'video', 'voice' => 'audio', 'audio' => 'audio', 'document' => 'document', 'sticker' => 'sticker'] as $campo => $tipo) {
            if ($file = $mensaje[$campo]['file_id'] ?? null) {
                return [$tipo, $file];
            }
        }

        return ['text', null];
    }

    /** @param array<string, mixed> $datos */
    private function build(string $accountId, array $datos, ?string $interactiveReplyId = null): ?InboundMessage
    {
        $from = $datos['from'] ?? [];
        $senderId = isset($from['id']) ? (string) $from['id'] : null;

        if (! $senderId) {
            return null;
        }

        // Telegram parte el nombre en dos y el usuario puede no tener apellido
        // ni username. Se arma el mejor nombre disponible en vez de dejarlo
        // vacío: el contacto no tiene teléfono, así que el nombre es lo único
        // con lo que un asesor lo va a reconocer.
        $nombre = trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? ''));
        $nombre = $nombre !== '' ? $nombre : ($from['username'] ?? null);

        return new InboundMessage(
            accountId: $accountId,
            channel: ChannelRules::TELEGRAM,
            senderExternalId: $senderId,
            senderName: $nombre,
            // El chat puede no ser el remitente (un grupo), así que se guarda
            // aparte: es a DÓNDE se responde.
            threadExternalId: isset($datos['chat']['id']) ? (string) $datos['chat']['id'] : $senderId,
            contentType: $datos['type'] ?? 'text',
            contentText: $datos['text'] ?? null,
            // El `file_id` de Telegram. Lo baja `DownloadTelegramMediaJob`;
            // acá solo se anota cuál es.
            mediaRef: $datos['file_id'] ?? null,
            externalMessageId: isset($datos['message_id']) ? (string) $datos['message_id'] : null,
            replyToExternalId: isset($datos['reply_to']) ? (string) $datos['reply_to'] : null,
            interactiveReplyId: $interactiveReplyId,
        );
    }
}
