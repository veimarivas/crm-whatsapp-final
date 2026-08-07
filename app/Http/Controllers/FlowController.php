<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\Tag;
use App\Services\Flows\Recipes;
use App\Services\Flows\Runner;
use App\Services\Flows\Simulator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FlowController extends Controller
{
    public function index(Request $request): Response
    {
        $flows = Flow::forAccount($request->user()->account_id)
            ->with('nodes:id,flow_id,node_key,node_type,config')
            ->withCount(['nodes', 'runs'])
            ->orderByDesc('updated_at')
            ->get();

        return Inertia::render('Flows/Index', [
            // `entry_text` es el primer mensaje que vería el cliente: deja
            // entender de qué va el chatbot sin abrirlo.
            'flows' => $flows->map(fn (Flow $flow) => [
                ...$flow->only(['id', 'name', 'status', 'trigger_type', 'trigger_config', 'entry_node_id', 'nodes_count', 'runs_count', 'last_executed_at']),
                'entry_text' => $this->entryText($flow),
                'entry_missing' => ! $flow->nodes->contains('node_key', $flow->entry_node_id),
            ]),
            'recipes' => Recipes::gallery(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'recipe' => ['nullable', 'string', Rule::in(array_column(Recipes::all(), 'slug'))],
        ]);

        $recipe = Recipes::find($validated['recipe'] ?? Recipes::DEFAULT) ?? Recipes::find(Recipes::DEFAULT);

        $flow = DB::transaction(function () use ($request, $validated, $recipe) {
            $flow = Flow::create([
                'account_id' => $request->user()->account_id,
                'name' => $validated['name'],
                'status' => 'draft',
                'trigger_type' => $recipe['trigger_type'],
                'trigger_config' => $recipe['trigger_config'],
                'entry_node_id' => $recipe['entry_node_id'],
                'fallback_policy' => Flow::DEFAULT_FALLBACK_POLICY,
            ]);

            foreach ($recipe['nodes'] as $node) {
                FlowNode::create(['flow_id' => $flow->id, ...$node]);
            }

            return $flow;
        });

        return redirect()->route('flows.edit', $flow)
            ->with('success', "Flow creado con la plantilla «{$recipe['title']}». Pruébalo en el chat de la derecha y actívalo cuando esté listo.");
    }

    /**
     * Conversa con el grafo que está en pantalla sin enviar nada.
     *
     * Sin estado: el front manda todas las respuestas del "cliente" y acá
     * se reproduce la conversación entera. Así se prueba antes de guardar
     * y sin crear `flow_runs`.
     */
    public function simulate(Request $request, Simulator $simulator): JsonResponse
    {
        // Igual que en automatizaciones: esta ruta vive en el grupo web,
        // donde un validate() fallido devuelve 302 y axios recibiría HTML.
        $validator = Validator::make($request->all(), [
            'entry_node_id' => 'required|string|max:60',
            'fallback_policy' => 'nullable|array',
            'nodes' => 'required|array|min:1|max:50',
            'nodes.*.node_key' => 'required|string|max:60',
            'nodes.*.node_type' => ['required', Rule::in(Runner::NODE_TYPES)],
            'nodes.*.config' => 'nullable|array',
            'replies' => 'nullable|array|max:40',
            'replies.*' => 'nullable|string|max:500',
            'contact_id' => 'nullable|uuid',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $validated = $validator->validated();

        $contact = ! empty($validated['contact_id'])
            ? Contact::forAccount($request->user()->account_id)->find($validated['contact_id'])
            : null;

        return response()->json($simulator->run(
            [
                'entry_node_id' => $validated['entry_node_id'],
                'fallback_policy' => $validated['fallback_policy'] ?? [],
            ],
            $validated['nodes'],
            $validated['replies'] ?? [],
            $contact,
        ));
    }

    /** Texto del nodo de entrada, sea del tipo que sea. */
    private function entryText(Flow $flow): ?string
    {
        $entry = $flow->nodes->firstWhere('node_key', $flow->entry_node_id);

        if (! $entry) {
            return null;
        }

        return $entry->config['text'] ?? $entry->config['message'] ?? null;
    }

    public function edit(Request $request, Flow $flow): Response
    {
        $this->authorizeFlow($request, $flow);

        return Inertia::render('Flows/Edit', [
            'flow' => $flow,
            'nodes' => $flow->nodes()->orderBy('created_at')->get()
                ->map(fn ($n) => ['node_key' => $n->node_key, 'node_type' => $n->node_type, 'config' => $n->config]),
            'tags' => Tag::forAccount($request->user()->account_id)->orderBy('name')->get(['id', 'name']),
            'sampleContacts' => Contact::forAccount($request->user()->account_id)
                ->orderByDesc('updated_at')
                ->limit(30)
                ->get(['id', 'name', 'phone']),
        ]);
    }

    public function update(Request $request, Flow $flow): RedirectResponse
    {
        $this->authorizeFlow($request, $flow);

        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'trigger_type' => ['required', Rule::in(['keyword', 'first_inbound_message', 'manual'])],
            'trigger_config' => 'nullable|array',
            'trigger_config.keywords' => 'required_if:trigger_type,keyword|array|min:1',
            'trigger_config.keywords.*' => 'string|max:60',
            'entry_node_id' => 'required|string|max:60',
            'fallback_policy' => 'nullable|array',
            'fallback_policy.max_reprompts' => 'nullable|integer|between:0,5',
            'fallback_policy.on_timeout_hours' => 'nullable|integer|between:1,168',
            'fallback_policy.on_exhaust' => ['nullable', Rule::in(['handoff', 'end'])],
            'nodes' => 'required|array|min:1|max:50',
            'nodes.*.node_key' => 'required|string|max:60|regex:/^[a-z0-9_]+$/',
            'nodes.*.node_type' => ['required', Rule::in(Runner::NODE_TYPES)],
            'nodes.*.config' => 'nullable|array',
        ]);

        $this->validateGraph($validated);

        DB::transaction(function () use ($flow, $validated) {
            $flow->update([
                'name' => $validated['name'],
                'trigger_type' => $validated['trigger_type'],
                'trigger_config' => $validated['trigger_config'] ?? [],
                'entry_node_id' => $validated['entry_node_id'],
                'fallback_policy' => array_merge(
                    Flow::DEFAULT_FALLBACK_POLICY,
                    $validated['fallback_policy'] ?? [],
                ),
            ]);

            $flow->nodes()->delete();

            foreach ($validated['nodes'] as $node) {
                FlowNode::create([
                    'flow_id' => $flow->id,
                    'node_key' => $node['node_key'],
                    'node_type' => $node['node_type'],
                    'config' => $node['config'] ?? [],
                ]);
            }
        });

        return back()->with('success', 'Flow guardado.');
    }

    public function toggle(Request $request, Flow $flow): RedirectResponse
    {
        $this->authorizeFlow($request, $flow);

        if ($flow->status === 'active') {
            $flow->update(['status' => 'draft']);

            return back()->with('success', 'Flow desactivado.');
        }

        if (! $flow->entry_node_id || ! $flow->nodes()->where('node_key', $flow->entry_node_id)->exists()) {
            return back()->withErrors(['flow' => 'El nodo de entrada no existe. Revisa el flow antes de activar.']);
        }

        $flow->update(['status' => 'active']);

        return back()->with('success', 'Flow activado.');
    }

    public function destroy(Request $request, Flow $flow): RedirectResponse
    {
        $this->authorizeFlow($request, $flow);
        $flow->delete();

        return redirect()->route('flows.index')->with('success', 'Flow eliminado.');
    }

    public function runs(Request $request, Flow $flow): Response
    {
        $this->authorizeFlow($request, $flow);

        return Inertia::render('Flows/Runs', [
            'flow' => $flow->only(['id', 'name']),
            'runs' => $flow->runs()
                ->with('contact:id,name,phone')
                ->orderByDesc('started_at')
                ->paginate(30),
        ]);
    }

    /** Los edges (next/next_yes/next_no/botones) deben apuntar a nodos existentes. */
    private function validateGraph(array $validated): void
    {
        $keys = collect($validated['nodes'])->pluck('node_key');

        if ($keys->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'nodes' => 'Claves de nodo duplicadas: '.$keys->duplicates()->join(', '),
            ]);
        }

        $known = $keys->flip();

        $assert = function (?string $target, string $from) use ($known) {
            if ($target !== null && $target !== '' && ! isset($known[$target])) {
                throw ValidationException::withMessages([
                    'nodes' => "El nodo «{$from}» apunta a «{$target}», que no existe.",
                ]);
            }
        };

        if (! isset($known[$validated['entry_node_id']])) {
            throw ValidationException::withMessages([
                'entry_node_id' => 'El nodo de entrada no existe en el grafo.',
            ]);
        }

        foreach ($validated['nodes'] as $node) {
            $config = $node['config'] ?? [];
            $key = $node['node_key'];

            $assert($config['next'] ?? null, $key);
            $assert($config['next_yes'] ?? null, $key);
            $assert($config['next_no'] ?? null, $key);

            foreach ([...$config['buttons'] ?? [], ...$config['rows'] ?? []] as $option) {
                $assert($option['next'] ?? null, $key);
            }
        }
    }

    private function authorizeFlow(Request $request, Flow $flow): void
    {
        abort_if($flow->account_id !== $request->user()->account_id, 403);
    }
}
