<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * API v1 — Team + Assignment.
 *
 * Estos endpoints los consume el Komo (u otro CRM externo) para:
 *  - Provisionar usuarios agente en el wacrm al invitarlos allá.
 *  - Reasignar la conversación de WhatsApp cuando cambia el responsable
 *    del lead en el sistema externo.
 */
class TeamApiController extends Controller
{
    private function accountId(Request $request): string
    {
        return $request->attributes->get('account_id');
    }

    /**
     * Provisión idempotente de un usuario por email.
     *
     * - Si no existe: lo crea con el rol pedido dentro de esta cuenta.
     * - Si ya existe en esta cuenta: actualiza el rol (no toca password).
     * - Si existe en OTRA cuenta: 409 (no robamos users entre tenants).
     */
    public function provision(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:180',
            'password' => 'nullable|string|min:8|max:100',
            'role' => ['nullable', Rule::in([User::ROLE_ADMIN, User::ROLE_AGENT, User::ROLE_VIEWER])],
        ]);

        $accountId = $this->accountId($request);
        $role = $validated['role'] ?? User::ROLE_AGENT;

        $existing = User::where('email', $validated['email'])->first();

        if ($existing && $existing->account_id !== $accountId) {
            return response()->json([
                'message' => 'El email ya pertenece a otra cuenta en el wacrm.',
                'code' => 'email_in_other_account',
            ], 409);
        }

        if ($existing) {
            $existing->update(['name' => $validated['name'], 'account_role' => $role]);

            return response()->json(['user' => $existing->only(['id', 'name', 'email', 'account_role']), 'created' => false]);
        }

        // Sin password: generamos una random (el user tendrá que resetear)
        $password = $validated['password'] ?? Str::random(24);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => bcrypt($password),
            'account_id' => $accountId,
            'account_role' => $role,
        ]);

        return response()->json(['user' => $user->only(['id', 'name', 'email', 'account_role']), 'created' => true], 201);
    }

    /**
     * Reasigna una conversación por email del agente (o desasigna con null).
     * El email debe corresponder a un user del mismo account que la API key.
     */
    public function assignConversation(Request $request, string $conversationId): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'nullable|email|max:180',
        ]);

        $accountId = $this->accountId($request);

        $conversation = Conversation::where('account_id', $accountId)->findOrFail($conversationId);

        $agentId = null;
        if (! empty($validated['email'])) {
            $agent = User::where('account_id', $accountId)->where('email', $validated['email'])->first();

            if (! $agent) {
                return response()->json([
                    'message' => 'No hay un usuario con ese email en esta cuenta. Provisionalo primero.',
                    'code' => 'user_not_found',
                ], 422);
            }
            $agentId = $agent->id;
        }

        $conversation->update(['assigned_agent_id' => $agentId]);

        // El deal de la conversación espeja la asignación: la columna de
        // /pipelines y el responsable en Komo deben coincidir.
        Deal::where('conversation_id', $conversation->id)->update(['assigned_to' => $agentId]);

        return response()->json(['ok' => true, 'assigned_agent_id' => $agentId]);
    }

    /** Cambia el modo IA/Humano de una conversación desde Komo. */
    public function setAiMode(Request $request, string $conversationId): JsonResponse
    {
        $validated = $request->validate(['ai_enabled' => 'required|boolean']);

        $accountId = $this->accountId($request);
        $conversation = Conversation::where('account_id', $accountId)->findOrFail($conversationId);

        $conversation->setAiEnabled($validated['ai_enabled'], 'Apagada desde Komo');

        if ($validated['ai_enabled']) {
            $conversation->update(['ai_limit_notified_at' => null]);
        }

        return response()->json(['ok' => true, 'ai_enabled' => $validated['ai_enabled']]);
    }

    /**
     * Mueve el deal de la conversación a la etapa que el Komo indica — el
     * Komo es la fuente de verdad del pipeline y esta llamada refleja sus
     * cambios de etapa en la columna de /pipelines del wacrm.
     *
     * La etapa se mapea por nombre dentro del pipeline del deal. Si el nombre
     * no existe (el Komo siembra "Ganado"/"Perdido" que acá no se crean),
     * los estados terminales caen a la última etapa del pipeline y si no,
     * se conserva la etapa actual.
     */
    public function setConversationStage(Request $request, string $conversationId): JsonResponse
    {
        $validated = $request->validate([
            'stage_name' => 'required|string|max:100',
            // D5: el uuid de la etapa en Komo, que acá se guarda como
            // `external_id` al sincronizar los pipelines. Opcional para que un
            // Komo viejo siga funcionando por nombre.
            'stage_external_id' => 'nullable|uuid',
            'status' => ['nullable', Rule::in(['open', 'won', 'lost'])],
        ]);

        $accountId = $this->accountId($request);

        $conversation = Conversation::where('account_id', $accountId)->findOrFail($conversationId);

        $deal = Deal::where('account_id', $accountId)
            ->where('conversation_id', $conversation->id)
            ->first();

        if (! $deal) {
            return response()->json(['ok' => true, 'updated' => false]);
        }

        $updates = [];

        // Por uuid primero: es la única correspondencia que no se rompe cuando
        // se renombra una etapa ni se confunde entre dos homónimas de pipelines
        // distintos. El nombre queda como respaldo para los pipelines que se
        // sembraron acá antes de la integración y para un Komo sin desplegar.
        $stage = null;

        if (! empty($validated['stage_external_id'])) {
            $stage = $deal->pipeline->stages()->where('external_id', $validated['stage_external_id'])->first();
        }

        $stage ??= $deal->pipeline->stages()->where('name', $validated['stage_name'])->first();

        // Etapas terminales que Komo siembra ("Ganado"/"Perdido"): si el
        // nombre no existe, se buscan por stage_type para respetar la columna.
        if (! $stage && in_array($validated['status'] ?? null, ['won', 'lost'], true)) {
            $stage = $deal->pipeline->stages()->where('stage_type', $validated['status'])->first();
        }

        if ($stage) {
            $updates['stage_id'] = $stage->id;
        } elseif (in_array($validated['status'] ?? null, ['won', 'lost'], true)) {
            // Legacy: pipelines sincronizados antes de stage_type, sin etapa
            // terminal → cae a la última columna (comportamiento original).
            $last = $deal->pipeline->stages()->reorder('position', 'desc')->first();
            if ($last) {
                $updates['stage_id'] = $last->id;
            }
        }

        if (isset($validated['status']) && $validated['status'] !== $deal->status) {
            $updates['status'] = $validated['status'];
        }

        if ($updates !== []) {
            $deal->update($updates);
        }

        return response()->json([
            'ok' => true,
            'updated' => $updates !== [],
            'stage_id' => $updates['stage_id'] ?? $deal->stage_id,
            'status' => $updates['status'] ?? $deal->status,
        ]);
    }
}
