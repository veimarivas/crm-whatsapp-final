<?php

namespace App\Http\Controllers;

use App\Models\Automation;
use App\Models\AutomationStep;
use App\Models\Contact;
use App\Models\Tag;
use App\Services\Academico\Plantillas;
use App\Services\Automations\Engine;
use App\Services\Automations\Recipes;
use App\Services\Automations\Simulator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AutomationController extends Controller
{
    public function index(Request $request): Response
    {
        $automations = Automation::forAccount($request->user()->account_id)
            ->with('steps:id,automation_id,parent_step_id,branch,step_type,step_config,position')
            ->withCount('steps')
            ->orderByDesc('updated_at')
            ->get();

        return Inertia::render('Automations/Index', [
            // El resumen en lenguaje natural se arma en el front a partir
            // de los pasos raíz: es lo que deja entender una automatización
            // sin abrirla.
            'automations' => $automations->map(fn (Automation $a) => [
                ...$a->only(['id', 'name', 'description', 'trigger_type', 'trigger_config', 'is_active', 'execution_count', 'last_executed_at', 'steps_count']),
                'root_steps' => $a->steps
                    ->whereNull('parent_step_id')
                    ->sortBy('position')
                    ->values()
                    ->map(fn (AutomationStep $s) => ['type' => $s->step_type, 'config' => $s->step_config])
                    ->all(),
            ]),
            'recipes' => Recipes::gallery(),
            'oferta' => app(Plantillas::class)->resumen(),
        ]);
    }

    public function edit(Request $request, ?Automation $automation = null): Response
    {
        if ($automation) {
            $this->authorizeAutomation($request, $automation);
        }

        // ?recipe=slug precarga el formulario con una automatización ya
        // armada; sigue siendo un borrador sin guardar hasta que el
        // usuario le dé a crear.
        $recipe = ! $automation && $request->filled('recipe')
            ? Recipes::find($request->query('recipe'))
            : null;

        return Inertia::render('Automations/Edit', [
            'automation' => $automation ?? ($recipe ? [
                'name' => $recipe['automation']['name'],
                'description' => $recipe['automation']['description'],
                'trigger_type' => $recipe['automation']['trigger_type'],
                'trigger_config' => $recipe['automation']['trigger_config'],
            ] : null),
            'isDraft' => (bool) $recipe,
            'recipeTitle' => $recipe['title'] ?? null,
            'steps' => $automation
                ? $this->stepsAsTree($automation)
                : $this->normalizeTree($recipe['automation']['steps'] ?? []),
            'tags' => Tag::forAccount($request->user()->account_id)->orderBy('name')->get(['id', 'name', 'color']),
            'sampleContacts' => Contact::forAccount($request->user()->account_id)
                ->orderByDesc('updated_at')
                ->limit(30)
                ->get(['id', 'name', 'phone']),
        ]);
    }

    /**
     * Prueba en seco: recorre el árbol que está en pantalla y devuelve
     * qué pasaría. No envía mensajes, no etiqueta, no llama webhooks —
     * se puede probar sin guardar y sin activar.
     */
    public function simulate(Request $request, Simulator $simulator): JsonResponse
    {
        // Validación manual: esta ruta vive en el grupo web, donde el
        // handler convierte los fallos en redirect (`shouldRenderJsonWhen`
        // solo cubre api/*). El panel de prueba consume JSON, así que el
        // 422 se arma acá.
        $validator = Validator::make($request->all(), [
            'trigger_type' => ['required', Rule::in(Engine::TRIGGERS)],
            'trigger_config' => 'nullable|array',
            'steps' => 'nullable|array|max:30',
            'message_text' => 'nullable|string|max:2000',
            'contact_id' => 'nullable|uuid',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $validated = $validator->validated();

        try {
            $this->validateStepsTree($validated['steps'] ?? [], depth: 0);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->validator->errors()->first()], 422);
        }

        $contact = ! empty($validated['contact_id'])
            ? Contact::forAccount($request->user()->account_id)->find($validated['contact_id'])
            : null;

        return response()->json($simulator->run(
            $validated['trigger_type'],
            $validated['trigger_config'] ?? [],
            $validated['steps'] ?? [],
            $validated['message_text'] ?? null,
            $contact,
        ));
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
                // `position` es el ORDEN dentro de la rama; `position_x/y` es
                // dónde quedó la tarjeta en el lienzo. Mezclarlos haría que
                // mover una tarjeta cambie el orden de ejecución.
                'position' => $position,
                'position_x' => isset($step['x']) ? (int) $step['x'] : null,
                'position_y' => isset($step['y']) ? (int) $step['y'] : null,
            ]);

            if ($step['type'] === 'condition') {
                $this->saveSteps($automation, $step['children_yes'] ?? [], $created->id, 'yes');
                $this->saveSteps($automation, $step['children_no'] ?? [], $created->id, 'no');
            }
        }
    }

    /** Las recetas se escriben sin ramas vacías; el builder las espera siempre. */
    private function normalizeTree(array $steps): array
    {
        return array_map(fn (array $step) => [
            'type' => $step['type'],
            'config' => $step['config'] ?? [],
            'x' => $step['x'] ?? null,
            'y' => $step['y'] ?? null,
            'children_yes' => $this->normalizeTree($step['children_yes'] ?? []),
            'children_no' => $this->normalizeTree($step['children_no'] ?? []),
        ], $steps);
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
                    // `null` cuando el paso nunca se movió: el lienzo lo ubica
                    // con el layout automático del árbol, así que lo que ya
                    // existía no se amontona en el origen.
                    'x' => $s->position_x,
                    'y' => $s->position_y,
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
