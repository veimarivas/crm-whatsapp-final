<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiConfig;
use App\Services\Ai\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Estado de la IA de esta cuenta, para que el CRM externo (Komo) pueda
 * mostrarlo en su header sin adivinar.
 *
 * "Disponible" no es solo que el toggle esté encendido: si Ollama está caído
 * la IA está configurada pero no va a responder, y eso es justo lo que hay
 * que ver de un vistazo. Por eso se comprueba también que el proveedor
 * responda, cacheado 60s para no castigar cada render de Komo.
 */
class AiStatusApiController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $accountId = $request->attributes->get('account_id');

        $config = AiConfig::forAccount($accountId)->first();

        if (! $config) {
            return response()->json([
                'configured' => false,
                'available' => false,
                'reason' => 'not_configured',
            ]);
        }

        $withinHours = $config->isWithinBusinessHours();

        $reachable = Cache::remember(
            "ai_provider_reachable:{$config->id}",
            now()->addSeconds(60),
            fn () => Client::for($config)->isReachable(),
        );

        // El primer motivo que impide responder, en orden de importancia:
        // apagado > sin auto-respuesta > proveedor caído > fuera de horario.
        $reason = match (true) {
            ! $config->is_active => 'inactive',
            ! $config->auto_reply_enabled => 'auto_reply_off',
            ! $reachable => 'provider_down',
            ! $withinHours => 'after_hours',
            default => null,
        };

        return response()->json([
            'configured' => true,
            'available' => $reason === null,
            'reason' => $reason,
            'is_active' => (bool) $config->is_active,
            'auto_reply_enabled' => (bool) $config->auto_reply_enabled,
            'provider_reachable' => $reachable,
            'within_business_hours' => $withinHours,
            'provider' => $config->provider,
            'model' => $config->model,
            'max_per_conversation' => (int) $config->auto_reply_max_per_conversation,
        ]);
    }
}
