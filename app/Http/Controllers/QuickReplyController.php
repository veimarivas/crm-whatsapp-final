<?php

namespace App\Http\Controllers;

use App\Models\QuickReply;
use App\Models\User;
use App\Services\WhatsApp\SuggestedQuickReplies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QuickReplyController extends Controller
{
    public function index(Request $request): Response
    {
        $accountId = $request->user()->account_id;

        $replies = QuickReply::forAccount($accountId)
            ->with('user:id,name')
            ->orderBy('shortcut')
            ->get(['id', 'user_id', 'shortcut', 'content', 'created_at']);

        $existing = $replies->pluck('shortcut')->all();

        return Inertia::render('Settings/QuickReplies', [
            'replies' => $replies,
            // Solo se ofrecen las que faltan: el botón de cargar es idempotente
            // y no tiene sentido mostrar las que ya existen.
            'suggested' => collect(SuggestedQuickReplies::all())
                ->reject(fn (array $s) => in_array($s['shortcut'], $existing, true))
                ->values()
                ->all(),
            'isAdmin' => $request->user()->hasRoleAtLeast(User::ROLE_ADMIN),
        ]);
    }

    /**
     * Carga el pack de plantillas sugeridas como plantillas COMPARTIDAS del
     * equipo. Idempotente: las que ya existen (por shortcut) se saltan, así
     * que se puede volver a tocar el botón sin duplicar nada.
     */
    public function loadSuggested(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->hasRoleAtLeast(User::ROLE_ADMIN), 403,
            'Solo un administrador puede cargar las plantillas del equipo.');

        $existing = QuickReply::forAccount($user->account_id)->pluck('shortcut')->all();
        $created = 0;

        foreach (SuggestedQuickReplies::all() as $suggestion) {
            if (in_array($suggestion['shortcut'], $existing, true)) {
                continue;
            }

            QuickReply::create([
                'account_id' => $user->account_id,
                'user_id' => null, // compartida con todo el equipo
                'shortcut' => $suggestion['shortcut'],
                'content' => $suggestion['content'],
            ]);
            $created++;
        }

        return back()->with('success', $created > 0
            ? "Se cargaron {$created} plantillas. Revisá los campos entre [CORCHETES] antes de usarlas."
            : 'Ya tenías todas las plantillas sugeridas.');
    }

    /** JSON — usado por el Inbox para popular el dropdown de plantillas. */
    public function available(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(
            QuickReply::forAccount($user->account_id)
                // El agente ve las globales (user_id=null) + las suyas propias.
                ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $user->id))
                ->orderBy('shortcut')
                ->get(['id', 'shortcut', 'content', 'user_id'])
        );
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'shortcut' => 'required|string|max:40',
            'content' => 'required|string|max:4096',
            'shared' => 'boolean',
        ]);

        QuickReply::create([
            'account_id' => $request->user()->account_id,
            'user_id' => ($validated['shared'] ?? false) ? null : $request->user()->id,
            'shortcut' => trim(str_replace(' ', '_', $validated['shortcut'])),
            'content' => $validated['content'],
        ]);

        return back()->with('success', 'Plantilla creada.');
    }

    public function update(Request $request, QuickReply $quickReply): RedirectResponse
    {
        $this->authorize($request, $quickReply);

        $validated = $request->validate([
            'shortcut' => 'required|string|max:40',
            'content' => 'required|string|max:4096',
            'shared' => 'boolean',
        ]);

        $quickReply->update([
            'user_id' => ($validated['shared'] ?? false) ? null : $request->user()->id,
            'shortcut' => trim(str_replace(' ', '_', $validated['shortcut'])),
            'content' => $validated['content'],
        ]);

        return back()->with('success', 'Plantilla actualizada.');
    }

    public function destroy(Request $request, QuickReply $quickReply): RedirectResponse
    {
        $this->authorize($request, $quickReply);
        $quickReply->delete();

        return back()->with('success', 'Plantilla eliminada.');
    }

    /** Solo el dueño de la plantilla (o el admin para las globales) puede editarla. */
    private function authorize(Request $request, QuickReply $quickReply): void
    {
        abort_if($quickReply->account_id !== $request->user()->account_id, 403);

        $user = $request->user();
        $isAdmin = $user->hasRoleAtLeast(User::ROLE_ADMIN);

        if ($quickReply->user_id === null) {
            abort_unless($isAdmin, 403, 'Solo admin puede editar plantillas compartidas.');
        } else {
            abort_unless($quickReply->user_id === $user->id || $isAdmin, 403);
        }
    }
}
