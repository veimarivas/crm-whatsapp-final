<?php

namespace App\Jobs;

use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Contact;
use App\Models\WhatsappConfig;
use App\Services\WhatsApp\MetaApi;
use App\Services\WhatsApp\ServiceWindow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Envía un broadcast completo: recorre los destinatarios pendientes,
 * sustituye variables por contacto y envía vía Meta.
 * Equivalente al motor de src/lib/whatsapp/broadcast-core.ts.
 *
 * Desde D1a envía los dos cuerpos: plantilla aprobada (lo de siempre) y
 * mensaje de sesión de texto libre, que es lo que le permitió a Komo apagar su
 * motor paralelo. La diferencia que importa está en `sendText()`: **la ventana
 * se vuelve a mirar acá**, no solo al crear. Un broadcast programado se arma
 * hoy y sale mañana, y para entonces la ventana de medio mundo se cerró.
 */
class SendBroadcastJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    private ?string $headerType = null;

    private bool $headerTypeResolved = false;

    /** media_id de Meta del adjunto, subido una sola vez para todo el envío. */
    private ?string $mediaId = null;

    private bool $mediaResolved = false;

    public function __construct(public readonly string $broadcastId)
    {
    }

    public function handle(): void
    {
        $broadcast = Broadcast::find($this->broadcastId);

        if (! $broadcast || ! in_array($broadcast->status, ['scheduled', 'sending'], true)) {
            return;
        }

        $config = WhatsappConfig::forAccount($broadcast->account_id)
            ->where('status', 'connected')
            ->first();

        if (! $config) {
            $broadcast->update(['status' => 'failed']);

            return;
        }

        $broadcast->update(['status' => 'sending']);
        $api = MetaApi::for($config);

        $broadcast->recipients()
            ->where('status', 'pending')
            ->with('contact')
            ->chunkById(50, function ($recipients) use ($broadcast, $api) {
                // La ventana se resuelve por lote, no por destinatario: son dos
                // queries para los 50 en vez de dos por cada uno.
                $windows = $broadcast->isText()
                    ? app(ServiceWindow::class)->forContacts(
                        $recipients->pluck('contact_id')->filter()->values()->all()
                    )
                    : [];

                foreach ($recipients as $recipient) {
                    $this->sendToRecipient($broadcast, $api, $recipient, $windows);
                }
            });

        $broadcast->refresh();
        $broadcast->update(['status' => $broadcast->sent_count > 0 ? 'sent' : 'failed']);

        app(\App\Services\Webhooks\Dispatcher::class)->dispatch($broadcast->account_id, 'broadcast.completed', [
            'broadcast' => $broadcast->only([
                'id', 'name', 'status', 'total_recipients', 'sent_count', 'failed_count',
            ]),
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $windows  ventana por contact_id
     */
    private function sendToRecipient(Broadcast $broadcast, MetaApi $api, BroadcastRecipient $recipient, array $windows): void
    {
        $contact = $recipient->contact;

        // El contacto es opcional SOLO en audiencias por teléfono (las que
        // manda Komo), donde el destinatario puede no existir todavía acá. En
        // una audiencia de este proyecto, un contacto que ya no está es un
        // contacto BORRADO, y a un borrado no se le escribe.
        if (! $contact && ($broadcast->audience_filter['type'] ?? null) !== 'phones') {
            $this->fail($broadcast, $recipient, 'Contacto eliminado');

            return;
        }

        // El teléfono lo trae la fila; el contacto es opcional desde D1a
        // (Komo manda audiencias con gente que este proyecto no conoce).
        $phone = $recipient->phone ?: ($contact?->phone_normalized ?? $contact?->phone);

        if (! $phone) {
            $this->fail($broadcast, $recipient, 'Destinatario sin teléfono');

            return;
        }

        if ($broadcast->isText() && ! ($windows[$recipient->contact_id]['is_open'] ?? false)) {
            // No se intenta: Meta rechazaría el texto libre y el error diría
            // «(#131047) Re-engagement message», que no le explica nada a nadie.
            $this->fail($broadcast, $recipient, 'Ventana de 24 h cerrada: hace falta una plantilla aprobada.');

            return;
        }

        try {
            $result = $broadcast->isText()
                ? $this->sendText($broadcast, $api, $phone, $contact)
                : $api->sendTemplate(
                    $phone,
                    $broadcast->template_name,
                    $broadcast->template_language,
                    $this->buildComponents($broadcast, $contact),
                );

            $recipient->update([
                'status' => 'sent',
                'sent_at' => now(),
                'whatsapp_message_id' => $result['messages'][0]['id'] ?? null,
            ]);
            $broadcast->increment('sent_count');
        } catch (\Throwable $e) {
            Log::warning('Broadcast: fallo enviando a destinatario', [
                'recipient_id' => $recipient->id,
                'error' => $e->getMessage(),
            ]);

            $this->fail($broadcast, $recipient, mb_substr($e->getMessage(), 0, 500));
        }
    }

    private function fail(Broadcast $broadcast, BroadcastRecipient $recipient, string $reason): void
    {
        $recipient->update(['status' => 'failed', 'error_message' => $reason]);
        $broadcast->increment('failed_count');
    }

    /**
     * Mensaje de sesión: imagen con el texto de pie si el broadcast trae
     * adjunto, texto suelto si no.
     *
     * El adjunto se sube a Meta UNA vez para todo el envío: subirlo por
     * destinatario sería una carga completa del archivo por cada mensaje.
     */
    private function sendText(Broadcast $broadcast, MetaApi $api, string $phone, ?Contact $contact): array
    {
        $text = $this->interpolate($broadcast->body_text ?? '', $contact);

        if ($broadcast->body_media_path) {
            if (! $this->mediaResolved) {
                $disk = Storage::disk('local');

                if ($disk->exists($broadcast->body_media_path)) {
                    $this->mediaId = $api->uploadMedia(
                        $disk->get($broadcast->body_media_path),
                        $disk->mimeType($broadcast->body_media_path) ?: 'image/jpeg',
                        basename($broadcast->body_media_path),
                    );
                }

                $this->mediaResolved = true;
            }

            if ($this->mediaId) {
                return $api->sendMedia($phone, 'image', $this->mediaId, $text);
            }
        }

        return $api->sendText($phone, $text);
    }

    /** Los mismos tokens que acepta el cuerpo de una plantilla. */
    private function interpolate(string $text, ?Contact $contact): string
    {
        return strtr($text, [
            '{name}' => $contact->name ?? '',
            '{phone}' => $contact->phone ?? '',
            '{email}' => $contact->email ?? '',
            '{company}' => $contact->company ?? '',
        ]);
    }

    /**
     * template_variables es un array posicional de strings para los
     * parámetros {{1}}, {{2}}… del body. Cada valor admite tokens
     * {name}, {phone}, {email}, {company} sustituidos por contacto.
     * Si el broadcast trae header_media_url, se añade el componente de
     * encabezado (imagen/video/documento por link) que Meta exige para
     * plantillas con header multimedia.
     */
    private function buildComponents(Broadcast $broadcast, ?Contact $contact): array
    {
        $components = [];

        if ($broadcast->header_media_url) {
            if (! $this->headerTypeResolved) {
                $this->headerType = \App\Models\MessageTemplate::forAccount($broadcast->account_id)
                    ->where('name', $broadcast->template_name)
                    ->where('language', $broadcast->template_language)
                    ->value('header_type');
                $this->headerTypeResolved = true;
            }
            $headerType = $this->headerType;

            if (in_array($headerType, ['image', 'video', 'document'], true)) {
                $components[] = [
                    'type' => 'header',
                    'parameters' => [[
                        'type' => $headerType,
                        $headerType => ['link' => $broadcast->header_media_url],
                    ]],
                ];
            }
        }

        $variables = $broadcast->template_variables ?? [];

        if (! empty($variables)) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(fn (string $value) => [
                    'type' => 'text',
                    'text' => $this->interpolate($value, $contact),
                ], array_values($variables)),
            ];
        }

        return $components;
    }
}
