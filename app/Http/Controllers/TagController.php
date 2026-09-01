<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Desde D2 el catálogo de etiquetas lo administra el Komo.
 *
 * Crear una etiqueta LOCAL acá sigue permitido —un agente que necesita marcar
 * algo en el momento no puede quedar bloqueado— pero **renombrar o borrar una
 * etiqueta que administra Komo, no**: el próximo sync la pisaría igual, así
 * que la pantalla estaría prometiendo un cambio que no sobrevive. Es preferible
 * decir dónde se hace.
 */
class TagController extends Controller
{
    private function assertEditable(Tag $tag): void
    {
        if ($tag->isManagedByKomo()) {
            throw ValidationException::withMessages([
                'name' => 'Esta etiqueta se administra desde el CRM de leads (Komo): un cambio hecho acá se perdería en la próxima sincronización.',
            ]);
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:60',
            'color' => 'nullable|string|max:20',
        ]);

        Tag::create([
            'account_id' => $request->user()->account_id,
            'name' => $validated['name'],
            'color' => $validated['color'] ?? '#3b82f6',
        ]);

        return back()->with('success', 'Etiqueta creada.');
    }

    public function update(Request $request, Tag $tag): RedirectResponse
    {
        abort_if($tag->account_id !== $request->user()->account_id, 403);
        $this->assertEditable($tag);

        $tag->update($request->validate([
            'name' => 'required|string|max:60',
            'color' => 'nullable|string|max:20',
        ]));

        return back()->with('success', 'Etiqueta actualizada.');
    }

    public function destroy(Request $request, Tag $tag): RedirectResponse
    {
        abort_if($tag->account_id !== $request->user()->account_id, 403);
        $this->assertEditable($tag);

        $tag->delete();

        return back()->with('success', 'Etiqueta eliminada.');
    }
}
