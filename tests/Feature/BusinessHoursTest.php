<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AiConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Horario de atención de la IA.
 *
 * Lo que se fija acá es el borde: los rangos que cruzan la medianoche (una
 * academia que atiende 18:00–02:00) no entraban NUNCA, porque el chequeo era
 * `hora >= inicio && hora < fin` y a la 01:00 eso es falso. La IA se callaba
 * justo en el horario en que más escriben.
 */
class BusinessHoursTest extends TestCase
{
    use RefreshDatabase;

    private function config(array $hours): AiConfig
    {
        $owner = User::create(['name' => 'Admin', 'email' => 'a@test.com', 'password' => bcrypt('x')]);
        $account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $account->id, 'account_role' => 'owner']);

        return AiConfig::create([
            'account_id' => $account->id,
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'business_hours' => $hours,
            'timezone' => 'America/La_Paz',
        ]);
    }

    private function laPaz(string $fecha): Carbon
    {
        return Carbon::parse($fecha, 'America/La_Paz');
    }

    public function test_dentro_y_fuera_de_un_rango_normal(): void
    {
        $config = $this->config(['mon' => [['08:00', '18:00']]]);

        // 2026-08-03 es lunes.
        $this->assertTrue($config->isWithinBusinessHours($this->laPaz('2026-08-03 09:00')));
        $this->assertFalse($config->isWithinBusinessHours($this->laPaz('2026-08-03 19:00')));
        $this->assertFalse($config->isWithinBusinessHours($this->laPaz('2026-08-03 07:59')));
    }

    public function test_un_dia_sin_rangos_esta_cerrado(): void
    {
        $config = $this->config(['mon' => [['08:00', '18:00']], 'sun' => []]);

        $this->assertFalse($config->isWithinBusinessHours($this->laPaz('2026-08-02 12:00')), 'Domingo cerrado.');
    }

    public function test_sin_horario_configurado_atiende_siempre(): void
    {
        $config = $this->config([]);

        $this->assertTrue($config->isWithinBusinessHours($this->laPaz('2026-08-03 03:00')));
    }

    public function test_un_rango_que_cruza_la_medianoche_vale_de_los_dos_lados(): void
    {
        $config = $this->config(['mon' => [['18:00', '02:00']]]);

        $this->assertTrue($config->isWithinBusinessHours($this->laPaz('2026-08-03 20:00')), 'Lunes a las 20:00: dentro.');
        $this->assertTrue($config->isWithinBusinessHours($this->laPaz('2026-08-04 01:00')), 'Martes a la 01:00 pertenece al tramo del lunes.');
        $this->assertFalse($config->isWithinBusinessHours($this->laPaz('2026-08-04 03:00')), 'Ya venció el tramo.');
        $this->assertFalse($config->isWithinBusinessHours($this->laPaz('2026-08-03 10:00')), 'Antes de abrir.');
    }

    public function test_dice_cuando_vuelve_a_atender(): void
    {
        $config = $this->config([
            'mon' => [['08:00', '18:00']],
            'tue' => [['08:00', '18:00']],
        ]);

        // Lunes 19:00 → abre el martes 08:00.
        $proxima = $config->nextOpeningAt($this->laPaz('2026-08-03 19:00'));

        $this->assertSame('2026-08-04 08:00', $proxima->format('Y-m-d H:i'));
    }

    public function test_la_reapertura_del_mismo_dia_se_reporta_igual(): void
    {
        $config = $this->config(['mon' => [['08:00', '12:00'], ['14:00', '18:00']]]);

        $proxima = $config->nextOpeningAt($this->laPaz('2026-08-03 13:00'));

        $this->assertSame('2026-08-03 14:00', $proxima->format('Y-m-d H:i'));
    }

    public function test_sin_horario_no_hay_reapertura_que_anunciar(): void
    {
        $this->assertNull($this->config([])->nextOpeningAt());
    }
}
