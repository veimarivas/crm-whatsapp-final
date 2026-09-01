<?php

namespace App\Http\Controllers;

use App\Models\Pipeline;
use App\Models\PipelineStage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Desde D5, las etapas de un pipeline sincronizado las administra el Komo.
 *
 * No es una restricción nueva de verdad: crear, renombrar, reordenar o borrar
 * una etapa acá **ya no sobrevivía** al próximo `pipelines/sync`, que reconcilia
 * contra el catálogo de allá. Lo único que cambia es que ahora se dice, en vez
 * de que el cambio desaparezca solo y sin explicación.
 */
class PipelineStageController extends Controller
{
    private function assertStructureEditable(Pipeline $pipeline): void
    {
        if ($pipeline->external_id !== null) {
            throw ValidationException::withMessages([
                'name' => 'Las etapas de este pipeline se administran desde el CRM de leads (Komo): un cambio hecho acá se perdería en la próxima sincronización.',
            ]);
        }
    }

    public function store(Request $request, Pipeline $pipeline): RedirectResponse
    {
        abort_if($pipeline->account_id !== $request->user()->account_id, 403);
        $this->assertStructureEditable($pipeline);

        $validated = $request->validate([
            'name' => 'required|string|max:60',
            'color' => 'nullable|string|max:20',
        ]);

        PipelineStage::create([
            'pipeline_id' => $pipeline->id,
            'name' => $validated['name'],
            'color' => $validated['color'] ?? '#3b82f6',
            'position' => ($pipeline->stages()->max('position') ?? -1) + 1,
        ]);

        return back()->with('success', 'Etapa creada.');
    }

    public function update(Request $request, PipelineStage $stage): RedirectResponse
    {
        $this->authorizeStage($request, $stage);

        $stage->update($request->validate([
            'name' => 'required|string|max:60',
            'color' => 'nullable|string|max:20',
        ]));

        return back()->with('success', 'Etapa actualizada.');
    }

    /** Sube o baja la etapa una posición (intercambia con la vecina). */
    public function move(Request $request, PipelineStage $stage): RedirectResponse
    {
        $this->authorizeStage($request, $stage);

        $direction = $request->validate(['direction' => 'required|in:up,down'])['direction'];

        $neighbor = $stage->pipeline->stages()
            ->where('position', $direction === 'up' ? '<' : '>', $stage->position)
            ->orderBy('position', $direction === 'up' ? 'desc' : 'asc')
            ->first();

        if ($neighbor) {
            [$a, $b] = [$stage->position, $neighbor->position];
            $stage->update(['position' => $b]);
            $neighbor->update(['position' => $a]);
        }

        return back();
    }

    public function destroy(Request $request, PipelineStage $stage): RedirectResponse
    {
        $this->authorizeStage($request, $stage);

        if ($stage->deals()->exists()) {
            return back()->withErrors(['stage' => 'Mueve o elimina los deals de la etapa antes de borrarla.']);
        }

        $stage->delete();

        return back()->with('success', 'Etapa eliminada.');
    }

    private function authorizeStage(Request $request, PipelineStage $stage): void
    {
        abort_if($stage->pipeline->account_id !== $request->user()->account_id, 403);
        $this->assertStructureEditable($stage->pipeline);
    }
}
