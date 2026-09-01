<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AutoTagRule;
use App\Models\CustomField;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * API v1 — Sincronización de la taxonomía (etiquetas y campos personalizados)
 * desde el Komo.
 *
 * Mismo patrón que `PipelineSyncController`: Komo es la fuente de verdad y
 * este endpoint reconcilia el catálogo local contra el payload completo.
 * Hasta D2 los dos proyectos tenían catálogos separados que no se
 * sincronizaban — a diferencia de los pipelines, que sí.
 *
 * **La diferencia con el sync de pipelines es qué pasa al borrar**, y no es un
 * detalle de implementación:
 *
 *  - Una etapa que desaparece reasigna sus deals: nada se pierde.
 *  - Una etiqueta que desaparece **desetiqueta contactos**, y si tiene una
 *    regla de auto-tagging la borra en cascada (`auto_tag_rules.tag_id` es
 *    `cascadeOnDelete`). O sea: alguien borra una etiqueta en Komo y el
 *    auto-etiquetado de este proyecto deja de funcionar **sin un solo aviso**.
 *
 * Por eso una etiqueta EN USO no se borra: se **desvincula** (`external_id` a
 * NULL) y pasa a ser una etiqueta local. Borrarla en Komo significa «no la
 * quiero en mi catálogo», no «rompé la automatización del otro lado». Lo mismo
 * con un campo personalizado que tenga valores cargados.
 *
 * La respuesta dice exactamente qué pasó con cada cosa, para que el llamador
 * pueda mostrarlo en vez de que el usuario descubra el efecto por su cuenta.
 */
