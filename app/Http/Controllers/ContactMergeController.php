<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\Deal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Detecta y fusiona contactos duplicados. Un cliente puede aparecer 2 veces
 * si escribió desde números distintos o si el importer creó dos entradas
 * (nombre + email similares). Este flujo detecta esos casos y permite unir
 * las conversaciones, tags, notas y deals de ambos en un solo contacto.
 */
class ContactMergeController extends Controller
{
    public function index(Request $request): Response
    {
        $accountId = $request->user()->account_id;

        // Estrategia: agrupar por email (si tienen) y por nombre normalizado.
        // Muestra hasta 100 grupos con >1 contacto (los "sospechosos").
        $byEmail = Contact::forAccount($accountId)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->selectRaw('LOWER(email) as key, COUNT(*) as cnt, GROUP_CONCAT(id) as ids')
            ->groupBy('key')
            ->having('cnt', '>', 1)
            ->limit(50)
            ->get();

        $byName = Contact::forAccount($accountId)
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->selectRaw('LOWER(TRIM(name)) as key, COUNT(*) as cnt, GROUP_CONCAT(id) as ids')
            ->groupBy('key')
            ->having('cnt', '>', 1)
            ->limit(50)
            ->get();

        // Combinar ambos criterios y traer los contactos completos
        $groups = collect();
        foreach ($byEmail as $g) {
            $groups->push(['reason' => 'email', 'label' => $g->key, 'ids' => explode(',', $g->ids)]);
        }
        foreach ($byName as $g) {
            // Evitar duplicar si ya está por email (mismo grupo de IDs)
            $ids = explode(',', $g->ids);
            $alreadyExists = $groups->contains(fn ($x) => count(array_intersect($x['ids'], $ids)) === count($ids));
            if (! $alreadyExists) {
                $groups->push(['reason' => 'name', 'label' => $g->key, 'ids' => $ids]);
            }
        }

        // Cargar contactos con conteos útiles
        $contactsById = Contact::forAccount($accountId)
            ->whereIn('id', $groups->pluck('ids')->flatten()->unique()->all())
            ->withCount(['tags', 'notes'])
            ->get(['id', 'name', 'phone', 'email', 'company', 'created_at'])
            ->keyBy('id');

        $groupsData = $groups->map(function ($g) use ($contactsById) {
            $contacts = collect($g['ids'])->map(fn ($id) => $contactsById[$id] ?? null)->filter()->values();

            return [
                'reason' => $g['reason'],
                'label' => $g['label'],
                'contacts' => $contacts,
            ];
        })->filter(fn ($g) => $g['contacts']->count() >= 2)->values();

        return Inertia::render('Contacts/Duplicates', [
            'groups' => $groupsData,
        ]);
    }

    /**
     * Fusiona los contactos dados en el primero (primary). Mueve conversaciones,
     * tags y notas de los "duplicados" al primary, luego los borra. Todo en
     * transacción para no dejar datos huérfanos si algo falla.
     */
    public function merge(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'primary_id' => 'required|uuid',
            'duplicate_ids' => 'required|array|min:1',
            'duplicate_ids.*' => 'uuid',
        ]);

        $accountId = $request->user()->account_id;

        $primary = Contact::forAccount($accountId)->findOrFail($validated['primary_id']);
        $duplicates = Contact::forAccount($accountId)->whereIn('id', $validated['duplicate_ids'])->get();

        if ($duplicates->pluck('id')->contains($primary->id)) {
            return back()->withErrors(['merge' => 'El contacto principal no puede estar en la lista de duplicados.']);
        }

        DB::transaction(function () use ($primary, $duplicates) {
            foreach ($duplicates as $dup) {
                // Completar campos vacíos en el primary con los del duplicado
                $primary->fill(array_filter([
                    'name' => $primary->name ?: $dup->name,
                    'email' => $primary->email ?: $dup->email,
                    'company' => $primary->company ?: $dup->company,
                ]));

                // Reasignar conversaciones al primary
                $dup->conversations()->update(['contact_id' => $primary->id]);

                // Copiar tags (sin duplicar)
                foreach ($dup->tags as $tag) {
                    $primary->tags()->syncWithoutDetaching([$tag->id]);
                }

                // Reasignar notas
                ContactNote::where('contact_id', $dup->id)->update(['contact_id' => $primary->id]);

                // Reasignar deals (si existen)
                if (class_exists(Deal::class)) {
                    Deal::where('contact_id', $dup->id)->update(['contact_id' => $primary->id]);
                }

                // Borrar duplicado
                $dup->delete();
            }
            $primary->save();
        });

        return back()->with('success', 'Contactos fusionados. Todos los datos se movieron al principal.');
    }
}
