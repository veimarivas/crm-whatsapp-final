<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiFeedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recibe las correcciones que el equipo hace desde el Komo.
 *
 * La IA vive acá pero el agente que ve la respuesta mala está mirando el chat
 * del lead en el Komo. Sin este endpoint, capturar feedback allá es un
 * formulario que no alimenta nada.
 *
 * **No entra al conocimiento.** Solo se acumula: la cola de revisión en
 * `/settings/ai/feedback` es lo que decide qué se convierte en documento. Un
 * texto mal escrito por un agente apurado, enchufado directo, se lo repite la
 * IA a todos los clientes.
 */
class AiFeedbackApiController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rating' => 'required|in:up,down',
            'external_ref' => 'required|string|max:64',
            'conversation_id' => 'nullable|uuid',
            'ai_text' => 'nullable|string|max:5000',
            'question' => 'nullable|string|max:5000',
            'correction' => 'nullable|string|max:5000',
            'reporter' => 'nullable|string|max:120',
            'source' => 'nullable|string|max:20',
        ]);

        // Lo pone `AuthenticateApiKey`, igual que en el resto de la API v1.
        $accountId = $request->attributes->get('account_id');

        // El mismo mensaje se vota una vez: si el agente cambia de opinión se
        // actualiza la fila. Esto también hace el endpoint **idempotente**,
        // que es lo que permite que el job del Komo reintente sin miedo cuando
        // este servicio estuvo caído.
        $feedback = AiFeedback::updateOrCreate(
            ['account_id' => $accountId, 'external_ref' => $validated['external_ref']],
            [
                'conversation_id' => $validated['conversation_id'] ?? null,
                'source' => $validated['source'] ?? 'komo',
                'reporter' => $validated['reporter'] ?? null,
                'rating' => $validated['rating'],
                'ai_text' => $validated['ai_text'] ?? null,
                'question' => $validated['question'] ?? null,
                'correction' => $validated['correction'] ?? null,
                // Reabre la revisión si el voto cambió después de resolverse.
                'status' => AiFeedback::PENDING,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ],
        );

        return response()->json([
            'id' => $feedback->id,
            'status' => $feedback->status,
            // Se dice explícito para que nadie del otro lado asuma que la IA
            // ya aprendió algo: falta que un humano lo apruebe.
            'queued_for_review' => $feedback->rating === AiFeedback::DOWN,
        ], 201);
    }
}