class TaxonomySyncController extends Controller
{
    private function accountId(Request $request): string
    {
        return $request->attributes->get('account_id');
    }

    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tags' => 'present|array',
            'tags.*.id' => 'required|uuid',
            'tags.*.name' => 'required|string|max:60',
            'tags.*.color' => 'nullable|string|max:20',
            'custom_fields' => 'present|array',
            'custom_fields.*.id' => 'required|uuid',
            'custom_fields.*.name' => 'required|string|max:60',
            'custom_fields.*.field_type' => 'nullable|string|max:20',
            'custom_fields.*.options' => 'nullable|array',
            'custom_fields.*.options.*' => 'string|max:100',
            // `true` solo en la pasada inicial: dice qué haría, sin tocar nada.
            'dry_run' => 'nullable|boolean',
        ]);

        $accountId = $this->accountId($request);
        $dryRun = (bool) ($validated['dry_run'] ?? false);

        $report = [
            'tags' => $this->syncTags($accountId, $validated['tags'], $dryRun),
            'custom_fields' => $this->syncCustomFields($accountId, $validated['custom_fields'], $dryRun),
            'dry_run' => $dryRun,
        ];

        return response()->json($report);
    }

    /**
     * Dos etiquetas «iguales» se reconocen por el nombre normalizado —
     * minúsculas, sin acentos, sin espacios de más. «Interesado» e
     * «interesado » son la misma para un humano, y si el sync no lo viera
     * crearía una segunda y el equipo terminaría con las dos en la lista.
     */
    private function normalize(string $name): string
    {
        return Str::squish(Str::lower(Str::ascii($name)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $incoming
     * @return array<string, mixed>
     */
    private function syncTags(string $accountId, array $incoming, bool $dryRun): array
    {
        $report = ['linked' => [], 'created' => [], 'updated' => [], 'kept_in_use' => [], 'deleted' => []];

        $locals = Tag::forAccount($accountId)->get();
        $byExternal = $locals->whereNotNull('external_id')->keyBy('external_id');
        $byName = $locals->keyBy(fn (Tag $t) => $this->normalize($t->name));

        foreach ($incoming as $data) {
            $normalized = $this->normalize($data['name']);

            $tag = $byExternal[$data['id']] ?? null;

            // Sin correspondencia por id, se busca por nombre: es la pasada que
            // absorbe las etiquetas que ya existían de los dos lados. NO se
            // crea una segunda ni se borra ninguna — se ENLAZAN, así que las
            // asociaciones a contactos y las reglas de auto-tag sobreviven.
            if (! $tag) {
                $candidate = $byName[$normalized] ?? null;

                if ($candidate && $candidate->external_id === null) {
                    $tag = $candidate;
                    $report['linked'][] = ['name' => $tag->name, 'external_id' => $data['id']];
                }
            }

            $attrs = [
                'external_id' => $data['id'],
                'name' => $data['name'],
                'color' => $data['color'] ?? '#3b82f6',
            ];

            if (! $tag) {
                $report['created'][] = $data['name'];

                if (! $dryRun) {
                    Tag::create([...$attrs, 'account_id' => $accountId]);
                }

                continue;
            }

            if ($tag->name !== $attrs['name'] || $tag->color !== $attrs['color']) {
                $report['updated'][] = ['from' => $tag->name, 'to' => $attrs['name']];
            }

            if (! $dryRun) {
                $tag->update($attrs);
            }
        }

        $incomingIds = array_column($incoming, 'id');

        // Las que Komo ya no reporta: se borran solo si no están en uso.
        foreach ($locals->whereNotNull('external_id') as $tag) {
            if (in_array($tag->external_id, $incomingIds, true)) {
                continue;
            }

            $contactos = $tag->contacts()->count();
            $reglas = AutoTagRule::forAccount($accountId)->where('tag_id', $tag->id)->count();

            if ($contactos > 0 || $reglas > 0) {
                $report['kept_in_use'][] = [
                    'name' => $tag->name,
                    'contacts' => $contactos,
                    'auto_tag_rules' => $reglas,
                ];

                // Pasa a ser local: el catálogo de Komo ya no la tiene, pero
                // acá sigue etiquetando y sus reglas siguen corriendo.
                if (! $dryRun) {
                    $tag->update(['external_id' => null]);
                }

                continue;
            }

            $report['deleted'][] = $tag->name;

            if (! $dryRun) {
                $tag->delete();
            }
        }

        return $report;
    }

    /**
     * Solo llegan los campos de **contacto**: los `custom_fields` de este
     * proyecto son contact-only (`ContactCustomValue`), así que un campo de
     * lead o de empresa crearía acá una columna que nadie podría llenar nunca.
     * El recorte lo hace Komo, que es quien sabe de entidades.
     *
     * @param  array<int, array<string, mixed>>  $incoming
     * @return array<string, mixed>
     */
    private function syncCustomFields(string $accountId, array $incoming, bool $dryRun): array
    {
        $report = ['linked' => [], 'created' => [], 'updated' => [], 'kept_in_use' => [], 'deleted' => []];

        $locals = CustomField::forAccount($accountId)->get();
        $byExternal = $locals->whereNotNull('external_id')->keyBy('external_id');
        $byName = $locals->keyBy(fn (CustomField $f) => $this->normalize($f->field_name));

        foreach ($incoming as $data) {
            $field = $byExternal[$data['id']] ?? null;

            if (! $field) {
                $candidate = $byName[$this->normalize($data['name'])] ?? null;

                if ($candidate && $candidate->external_id === null) {
                    $field = $candidate;
                    $report['linked'][] = ['name' => $field->field_name, 'external_id' => $data['id']];
                }
            }

            $attrs = [
                'external_id' => $data['id'],
                'field_name' => $data['name'],
                'field_type' => $data['field_type'] ?? 'text',
                'field_options' => $data['options'] ?? null,
            ];

            if (! $field) {
                $report['created'][] = $data['name'];

                if (! $dryRun) {
                    CustomField::create([...$attrs, 'account_id' => $accountId]);
                }

                continue;
            }

            if ($field->field_name !== $attrs['field_name']) {
                $report['updated'][] = ['from' => $field->field_name, 'to' => $attrs['field_name']];
            }

            if (! $dryRun) {
                $field->update($attrs);
            }
        }

        $incomingIds = array_column($incoming, 'id');

        foreach ($locals->whereNotNull('external_id') as $field) {
            if (in_array($field->external_id, $incomingIds, true)) {
                continue;
            }

            // Un campo con valores cargados guarda datos que nadie más tiene:
            // borrarlo los tira. Se desvincula y queda como campo local.
            $valores = $field->values()->count();

            if ($valores > 0) {
                $report['kept_in_use'][] = ['name' => $field->field_name, 'values' => $valores];

                if (! $dryRun) {
                    $field->update(['external_id' => null]);
                }

                continue;
            }

            $report['deleted'][] = $field->field_name;

            if (! $dryRun) {
                $field->delete();
            }
        }

        return $report;
    }
}
