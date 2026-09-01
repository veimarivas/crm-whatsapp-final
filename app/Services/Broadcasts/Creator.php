<?php

namespace App\Services\Broadcasts;

use App\Jobs\SendBroadcastJob;
use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\MessageTemplate;
use App\Services\WhatsApp\ServiceWindow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creación de broadcasts (resolución de audiencia + destinatarios +
 * encolado). Compartido por la UI web y la API pública para que las
 * reglas no diverjan.
 *
 * Desde D1a resuelve DOS cuerpos y TRES audiencias:
 *
 *  - cuerpo `template` (plantilla aprobada) o `text` (mensaje de sesión);
 *  - audiencia `all` / `tags` (contactos de este proyecto) o `phones` (lista
 *    explícita, que es como Komo expresa un segmento suyo).
 *
 * @throws InvalidArgumentException con mensaje apto para el usuario.
 */
class Creator
{
    /**
     * @param  array{name:string, body_type?:string, body_text?:?string,
     *               body_media_path?:?string, template_name?:?string,
     *               template_language?:?string, template_variables?:array,
     *               header_media_url?:?string, audience:string, tag_ids?:array,
     *               recipients?:array, scheduled_at?:?string}  $data
     */
    public function create(string $accountId, array $data): Broadcast
    {
        $bodyType = $data['body_type'] ?? Broadcast::BODY_TEMPLATE;

        $this->assertBodyUsable($accountId, $bodyType, $data);

        $recipients = $data['audience'] === 'phones'
            ? $this->fromPhones($accountId, $data['recipients'] ?? [])
            : $this->fromContacts($accountId, $data);

        if ($recipients === []) {
            throw new InvalidArgumentException('La audiencia seleccionada no tiene contactos.');
        }

        $report = $this->audienceReport($accountId, $bodyType, $recipients);

        // Un envío de texto a quien tiene la ventana cerrada NO se manda: Meta
        // lo rechaza y, peor, el pedido igual figura como intento. Se descarta
        // acá y se dice cuántos, en vez de fallar de a uno en el job.
        if ($bodyType === Broadcast::BODY_TEXT) {
            $recipients = array_values(array_filter($recipients, fn ($r) => $r['in_window']));

            if ($recipients === []) {
                throw new InvalidArgumentException(
                    'Ningún destinatario tiene la ventana de 24 h abierta: un mensaje de texto no les llegaría. Usá una plantilla aprobada.'
                );
            }
        }

        $scheduledAt = $data['scheduled_at'] ?? null;

        $broadcast = DB::transaction(function () use ($accountId, $data, $bodyType, $recipients, $report, $scheduledAt) {
            $broadcast = Broadcast::create([
                'account_id' => $accountId,
                'name' => $data['name'],
                'body_type' => $bodyType,
                'body_text' => $data['body_text'] ?? null,
                'body_media_path' => $data['body_media_path'] ?? null,
                'template_name' => $data['template_name'] ?? null,
                'template_language' => $data['template_language'] ?? null,
                'template_variables' => array_values($data['template_variables'] ?? []),
                'header_media_url' => $data['header_media_url'] ?? null,
                'audience_filter' => [
                    'type' => $data['audience'],
                    'tag_ids' => $data['tag_ids'] ?? [],
                    // El informe queda GUARDADO, no solo devuelto: dentro de una
                    // semana, «se mandó a 40 de 300» tiene que poder contestarse
                    // sin reconstruir la audiencia de aquel día.
                    'report' => $report,
                ],
                'scheduled_at' => $scheduledAt,
                'status' => $scheduledAt ? 'scheduled' : 'sending',
                'total_recipients' => count($recipients),
            ]);

            $now = now();
            $rows = array_map(fn (array $r) => [
                'id' => (string) Str::uuid(),
                'broadcast_id' => $broadcast->id,
                'contact_id' => $r['contact_id'],
                'phone' => $r['phone'],
                'external_ref' => $r['external_ref'],
                'status' => 'pending',
                'created_at' => $now,
            ], $recipients);

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('broadcast_recipients')->insert($chunk);
            }

            return $broadcast;
        });

        if (! $broadcast->scheduled_at) {
            SendBroadcastJob::dispatch($broadcast->id);
        }

