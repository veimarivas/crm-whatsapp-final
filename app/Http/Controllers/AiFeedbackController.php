<?php

namespace App\Http\Controllers;

use App\Models\AiFeedback;
use App\Models\AiKnowledgeDocument;
use App\Services\Ai\Chunker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cola de revisión de las correcciones a la IA.
 *
 * **Este paso es obligatorio, no un lujo.** Enchufar las correcciones directo
 * a la base de conocimiento la envenena: un agente apurado escribe algo mal —o
 * algo cierto para un caso puntual y falso en general— y la IA se lo repite a
 * todos los clientes. Un humano decide qué se convierte en conocimiento.
 *
 * Lo que se aprueba entra como documento **fijo** (`is_pinned`): una corrección
 * existe justamente porque el retrieval no encontró la respuesta correcta, así
 * que dejarla sujeta al mismo retrieval sería repetir el error.
 */
class AiFeedbackController extends Controller
{
    public function index(Request $request): Response
    {
        $accountId = $request->user()->account_id;

        $feedback = AiFeedback::forAccount($accountId)
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 ELSE 1 END")
            ->latest()
            ->limit(200)
            ->get();

        $down = AiFeedback::forAccount($accountId)->where('rating', AiFeedback::DOWN)->count();
        $up = AiFeedback::forAccount($accountId)->where('rating', AiFeedback::UP)->count();

        return Inertia::render('Settings/AiFeedback', [
            'pending' => $feedback->where('rating', AiFeedback::DOWN)
                ->where('status', AiFeedback::PENDING)->values(),
            'resolved' => $feedback->where('status', '!=', AiFeedback::PENDING)->values(),
            'stats' => [
                'up' => $up,
                'down' => $down,
                // La tasa de rechazo es el número que dice si el ciclo mejora
                // algo o solo genera trabajo. Sin base, `null` y no 0%.
                'downRate' => ($up + $down) > 0 ? round($down / ($up + $down) * 100, 1) : null,
                'pending' => $feedback->where('rating', AiFeedback::DOWN)->where('status', AiFeedback::PENDING)->count(),
            ],
        ]);
    }

    /**
     * Aprueba una corrección y la convierte en conocimiento.
     *
     * El texto que se indexa es el que el revisor tiene en pantalla y puede
     * editar, no el que mandó el agente: revisar sin poder corregir la
     * corrección sería aprobar a ciegas.
     */
    public function apply(Request $request, AiFeedback $aiFeedback, Chunker $chunker): RedirectResponse
    {
        $this->authorizeFeedback($request, $aiFeedback);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string|max:20000',
        ]);

        if ($aiFeedback->status === AiFeedback::APPLIED) {
            throw ValidationException::withMessages(['content' => 'Esta corrección ya se aplicó.']);
        }

        $document = AiKnowledgeDocument::create([
            'account_id' => $aiFeedback->account_id,
            'created_by' => $request->user()->id,
            'title' => $validated['title'],
            'content' => $validated['content'],
            // Fijo: la corrección existe porque el retrieval no encontró la
            // respuesta correcta. Dejarla sujeta al retrieval repetiría el error.
            'is_pinned' => true,
        ]);

        $chunks = $chunker->reindex($document);

        $aiFeedback->update([
            'status' => AiFeedback::APPLIED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'document_id' => $document->id,
        ]);

        return back()->with('success', "Corrección aplicada ({$chunks} fragmentos indexados).");
    }

    /** Descarta sin aplicar: no toda queja es un hueco de conocimiento. */
    public function dismiss(Request $request, AiFeedback $aiFeedback): RedirectResponse
    {
        $this->authorizeFeedback($request, $aiFeedback);

        $aiFeedback->update([
            'status' => AiFeedback::DISMISSED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('success', 'Corrección descartada.');
    }

    private function authorizeFeedback(Request $request, AiFeedback $feedback): void
    {
        abort_if($feedback->account_id !== $request->user()->account_id, 403);
    }
}
