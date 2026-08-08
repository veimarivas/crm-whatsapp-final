<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Broadcast;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dashboard de métricas de broadcasts: embudo por campaña, ventanas de
 * tiempo (`?days=`) y aislamiento entre cuentas.
 */
class BroadcastMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);
    }

    private function broadcast(array $attributes = [], string $at = '-2 days'): Broadcast
    {
        $b = Broadcast::create(array_merge([
            'account_id' => $this->account->id,
            'name' => 'Campaña de prueba',
            'template_name' => 'plantilla_aprobada',
            'template_language' => 'es',
            'status' => 'sent',
            'total_recipients' => 100,
            'sent_count' => 100,
            'delivered_count' => 80,
            'read_count' => 60,
            'replied_count' => 30,
            'failed_count' => 5,
        ], $attributes));

        return $b->forceFill(['created_at' => now()->modify($at)])->save() ? $b : $b;
    }

    public function test_exige_sesion(): void
    {
        $this->get('/broadcasts-metrics')->assertRedirect('/login');
    }

    public function test_abre_con_30_dias_y_totaliza_counters(): void
    {
        $this->broadcast();

        $this->actingAs($this->owner)
            ->get(route('broadcasts.metrics'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->component('Broadcasts/Metrics')
                    ->where('days', 30)
                    ->has('chart', 30)
                    ->where('totals.broadcasts', 1)
                    ->where('totals.sent', 100)
                    ->where('totals.replied', 30)
                    ->where('rates.reply', 30)
                    ->has('funnels', 1)
                    ->where('funnels', fn ($fun) => $fun[0]['name'] === 'Campaña de prueba'
                        && count($fun[0]['steps']) === 4
                        && $fun[0]['steps'][0] === ['name' => 'Enviados', 'value' => 100, 'color' => '#3b82f6']
                        && $fun[0]['steps'][3]['value'] === 30);
            });
    }

    public function test_respeta_la_ventana_y_rechaza_dias_invalidos(): void
    {
        $this->actingAs($this->owner)
            ->get(route('broadcasts.metrics', ['days' => 7]))
            ->assertInertia(fn ($page) => $page->has('chart', 7)->where('days', 7));

        $this->actingAs($this->owner)
            ->get(route('broadcasts.metrics', ['days' => 45]))
            ->assertInertia(fn ($page) => $page->where('days', 30));
    }

    public function test_la_ventana_filtra_las_campanas_del_top(): void
    {
        // Una campaña vieja con mucha respuesta: no debe aparecer en 7d.
        $this->broadcast(['name' => 'Vieja', 'delivered_count' => 100, 'read_count' => 100, 'replied_count' => 100], '-20 days');
        $this->broadcast(['name' => 'Reciente', 'delivered_count' => 50, 'read_count' => 50, 'replied_count' => 50], '-2 days');

        $this->actingAs($this->owner)
            ->get(route('broadcasts.metrics', ['days' => 7]))
            ->assertInertia(fn ($page) => $page
                ->has('funnels', 1)
                ->where('topByReply', fn ($rows) => count($rows) === 1 && $rows[0]['name'] === 'Reciente'));
    }

    public function test_no_filtra_datos_de_otra_cuenta(): void
    {
        $this->broadcast(['name' => 'Mia']);

        $other = User::create(['name' => 'Otra', 'email' => 'otra@test.com', 'password' => bcrypt('password')]);
        $otherAccount = Account::create(['name' => 'Otra empresa', 'owner_user_id' => $other->id]);
        $other->update(['account_id' => $otherAccount->id, 'account_role' => User::ROLE_OWNER]);
        Broadcast::create([
            'account_id' => $otherAccount->id,
            'name' => 'Ajena',
            'template_name' => 'plantilla_aprobada',
            'template_language' => 'es',
            'status' => 'sent',
            'sent_count' => 500,
            'delivered_count' => 400,
            'read_count' => 300,
            'replied_count' => 200,
        ])->forceFill(['created_at' => now()->subDay()])->save();

        $this->actingAs($this->owner)
            ->get(route('broadcasts.metrics'))
            ->assertInertia(fn ($page) => $page
                ->where('totals.broadcasts', 1)
                ->where('totals.sent', 100));
    }
}