        return $broadcast;
    }

    /**
     * Que el cuerpo se pueda enviar de verdad, antes de crear nada.
     */
    private function assertBodyUsable(string $accountId, string $bodyType, array $data): void
    {
        if ($bodyType === Broadcast::BODY_TEXT) {
            if (trim((string) ($data['body_text'] ?? '')) === '') {
                throw new InvalidArgumentException('El mensaje no puede estar vacío.');
            }

            return;
        }

        $templateOk = MessageTemplate::forAccount($accountId)
            ->where('name', $data['template_name'] ?? '')
            ->where('language', $data['template_language'] ?? '')
            ->where('status', 'APPROVED')
            ->exists();

        if (! $templateOk) {
            throw new InvalidArgumentException('La plantilla no existe o no está aprobada.');
        }
    }

    /**
     * Audiencia por contactos de ESTE proyecto (`all` / `tags`), que es como la
     * arma la UI web. Sin cambios de comportamiento respecto de antes de D1a.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fromContacts(string $accountId, array $data): array
    {
        // Segmentación combinada: tag_ids AND status_conv AND last_message_days
        // Todos los filtros son OPCIONALES; se aplican los que estén presentes.
        // audience='all' = sin filtro de tag (pero puede tener otros filtros).
        $contacts = Contact::forAccount($accountId)
            ->when($data['audience'] === 'tags' && ! empty($data['tag_ids'] ?? []), function ($query) use ($data) {
                $query->whereHas('tags', fn ($w) => $w->whereIn('tags.id', $data['tag_ids']));
            })
            // Filtro por estado de conversación (open/pending/closed) — al menos una conv en ese estado
            ->when(! empty($data['conv_status'] ?? null), function ($query) use ($data) {
                $query->whereHas('conversations', fn ($w) => $w->where('status', $data['conv_status']));
            })
            // Filtro por último mensaje > X días (contactos "fríos")
            ->when(($data['last_message_days'] ?? 0) > 0, function ($query) use ($data) {
                $cutoff = now()->subDays((int) $data['last_message_days']);
                $query->whereHas('conversations', fn ($w) => $w->where('last_message_at', '<', $cutoff));
            })
            // Filtro por fuente (contactos que llegaron via ad, form, etc.) — via referral en 1er mensaje
            ->when(! empty($data['source'] ?? null), function ($query) use ($data) {
                if ($data['source'] === 'ad') {
                    $query->whereHas('conversations', fn ($w) => $w->whereNotNull('entry_ad_id'));
                }
            })
            ->get(['id', 'phone', 'phone_normalized']);

        return $contacts->map(fn (Contact $c) => [
            'contact_id' => $c->id,
            'phone' => $c->phone_normalized ?? $c->phone,
            'external_ref' => null,
            'in_window' => false,
        ])->all();
    }

    /**
     * Audiencia por lista explícita de teléfonos — la forma en que Komo manda
     * el resultado de su `SegmentQuery`, que este proyecto no puede reproducir
     * (no conoce leads, ni etapas, ni responsables).
     *
     * Un teléfono que acá no tiene contacto NO se descarta: es gente real que
     * llegó por formulario web o correo. Se registra sin `contact_id` y, si
     * contesta, el camino de entrada le crea el contacto como a cualquiera.
     *
     * @param  array<int, array|string>  $input
     * @return array<int, array<string, mixed>>
     */
    private function fromPhones(string $accountId, array $input): array
    {
        $wanted = [];

        foreach ($input as $item) {
            $phone = Contact::normalizePhone(is_array($item) ? ($item['phone'] ?? null) : $item);

            // Dedup por teléfono normalizado: el mismo humano en dos leads de
            // Komo es un solo mensaje de WhatsApp.
            if (! $phone || isset($wanted[$phone])) {
                continue;
            }

            $wanted[$phone] = is_array($item) ? ($item['external_ref'] ?? null) : null;
        }

        if ($wanted === []) {
            return [];
        }

        $contacts = Contact::forAccount($accountId)
            ->whereIn('phone_normalized', array_keys($wanted))
            ->pluck('id', 'phone_normalized');

        $out = [];

        foreach ($wanted as $phone => $externalRef) {
            $out[] = [
                'contact_id' => $contacts[$phone] ?? null,
                'phone' => (string) $phone,
                'external_ref' => $externalRef,
                'in_window' => false,
            ];
        }

        return $out;
    }

    /**
     * Marca cuáles están dentro de la ventana y arma el informe que se devuelve
     * al llamador y se guarda en el broadcast.
     *
     * Para una plantilla la ventana no cambia nada (se puede escribir igual, y
     * se cobra), así que el cálculo solo se hace cuando el cuerpo es texto.
     *
     * @param  array<int, array<string, mixed>>  $recipients  se modifica in place
     * @return array<string, mixed>
     */
    private function audienceReport(string $accountId, string $bodyType, array &$recipients): array
    {
        $requested = count($recipients);
        $unknown = 0;

        $contactIds = [];
        foreach ($recipients as $r) {
            if ($r['contact_id'] === null) {
                $unknown++;
            } else {
                $contactIds[] = $r['contact_id'];
            }
        }

        if ($bodyType !== Broadcast::BODY_TEXT) {
            foreach ($recipients as &$r) {
                $r['in_window'] = true;
            }
            unset($r);

            return [
                'requested' => $requested,
                'unknown_contacts' => $unknown,
                'out_of_window' => 0,
                'sending_to' => $requested,
                'excluded' => [],
                'excluded_truncated' => false,
            ];
        }

        $windows = $contactIds === [] ? [] : app(ServiceWindow::class)->forContacts($contactIds);

        $outOfWindow = 0;
        $excluded = [];

        foreach ($recipients as &$r) {
            // Sin contacto acá no hay historial de mensajes, así que no hay
            // ventana abierta que valga: un texto libre no le llegaría.
            $r['in_window'] = $r['contact_id'] !== null
                && ($windows[$r['contact_id']]['is_open'] ?? false);

            if (! $r['in_window']) {
                $outOfWindow++;

                // La lista, no solo el número: quien pidió el envío tiene que
                // poder marcar SUS filas, y un total no le dice cuáles.
                // Se corta en 1.000 para no devolver un payload gigante; el
                // conteo de arriba sigue siendo el completo.
                if (count($excluded) < 1000) {
                    $excluded[] = [
                        'phone' => $r['phone'],
                        'external_ref' => $r['external_ref'],
                        'reason' => $r['contact_id'] === null ? 'sin_conversacion' : 'ventana_cerrada',
                    ];
                }
            }
        }
        unset($r);

        return [
            'requested' => $requested,
            'unknown_contacts' => $unknown,
            'out_of_window' => $outOfWindow,
            'sending_to' => $requested - $outOfWindow,
            'excluded' => $excluded,
            'excluded_truncated' => $outOfWindow > count($excluded),
        ];
    }
}
