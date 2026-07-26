<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Services\WhatsApp\ServiceWindow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PipelineController extends Controller
{
    /** Etapas sembradas al crear un pipeline (editables después). */
    private const DEFAULT_STAGES = [
        ['name' => 'Nuevo', 'color' => '#3b82f6'],
        ['name' => 'Contactado', 'color' => '#8b5cf6'],
        ['name' => 'Propuesta', 'color' => '#f59e0b'],
        ['name' => 'Negociación', 'color' => '#f97316'],
        ['name' => 'Cierre', 'color' => '#10b981'],
    ];

    public function index(Request $request): Response
    {
        $accountId = $request->user()->account_id;

        $pipelines = Pipeline::forAccount($accountId)
            ->with('stages')
            ->orderBy('created_at')
            ->get();

        $selectedId = $request->query('pipeline', $pipelines->first()?->id);
        $selected = $pipelines->firstWhere('id', $selectedId) ?? $pipelines->first();

        $deals = $selected
            ? $selected->deals()
                ->with(['contact:id,name,phone', 'assignee:id,name'])
                ->orderByDesc('created_at')
                ->get()
            : collect();

        // Ventana de servicio del contacto de cada deal: en el pipeline es lo
        // que dice si todavía se le puede escribir sin costo para cerrarlo.
        $windows = app(ServiceWindow::class)
            ->forContacts($deals->pluck('contact_id')->filter()->unique()->values()->all());

        $deals->each(fn ($d) => $d->setAttribute('service_window', $windows[$d->contact_id] ?? null));

        return Inertia::render('Pipelines/Index', [
            'pipelines' => $pipelines->map(fn ($p) => ['id' => $p->id, 'name' => $p->name]),
            'pipeline' => $selected ? [
                'id' => $selected->id,
                'name' => $selected->name,
                'stages' => $selected->stages,
            ] : null,
            'deals' => $deals,
            'members' => $request->user()->account->members()->get(['id', 'name']),
            'contacts' => Contact::forAccount($accountId)
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'name', 'phone']),
            'currency' => $request->user()->account->default_currency,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['name' => 'required|string|max:100']);

        $pipeline = DB::transaction(function () use ($request, $validated) {
            $pipeline = Pipeline::create([
                'account_id' => $request->user()->account_id,
                'name' => $validated['name'],
            ]);

            foreach (self::DEFAULT_STAGES as $position => $stage) {
                PipelineStage::create([
                    'pipeline_id' => $pipeline->id,
                    'name' => $stage['name'],
                    'color' => $stage['color'],
                    'position' => $position,
                ]);
            }

            return $pipeline;
        });

        return redirect()->route('pipelines.index', ['pipeline' => $pipeline->id])
            ->with('success', 'Pipeline creado con etapas por defecto.');
    }

    public function update(Request $request, Pipeline $pipeline): RedirectResponse
    {
        $this->authorizePipeline($request, $pipeline);

        $pipeline->update($request->validate(['name' => 'required|string|max:100']));

        return back()->with('success', 'Pipeline renombrado.');
    }

    public function destroy(Request $request, Pipeline $pipeline): RedirectResponse
    {
        $this->authorizePipeline($request, $pipeline);

        $pipeline->delete(); // etapas y deals caen por FK cascade

        return redirect()->route('pipelines.index')->with('success', 'Pipeline eliminado.');
    }

    private function authorizePipeline(Request $request, Pipeline $pipeline): void
    {
        abort_if($pipeline->account_id !== $request->user()->account_id, 403);
    }
}
