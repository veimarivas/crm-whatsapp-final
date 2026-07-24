<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    /**
     * Exporta contactos a CSV. Aplica los mismos filtros que el index (tag_id,
     * q para búsqueda). El CSV se stream (chunked) para no cargar todos en RAM
     * en cuentas con miles de contactos.
     */
    public function exportCsv(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $accountId = $request->user()->account_id;

        $query = Contact::forAccount($accountId)
            ->with(['tags:id,name', 'company:id,name'])
            ->when($request->query('tag_id'), fn ($q, $tagId) => $q->whereHas('tags', fn ($t) => $t->where('tags.id', $tagId)))
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")
                ->orWhere('phone', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%")))
            ->orderBy('name');

        $filename = 'contactos-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            // BOM UTF-8 para que Excel lo abra bien
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['ID', 'Nombre', 'Teléfono', 'Email', 'Empresa', 'Tags', 'Creado'], ';');

            $query->chunk(500, function ($chunk) use ($out) {
                foreach ($chunk as $c) {
                    fputcsv($out, [
                        $c->id,
                        $c->name ?? '',
                        $c->phone ?? '',
                        $c->email ?? '',
                        $c->company?->name ?? $c->company ?? '',
                        $c->tags->pluck('name')->implode(', '),
                        $c->created_at?->format('Y-m-d H:i'),
                    ], ';');
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function index(Request $request): Response
    {
        $accountId = $request->user()->account_id;

        $contacts = Contact::forAccount($accountId)
            ->with(['tags:id,name,color', 'customValues:id,contact_id,custom_field_id,value'])
            ->when($request->query('q'), function ($query, $q) {
                $query->where(fn ($w) => $w
                    ->where('name', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('company', 'like', "%{$q}%"));
            })
            ->when($request->query('tag'), function ($query, $tagId) {
                $query->whereHas('tags', fn ($w) => $w->where('tags.id', $tagId));
            })
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Contacts/Index', [
            'contacts' => $contacts,
            'tags' => Tag::forAccount($accountId)->orderBy('name')->get(),
            'customFields' => CustomField::forAccount($accountId)->orderBy('created_at')->get(),
            'filters' => $request->only(['q', 'tag']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $accountId = $request->user()->account_id;
        $validated = $this->validateContact($request, $accountId);

        $contact = Contact::create([
            ...$validated['fields'],
            'account_id' => $accountId,
            'user_id' => $request->user()->id,
        ]);

        $this->syncRelations($contact, $validated);

        \App\Jobs\ProcessAutomationEventJob::dispatch('new_contact', $contact->id);
        app(\App\Services\Webhooks\Dispatcher::class)->dispatch($accountId, 'contact.created', [
            'contact' => $contact->only(['id', 'phone', 'name', 'email', 'company']),
        ]);

        return back()->with('success', 'Contacto creado.');
    }

    public function update(Request $request, Contact $contact): RedirectResponse
    {
        $this->authorizeContact($request, $contact);
        $validated = $this->validateContact($request, $contact->account_id, $contact);

        $contact->update($validated['fields']);
        $this->syncRelations($contact, $validated);

        return back()->with('success', 'Contacto actualizado.');
    }

    public function destroy(Request $request, Contact $contact): RedirectResponse
    {
        $this->authorizeContact($request, $contact);
        $contact->delete();

        return back()->with('success', 'Contacto eliminado.');
    }

    /**
     * Importación CSV. Espera cabecera con columnas (en cualquier orden):
     * phone (obligatoria), name, email, company. Deduplica por teléfono
     * normalizado — las filas repetidas se saltan, no fallan.
     */
    public function import(Request $request): RedirectResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:10240']);

        $accountId = $request->user()->account_id;
        $handle = fopen($request->file('file')->getRealPath(), 'r');

        $header = array_map(fn ($h) => strtolower(trim($h)), fgetcsv($handle) ?: []);
        $phoneCol = array_search('phone', $header);

        if ($phoneCol === false) {
            fclose($handle);

            return back()->withErrors(['file' => 'El CSV debe tener una columna "phone".']);
        }

        $existing = Contact::forAccount($accountId)->pluck('phone_normalized')->flip();
        $imported = $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($header, array_pad($row, count($header), null));
            $normalized = Contact::normalizePhone($data['phone'] ?? '');

            if (! $normalized || isset($existing[$normalized])) {
                $skipped++;

                continue;
            }

            Contact::create([
                'account_id' => $accountId,
                'user_id' => $request->user()->id,
                'phone' => trim($data['phone']),
                'name' => trim($data['name'] ?? '') ?: null,
                'email' => trim($data['email'] ?? '') ?: null,
                'company' => trim($data['company'] ?? '') ?: null,
            ]);

            $existing[$normalized] = true;
            $imported++;
        }

        fclose($handle);

        return back()->with('success', "Importados: {$imported}. Saltados (duplicados o sin teléfono): {$skipped}.");
    }

    private function validateContact(Request $request, string $accountId, ?Contact $ignore = null): array
    {
        $normalized = Contact::normalizePhone($request->input('phone', ''));

        $fields = $request->validate([
            'phone' => 'required|string|max:32',
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'company' => 'nullable|string|max:255',
        ]);

        // Unicidad por teléfono normalizado dentro de la cuenta. Se
        // comprueba a mano porque la columna comparada (phone_normalized)
        // no es la que viene en el request (phone).
        $duplicate = Contact::forAccount($accountId)
            ->where('phone_normalized', $normalized)
            ->when($ignore, fn ($q) => $q->where('id', '!=', $ignore->id))
            ->exists();

        if ($duplicate) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'phone' => 'Ya existe un contacto con ese teléfono.',
            ]);
        }

        return [
            'fields' => $fields,
            'tag_ids' => $request->validate(['tag_ids' => 'nullable|array', 'tag_ids.*' => 'uuid'])['tag_ids'] ?? null,
            'custom_values' => $request->validate([
                'custom_values' => 'nullable|array',
                'custom_values.*' => 'nullable|string|max:1000',
            ])['custom_values'] ?? null,
        ];
    }

    private function syncRelations(Contact $contact, array $validated): void
    {
        if ($validated['tag_ids'] !== null) {
            $valid = Tag::forAccount($contact->account_id)
                ->whereIn('id', $validated['tag_ids'])
                ->pluck('id');
            $contact->tags()->sync($valid);
        }

        if ($validated['custom_values'] !== null) {
            $fieldIds = CustomField::forAccount($contact->account_id)->pluck('id')->flip();

            foreach ($validated['custom_values'] as $fieldId => $value) {
                if (! isset($fieldIds[$fieldId])) {
                    continue;
                }

                if ($value === null || $value === '') {
                    $contact->customValues()->where('custom_field_id', $fieldId)->delete();
                } else {
                    $contact->customValues()->updateOrCreate(
                        ['custom_field_id' => $fieldId],
                        ['value' => $value],
                    );
                }
            }
        }
    }

    private function authorizeContact(Request $request, Contact $contact): void
    {
        abort_if($contact->account_id !== $request->user()->account_id, 403);
    }
}
