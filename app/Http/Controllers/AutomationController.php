<?php

namespace App\Http\Controllers;

use App\Models\Automation;
use App\Models\AutomationStep;
use App\Models\Tag;
use App\Services\Automations\Engine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AutomationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Automations/Index', [
            'automations' => Automation::forAccount($request->user()->account_id)
                ->withCount('steps')
                ->orderByDesc('updated_at')
                ->get(),
        ]);
    }

    public function edit(Request $request, ?Automation $automation = null): Response
    {
        if ($automation) {
            $this->authorizeAutomation($request, $automation);
        }

        return Inertia::render('Automations/Edit', [
            'automation' => $automation,
            'steps' => $automation ? $this->stepsAsTree($automation) : [],
            'tags' => Tag::forAccount($request->user()->account_id)->orderBy('name')->get(['id', 'name', 'color']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePayload($request);

        $automation = DB::transaction(function () use ($request, $validated) {
            $automation = Automation::create([
                'account_id' => $request->user()->account_id,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'trigger_type' => $validated['trigger_type'],
                'trigger_config' => $validated['trigger_config'] ?? [],
                'is_active' => false,
            ]);

            $this->saveSteps($automation, $validated['steps'] ?? []);

            return $automation;
        });

        return redirect()->route('automations.edit', $automation)
            ->with('success', 'Automatización creada (inactiva). Actívala cuando esté lista.');
    }

    public function update(Request $request, Automation $automation): RedirectResponse
    {
        $this->authorizeAutomation($request, $automation);
        $validated = $this->validatePayload($request);

        DB::transaction(function () use ($automation, $validated) {
            $automation->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'trigger_type' => $validated['trigger_type'],
                'trigger_config' => $validated['trigger_config'] ?? [],
            ]);

            // Re-crear los pasos es más simple y seguro que un diff:
            // las ejecuciones pendientes de pasos borrados se cancelan.
            $automation->pendingExecutions()->where('status', 'pending')->delete();
            $automation->steps()->whereNull('parent_step_id')->get()->each->delete();
            $this->saveSteps($automation, $validated['steps'] ?? []);
        });

        return back()->with('success', 'Automatización guardada.');
    }

    public function toggle(Request $request, Automation $automation): RedirectResponse
    {
        $this->authorizeAutomation($request, $automation);

        if (! $automation->is_active && $automation->steps()->count() === 0) {
            return back()->withErrors(['steps' => 'Añade al menos un paso antes de activar.']);
        }

        $automation->update(['is_active' => ! $automation->is_active]);

        return back()->with('success', $automation->is_active ? 'Automatización activada.' : 'Automatización desactivada.');
    }

    public function destroy(Request $request, Automation $automation): RedirectResponse
    {
        $this->authorizeAutomation($request, $automation);
        $automation->delete();

        return redirect()->route('automations.index')->with('success', 'Automatización eliminada.');
    }

    public function logs(Request $request, Automation $automation): Response
    {
        $this->authorizeAutomation($request, $automation);

        return Inertia::render('Automations/Logs', [
            'automation' => $automation->only(['id', 'name']),
            'logs' => $automation->logs()
                ->with('contact:id,name,phone')
                ->orderByDesc('created_at')
                ->paginate(30),
        ]);
    }

    /**
     * El builder envía los pasos como árbol anidado:
     * [{type, config, children_yes: [...], children_no: [...]}, ...]
     */
    private function saveSteps(Automation $automation, array $steps, ?string $parentId = null, ?string $branch = null): void
    {
        foreach (array_values($steps) as $position => $step) {
            $created = AutomationStep::create([
                'automation_id' => $automation->id,
                'parent_step_id' => $parentId,
                'branch' => $branch,
                'step_type' => $step['type'],
                'step_config' => $step['config'] ?? [],
                'position' => $position,
            ]);

            if ($step['type'] === 'condition') {
                $this->saveSteps($automation, $step['children_yes'] ?? [], $created->id, 'yes');
                $this->saveSteps($automation, $step['children_no'] ?? [], $created->id, 'no');
            }
        }
    }

    /** Reconstruye el árbol anidado para el builder. */
    private function stepsAsTree(Automation $automation): array
    {
        $all = $automation->steps()->get();

        $build = function (?string $parentId, ?string $branch) use (&$build, $all): array {
            return $all
                ->filter(fn ($s) => $s->parent_step_id === $parentId
                    && ($parentId === null || $s->branch === $branch))
                ->sortBy('position')
                ->values()
                ->map(fn ($s) => [
                    'type' => $s->step_type,
                    'config' => $s->step_config,
                    'children_yes' => $s->step_type === 'condition' ? $build($s->id, 'yes') : [],
                    'children_no' => $s->step_type === 'condition' ? $build($s->id, 'no') : [],
                ])
                ->all();
        };

        return $build(null, null);
    }

    private function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
            'trigger_type' => ['required', Rule::in(Engine::TRIGGERS)],
            'trigger_config' => 'nullable|array',
            'trigger_config.keywords' => 'required_if:trigger_type,keyword|array|min:1',
            'trigger_config.keywords.*' => 'string|max:60',
            'steps' => 'nullable|array|max:30',
        ]);

        $this->validateStepsTree($validated['steps'] ?? [], depth: 0);

        return $validated;
    }

    /** Valida tipos y anidamiento (condiciones hasta 3 niveles). */
    private function validateStepsTree(array $steps, int $depth): void
    {
        if ($depth > 3) {
            throw ValidationException::withMessages([
                'steps' => 'Máximo 3 niveles de condiciones anidadas.',
            ]);
        }

        foreach ($steps as $step) {
            if (! in_array($step['type'] ?? '', Engine::STEP_TYPES, true)) {
                throw ValidationException::withMessages([
                    'steps' => 'Tipo de paso inválido: '.($step['type'] ?? '?'),
                ]);
            }

            $this->validateStepsTree($step['children_yes'] ?? [], $depth + 1);
            $this->validateStepsTree($step['children_no'] ?? [], $depth + 1);
        }
    }

    private function authorizeAutomation(Request $request, Automation $automation): void
    {
        abort_if($automation->account_id !== $request->user()->account_id, 403);
    }
}
