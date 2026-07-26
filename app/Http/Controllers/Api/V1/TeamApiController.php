<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
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

        return response()->json(['ok' => true, 'assigned_agent_id' => $agentId]);
    }

    /** Cambia el modo IA/Humano de una conversación desde Komo. */
    public function setAiMode(Request $request, string $conversationId): JsonResponse
    {
        $validated = $request->validate(['ai_enabled' => 'required|boolean']);

        $accountId = $this->accountId($request);
        $conversation = Conversation::where('account_id', $accountId)->findOrFail($conversationId);

        $conversation->update([
            'ai_autoreply_disabled' => ! $validated['ai_enabled'],
            'ai_reply_count' => $validated['ai_enabled'] ? 0 : $conversation->ai_reply_count,
            // El contador vuelve a cero: el aviso de tope agotado tambien.
            'ai_limit_notified_at' => $validated['ai_enabled'] ? null : $conversation->ai_limit_notified_at,
        ]);

        return response()->json(['ok' => true, 'ai_enabled' => $validated['ai_enabled']]);
    }
}
