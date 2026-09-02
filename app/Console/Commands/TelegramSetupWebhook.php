<?php

namespace App\Console\Commands;

use App\Models\ChannelConfig;
use App\Services\Channels\ChannelRules;
use App\Services\Telegram\TelegramApi;
use Illuminate\Console\Command;

/**
 * Re-registra el webhook de Telegram de una cuenta.
 *
 * **No duplica a `/settings/channels`, que ya lo registra al conectar.** Existe
 * para lo que la pantalla no puede: volver a apuntar el bot después de que
 * cambie el dominio, o reparar un webhook que Telegram desactivó por su cuenta
 * —lo hace tras muchos errores seguidos— sin obligar a desconectar y volver a
 * pegar el token.
 *
 *     php artisan wacrm:telegram-setup-webhook
 *     php artisan wacrm:telegram-setup-webhook --account=<uuid>
 */
class TelegramSetupWebhook extends Command
{
    protected $signature = 'wacrm:telegram-setup-webhook {--account= : UUID de la cuenta (sin él, todas las conectadas)}';

    protected $description = 'Registra en Telegram la URL del webhook de cada cuenta con el bot conectado';

    public function handle(): int
    {
        $configs = ChannelConfig::query()
            ->where('channel', ChannelRules::TELEGRAM)
            ->when($this->option('account'), fn ($q, $id) => $q->where('account_id', $id))
            ->get();

        if ($configs->isEmpty()) {
            // Se dice qué falta, no solo que no hay nada: quien corre esto en un
            // deploy no tiene por qué saber dónde se conecta un bot.
            $this->warn('Ninguna cuenta tiene Telegram conectado. Se conecta en /settings/channels.');

            return self::SUCCESS;
        }

        $huboProblema = false;

        foreach ($configs as $config) {
            $url = route('webhooks.telegram', $config->account_id);
            $secret = $config->credential('webhook_secret');

            $this->line("\nCuenta {$config->account_id}");
            $this->line("  → {$url}");

            if (! $secret) {
                // Sin secreto el webhook quedaría abierto a cualquiera que
                // descubra la URL. Antes que registrar algo inseguro, no se
                // registra y se dice.
                $this->error('  falta el webhook_secret: reconectá el bot desde /settings/channels.');
                $huboProblema = true;

                continue;
            }

            try {
                TelegramApi::for($config)->setWebhook($url, $secret);
                $this->info('  ✓ registrado');
            } catch (\Throwable $e) {
                $this->error('  '.$e->getMessage());
                $huboProblema = true;
            }
        }

        // Código de salida distinto de cero: esto se corre en un deploy, donde
        // nadie lee la salida entera.
        return $huboProblema ? self::FAILURE : self::SUCCESS;
    }
}
