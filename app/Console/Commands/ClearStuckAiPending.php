<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Services\Webhooks\Dispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Apaga las burbujas «Pensando respuesta…» que quedaron colgadas.
 *
 * El job enciende `ai_pending` al arrancar y lo apaga al terminar — pero si al
 * job LO MATAN, su `catch` nunca corre. Y lo matan más seguido de lo que
 * parece: se pasa del timeout del worker, un OOM, un `systemctl restart` en
 * pleno despliegue. La conversación queda con la burbuja girando para siempre,
 * en el wacrm y en Komo, y el agente cree que la IA está por contestar.
 *
 * Es una red de seguridad, no un parche: ningún proceso puede limpiar lo suyo
 * después de que lo matan, así que la limpieza tiene que venir de afuera.
 */
class ClearStuckAiPending extends Command
{
    protected $signature = 'wacrm:ai-clear-stuck-pending {--minutes= : Antigüedad a partir de la cual se considera colgada}';

    protected $description = 'Apaga los "Pensando respuesta…" de jobs que murieron sin poder limpiar';

    public function handle(): int
    {
        // Por defecto, el timeout del job más un margen: por debajo de eso la
        // IA puede estar trabajando de verdad y apagarle la burbuja sería
        // mentir al revés.
        $minutos = (int) ($this->option('minutes')
            ?: max(5, (int) ceil(((int) config('services.ollama.timeout', 180) + 60) / 60)));

        $limite = now()->subMinutes($minutos);

        $colgadas = Conversation::where('ai_pending', true)
            ->where(fn ($q) => $q->where('ai_pending_at', '<', $limite)->orWhereNull('ai_pending_at'))
            ->get();

        if ($colgadas->isEmpty()) {
            $this->info("Sin burbujas colgadas (umbral: {$minutos} min).");

            return self::SUCCESS;
        }

        foreach ($colgadas as $conversation) {
            $conversation->update(['ai_pending' => false, 'ai_pending_at' => null]);

            // Komo pinta la misma burbuja: si no se le avisa, allá sigue
            // girando aunque acá ya esté apagada.
            rescue(fn () => app(Dispatcher::class)->dispatch(
                $conversation->account_id,
                'ai.pending_changed',
                ['conversation_id' => $conversation->id, 'pending' => false],
            ), report: false);

            Log::info('Burbuja "IA pensando" colgada apagada', [
                'conversation_id' => $conversation->id,
                'desde' => $conversation->ai_pending_at?->toIso8601String(),
            ]);
        }

        $this->warn($colgadas->count().' burbuja(s) colgada(s) apagada(s).');
        $this->line('Si esto pasa seguido, el job se está muriendo: revisá el timeout del worker y la memoria del servidor.');

        return self::SUCCESS;
    }
}
