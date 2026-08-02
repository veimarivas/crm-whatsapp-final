<?php

namespace App\Console\Commands;

use App\Models\Deal;
use Illuminate\Console\Command;

/**
 * Repara la asignación de los deals: copia la del agente de la conversación
 * (assigned_agent_id) al deal (assigned_to), que es lo que muestra y filtra
 * /pipelines. Idempotente — no toca los que ya coinciden.
 *
 * Antes de la sincronización con Komo, el deal nunca espejaba la asignación
 * de la conversación, así que /pipelines mostraba "Sin asignar" aunque el
 * lead estuviera asignado en Komo.
 *
 * Uso: php artisan wacrm:sync-deal-assignments
 */
class SyncDealAssignments extends Command
{
    protected $signature = 'wacrm:sync-deal-assignments {--account= : UUID de la cuenta (opcional; sin él repara todas)}';

    protected $description = 'Espeja assigned_agent_id de la conversación en assigned_to del deal';

    public function handle(): int
    {
        $accountId = $this->option('account');

        $query = Deal::query()
            ->whereNotNull('conversation_id')
            ->whereHas('conversation')
            ->with('conversation:id,assigned_agent_id');

        if ($accountId) {
            $query->where('account_id', $accountId);
        }

        $total = 0;
        $fixed = 0;

        $query->orderBy('id')->eachById(500, function (Deal $deal) use (&$total, &$fixed) {
            $total++;

            if ((string) $deal->assigned_to !== (string) ($deal->conversation->assigned_agent_id ?? '')) {
                $deal->update(['assigned_to' => $deal->conversation->assigned_agent_id]);
                $fixed++;
            }
        });

        $this->info("Reparación terminada: {$fixed} deals actualizados de {$total} revisados.");

        return self::SUCCESS;
    }
}
