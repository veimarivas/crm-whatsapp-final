<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\WebhookEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API v1 — Sincronización de la estructura de pipelines desde el Komo.
 *
 * El Komo es la fuente de verdad de las columnas (/settings/pipelines); este
 * endpoint reconcilia el pipeline local del wacrm contra el payload que manda
 * Komo en cada cambio:
 *
 *  - pipelines y etapas se matchean por external_id (uuid del Komo) y, si no,
 *    por nombre (para absorber los pipelines sembrados localmente antes de la
 *    integración).
 *  - las etapas/pipelines sincronizados que ya no vienen en el payload se
 *    eliminan y sus deals se reasignan a la primera etapa abierta, para que
 *    /pipelines quede con exactamente las mismas columnas que el Komo.
 */
class PipelineSyncController extends Controller
{
    private function accountId(Request $request): string
    {
        return $request->attributes->get('account_id');
    }

    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pipelines' => 'required|array',
            'pipelines.*.id' => 'required|uuid',
            'pipelines.*.name' => 'required|string|max:100',
            'pipelines.*.is_default' => 'nullable|boolean',
            'pipelines.*.stages' => 'present|array',
            'pipelines.*.stages.*.id' => 'required|uuid',
            'pipelines.*.stages.*.name' => 'required|string|max:100',
            'pipelines.*.stages.*.position' => 'nullable|integer',
            'pipelines.*.stages.*.color' => 'nullable|string|max:20',
            'pipelines.*.stages.*.stage_type' => 'nullable|string|max:10',
        ]);

        $accountId = $this->accountId($request);
        $incomingIds = collect($validated['pipelines'])->pluck('id')->all();

        foreach ($validated['pipelines'] as $data) {
            $this->syncPipeline($accountId, $data);
        }

        // Pipelines que Komo ya no reporta (fueron borrados allá) desaparecen.
        Pipeline::forAccount($accountId)
            ->whereNotNull('external_id')
            ->whereNotIn('external_id', $incomingIds)
            ->get()
            ->each(fn (Pipeline $p) => $this->destroyPipeline($accountId, $p));

        $this->subscribeStageWebhooks($accountId);

        return response()->json(['ok' => true]);
    }

    /**
     * El webhook saliente del wacrm se suscribió al provisionar con una lista
     * fija de eventos. Este es el único sitio que sabe que el llamador es el
     * Komo, así que garantiza que sus endpoints reciban el espejo inverso de
     * movimientos (deal.stage_changed). Idempotente.
     */
    private function subscribeStageWebhooks(string $accountId): void
    {
        WebhookEndpoint::forAccount($accountId)
            ->where('is_active', true)
            ->get()
            ->each(function (WebhookEndpoint $endpoint) {
                $events = $endpoint->events ?? [];

                if (! in_array('deal.stage_changed', $events, true)) {
                    $endpoint->update(['events' => [...$events, 'deal.stage_changed']]);
                }
            });
    }

    private function syncPipeline(string $accountId, array $data): void
    {
        $pipeline = Pipeline::forAccount($accountId)->where('external_id', $data['id'])->first()
            ?? Pipeline::forAccount($accountId)->where('name', $data['name'])->first();

        $attrs = [
            'name' => $data['name'],
            'external_id' => $data['id'],
            'is_default' => (bool) ($data['is_default'] ?? false),
        ];

        if (! $pipeline) {
            $pipeline = Pipeline::create([...$attrs, 'account_id' => $accountId]);
        } else {
            $pipeline->update($attrs);
        }

        if ($pipeline->is_default) {
            Pipeline::forAccount($accountId)->where('id', '!=', $pipeline->id)->update(['is_default' => false]);
        }

        $incomingStageIds = collect($data['stages'])->pluck('id')->all();
        $incomingStageNames = collect($data['stages'])->pluck('name')->all();

        foreach ($data['stages'] as $stageData) {
            $this->syncStage($pipeline, $stageData);
        }

        $pipeline->stages()->get()->each(function (PipelineStage $stage) use ($incomingStageIds, $incomingStageNames, $pipeline) {
            if (in_array($stage->external_id, $incomingStageIds, true) || in_array($stage->name, $incomingStageNames, true)) {
                return;
            }

            $this->destroyStage($pipeline, $stage);
        });
    }

    private function syncStage(Pipeline $pipeline, array $data): void
    {
        $stage = $pipeline->stages()->where('external_id', $data['id'])->first()
            ?? $pipeline->stages()->where('name', $data['name'])->first();

        $attrs = [
            'name' => $data['name'],
            'external_id' => $data['id'],
            'position' => $data['position'] ?? 0,
            'color' => $data['color'] ?? '#3b82f6',
            'stage_type' => $data['stage_type'] ?? PipelineStage::TYPE_OPEN,
        ];

        if (! $stage) {
            $pipeline->stages()->create($attrs);
        } else {
            $stage->update($attrs);
        }
    }

    /** Borra una etapa local que ya no existe en Komo; sus deals van a la primera etapa abierta. */
    private function destroyStage(Pipeline $pipeline, PipelineStage $stage): void
    {
        if ($stage->deals()->exists()) {
            $fallback = $pipeline->stages()
                ->where('stage_type', PipelineStage::TYPE_OPEN)
                ->where('id', '!=', $stage->id)
                ->orderBy('position')
                ->first();

            if ($fallback) {
                $stage->deals()->update(['stage_id' => $fallback->id, 'status' => 'open']);
            }
        }

        $stage->delete();
    }

    private function destroyPipeline(string $accountId, Pipeline $pipeline): void
    {
        if ($pipeline->deals()->exists()) {
            $fallback = Pipeline::forAccount($accountId)
                ->where('id', '!=', $pipeline->id)
                ->orderBy('created_at')
                ->first();

            $fallbackStage = $fallback?->stages()->where('stage_type', PipelineStage::TYPE_OPEN)->orderBy('position')->first();

            if ($fallback && $fallbackStage) {
                $pipeline->deals()->update(['pipeline_id' => $fallback->id, 'stage_id' => $fallbackStage->id]);
            }
        }

        $pipeline->delete();
    }
}
