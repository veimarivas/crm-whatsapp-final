<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * F0/T0.1 — cuenta y arregla lo que haría fallar la migración multi-canal.
 *
 * **Corre ANTES de `php artisan migrate`.** La migración crea dos índices
 * únicos que hoy no existen, y los dos pueden chocar contra datos históricos:
 *
 *  - `conversations UNIQUE(account_id, contact_id, channel)` — hasta ahora la
 *    conversación se resolvía con un `firstOrCreate` **sin índice que lo
 *    respaldara**, así que dos peticiones simultáneas pudieron crear dos
 *    conversaciones para el mismo contacto.
 *  - `messages UNIQUE(channel, external_message_id)` — el `message_id` de Meta
 *    nunca tuvo unicidad garantizada.
 *
 * **Los dos fallan en producción y no en local**, que es la peor forma de
 * enterarse: la migración se prueba contra una base limpia, pasa, y revienta
 * en el deploy con el sitio a medio migrar. Por eso el conteo va aparte y a
 * mano.
 *
 * Sin `--fix` no toca nada: informa. Es la única forma sensata de mirar un
 * merge de conversaciones antes de ejecutarlo.
 *
 *     php artisan wacrm:channel-precheck          # qué hay
 *     php artisan wacrm:channel-precheck --fix    # arreglarlo
 */
class ChannelMigrationPrecheck extends Command
{
    protected $signature = 'wacrm:channel-precheck {--fix : Aplica los arreglos en vez de solo informar}';

    protected $description = 'Cuenta (y opcionalmente arregla) los datos que romperían la migración multi-canal';

    /** Todo lo que apunta a una conversación y hay que mudar al fusionar. */
    private const TABLAS_CON_CONVERSACION = [
        'messages', 'message_reactions', 'deals', 'flow_runs', 'notifications',
    ];

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');

        if (! $fix) {
            $this->info('— Solo informe: no se toca nada. Usá --fix para aplicar. —');
        }

        $conversaciones = $this->conversacionesDuplicadas($fix);
        $mensajes = $this->mensajesDuplicados($fix);

        $this->newLine();

        if ($conversaciones === 0 && $mensajes === 0) {
            $this->info('✓ No hay nada que arreglar: la migración puede correr.');

            return self::SUCCESS;
        }

        if ($fix) {
            $this->info('✓ Arreglado. Ahora sí: php artisan migrate');

            return self::SUCCESS;
        }

        // Código de salida distinto de cero a propósito: esto se corre en un
        // deploy, y «hay que hacer algo» tiene que poder detectar un script.
        $this->warn('⚠ Hay que arreglar esto ANTES de migrar. Volvé a correr con --fix.');

        return self::FAILURE;
    }

    /**
     * Contactos con más de una conversación. Al fusionar gana **la más
     * antigua**: es la que tiene el historial y a la que apuntan los enlaces
     * que ya se repartieron (notificaciones, deals, el CRM externo).
     */
    private function conversacionesDuplicadas(bool $fix): int
    {
        $grupos = DB::table('conversations')
            ->select('account_id', 'contact_id', DB::raw('COUNT(*) as total'))
            ->groupBy('account_id', 'contact_id')
            ->having('total', '>', 1)
            ->get();

        $this->newLine();
        $this->line('Conversaciones duplicadas por contacto: '.$grupos->count());

        foreach ($grupos as $grupo) {
            $conversaciones = Conversation::where('account_id', $grupo->account_id)
                ->where('contact_id', $grupo->contact_id)
                ->orderBy('created_at')
                ->get();

            $superviviente = $conversaciones->first();
            $absorbidas = $conversaciones->slice(1);

            $this->line(sprintf(
                '  contacto %s: %d conversaciones → se conserva %s (la más antigua)',
                $grupo->contact_id,
                $grupo->total,
                $superviviente->id,
            ));

            if (! $fix) {
                continue;
            }

            DB::transaction(function () use ($superviviente, $absorbidas) {
                $ids = $absorbidas->pluck('id')->all();

                foreach (self::TABLAS_CON_CONVERSACION as $tabla) {
                    DB::table($tabla)->whereIn('conversation_id', $ids)
                        ->update(['conversation_id' => $superviviente->id]);
                }

                // Los no leídos se suman: son mensajes reales que nadie abrió.
                // La última actividad y el anuncio de entrada se resuelven con
                // el mismo criterio que el resto del sistema — la actividad más
                // reciente manda, la atribución original se conserva.
                $superviviente->update([
                    'unread_count' => $superviviente->unread_count + $absorbidas->sum('unread_count'),
                    'last_message_at' => $absorbidas->push($superviviente)->max('last_message_at'),
                    'entry_ad_id' => $superviviente->entry_ad_id
                        ?? $absorbidas->pluck('entry_ad_id')->filter()->first(),
                ]);

                Conversation::whereIn('id', $ids)->delete();
            });
        }

        return $grupos->count();
    }

    /**
     * `message_id` repetidos. Se conserva el más antiguo y a los demás se les
     * pone el id en NULL: la fila **no se borra** porque es un mensaje real
     * que alguien mandó o recibió, y borrarlo cambiaría el historial de la
     * conversación y las métricas de respuesta. Perder el id de Meta solo
     * significa que ese mensaje no se puede correlacionar con un webhook de
     * estado, que es un costo mucho menor.
     */
    private function mensajesDuplicados(bool $fix): int
    {
        $duplicados = DB::table('messages')
            ->select('message_id', DB::raw('COUNT(*) as total'))
            ->whereNotNull('message_id')
            ->groupBy('message_id')
            ->having('total', '>', 1)
            ->get();

        $this->newLine();
        $this->line('message_id duplicados: '.$duplicados->count());

        foreach ($duplicados as $duplicado) {
            $this->line("  {$duplicado->message_id}: {$duplicado->total} filas");

            if (! $fix) {
                continue;
            }

            $conservar = DB::table('messages')
                ->where('message_id', $duplicado->message_id)
                ->orderBy('created_at')
                ->value('id');

            DB::table('messages')
                ->where('message_id', $duplicado->message_id)
                ->where('id', '!=', $conservar)
                ->update(['message_id' => null]);
        }

        return $duplicados->count();
    }
}
