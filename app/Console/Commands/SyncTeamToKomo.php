<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\User;
use App\Services\Komo\Client as KomoClient;
use Illuminate\Console\Command;

/**
 * Empuja al Komo los miembros que ya existen acá.
 *
 * El espejo automático actúa al CREAR un miembro, así que los que se dieron
 * de alta antes de que existiera el puente siguen sin aparecer allá — y allá
 * es donde se asignan los contactos. Este comando los sincroniza de una vez.
 *
 * También sirve de diagnóstico: si la integración no está configurada o la
 * API key no tiene el scope correcto, lo dice con el error exacto en vez de
 * fallar en silencio.
 *
 * Uso:
 *   php artisan wacrm:sync-team-to-komo --dry-run
 *   php artisan wacrm:sync-team-to-komo
 *   php artisan wacrm:sync-team-to-komo --password=Temporal2026
 */
class SyncTeamToKomo extends Command
{
    protected $signature = 'wacrm:sync-team-to-komo
        {--account= : UUID de la cuenta (sin él, todas)}
        {--password= : Contraseña temporal para los que se creen en Komo}
        {--dry-run : Muestra qué haría, sin tocar nada}';

    protected $description = 'Da de alta en el Komo a los miembros del equipo que ya existen acá';

    public function handle(): int
    {
        $client = KomoClient::fromConfig();

        if (! $client) {
            $this->error('La integración con Komo no está configurada.');
            $this->newLine();
            $this->line('Agregá esto al .env de este proyecto:');
            $this->line('  KOMO_URL=https://komo.tudominio.com');
            $this->line('  KOMO_API_KEY=komo_live_...');
            $this->newLine();
            $this->line('La API key se genera en Komo → Ajustes → Equipo → API Keys,');
            $this->line('y necesita el scope <options=bold>team:write</>.');
            $this->newLine();
            $this->line('Después: php artisan config:clear');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $password = $this->option('password');

        $members = User::query()
            ->whereNotNull('account_id')
            ->when($this->option('account'), fn ($q, $id) => $q->where('account_id', $id))
            ->orderBy('account_id')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'account_id', 'account_role']);

        if ($members->isEmpty()) {
            $this->warn('No hay miembros para sincronizar.');

            return self::SUCCESS;
        }

        $accountIds = $members->pluck('account_id')->unique();

        // La KOMO_API_KEY pertenece a UNA cuenta de Komo. Si acá hay miembros
        // de varias cuentas y no se dice cuál, sincronizarlos a todos metería
        // usuarios de un cliente en la cuenta de otro. Se exige elegir.
        if ($accountIds->count() > 1 && ! $this->option('account')) {
            $this->error('Hay miembros en '.$accountIds->count().' cuentas distintas y la API key de Komo es de UNA sola.');
            $this->newLine();
            $this->line('Sincronizarlas todas metería usuarios de una cuenta en la de otra.');
            $this->line('Elegí la cuenta que corresponde a tu Komo con <options=bold>--account=UUID</>:');
            $this->newLine();

            foreach (Account::whereIn('id', $accountIds)->orderBy('name')->get(['id', 'name']) as $account) {
                $count = $members->where('account_id', $account->id)->count();
                $this->line(sprintf('  %s  %s (%d miembros)', $account->id, $account->name, $count));
            }

            return self::FAILURE;
        }

        // Un email inválido no lo acepta Komo: se avisa antes de intentarlo.
        $invalidos = $members->filter(fn (User $m) => ! filter_var($m->email, FILTER_VALIDATE_EMAIL));

        if ($invalidos->isNotEmpty()) {
            $this->warn('Estos miembros tienen un email que Komo va a rechazar. Corregilos allá o acá:');
            foreach ($invalidos as $m) {
                $this->warn("  · {$m->name} <{$m->email}>");
            }
            $this->newLine();
        }

        if (! $password && ! $dryRun) {
            $this->warn('Sin --password, los miembros que se creen en Komo quedan con una');
            $this->warn('contraseña aleatoria: tendrán que entrar por "olvidé mi contraseña".');
            $this->newLine();
        }

        $accounts = Account::whereIn('id', $members->pluck('account_id')->unique())->pluck('name', 'id');
        $ok = $failed = 0;

        foreach ($members as $member) {
            $role = $member->account_role === User::ROLE_ADMIN || $member->account_role === User::ROLE_OWNER
                ? 'admin'
                : 'agent';

            $label = sprintf('%s <%s> [%s / %s]',
                $member->name, $member->email, $role, $accounts[$member->account_id] ?? '?');

            if ($dryRun) {
                $this->line("  · {$label}  <fg=gray>{$member->account_id}</>");
                $ok++;

                continue;
            }

            try {
                $result = $client->provisionUser(
                    email: $member->email,
                    name: $member->name,
                    password: $password,
                    role: $role,
                );

                $this->info(sprintf('  %s %s', ($result['created'] ?? false) ? '+ creado ' : '~ ya estaba', $label));
                $ok++;
            } catch (\Throwable $e) {
                $this->error("  x {$label}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();

        if ($dryRun) {
            $this->info("{$ok} miembros se sincronizarían. Corré el comando sin --dry-run para hacerlo.");

            return self::SUCCESS;
        }

        $this->info("Sincronización terminada: {$ok} OK, {$failed} fallos.");

        if ($failed > 0) {
            $this->newLine();
            $this->line('Qué significa cada error:');
            $this->line('  <options=bold>Invalid or revoked API key</> → KOMO_API_KEY está mal, vencida, o quedó el');
            $this->line('     texto de ejemplo. Revisá el .env: <options=bold>grep KOMO_ .env</>');
            $this->line('  <options=bold>403</> → la key existe pero le falta el scope <options=bold>team:write</>.');
            $this->line('  <options=bold>409</> → ese email ya pertenece a OTRA cuenta en Komo.');
            $this->line('  <options=bold>422</> → el email no es válido para Komo.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
