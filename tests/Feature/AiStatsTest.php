<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Panel de estadísticas de la IA: contadores por ventana, tasa de éxito con
 * fallbacks y la serie diaria para el gráfico (barras IA + línea de fallbacks
 * + % de éxito). Aislamiento entre cuentas incluido.
 */
class AiStatsTest extends TestCase
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

        Carbon::setTestNow('2026-08-08 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function conversation(): Conversation
    {
        $contact = Contact::create([
            'account_id' => $this->account->id,
            'name' => 'Ana',
            'phone' => '5917'.random_int(1000000, 9999999),
        ]);

        return Conversation::create([
            'account_id' => $this->account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
    }

    private function bot(Conversation $c, string $at): void
    {
        Message::create([
            'conversation_id' => $c->id,
            'sender_type' => Message::SENDER_BOT,
            'content_text' => 'respuesta',
        ])->forceFill(['created_at' => $at])->save();
    }

    private function fallback(string $at, string $accountId, ?string $userId = null): void
    {
        Notification::create([
            'account_id' => $accountId,
            'user_id' => $userId ?? $this->owner->id,
            'type' => 'ai_fallback',
            'title' => 'fallback',
        ])->forceFill(['created_at' => $at])->save();
    }

    public function test_exige_sesion(): void
    {
        $this->get('/settings/ai/stats')->assertRedirect('/login');
    }

    public function test_contadores_series_y_tasa_de_exito(): void
    {
        $c = $this->conversation();
        // Hoy 2 respuestas + 1 fallback; 5 días atrás otra respuesta.
        $this->bot($c, now()->subMinutes(5)->toDateTimeString());
        $this->bot($c, now()->subMinutes(3)->toDateTimeString());
        $this->bot($c, now()->subDays(5)->toDateTimeString());
        $this->fallback(now()->subMinute()->toDateTimeString(), $this->account->id);

        $this->actingAs($this->owner)
            ->get(route('settings.ai.stats'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->component('Settings/AiStats')
                    ->where('stats.replies_7d', 3)
                    ->where('stats.replies_30d', 3)
                    ->where('stats.replies_total', 3)
                    ->where('stats.fallbacks_30d', 1)
                    // 3 respuestas / (3 + 1 fallback)
                    ->where('stats.success_rate', 75)
                    ->has('chart', 14)
                    ->where('deltas.replies_7d_pct', null)
                    ->where('deltas.success_pp', null)
                    ->where('chart', function ($rows) {
                        $today = collect($rows)->first(fn ($r) => $r['ai_replies'] === 2 && $r['fallbacks'] === 1);
                        return $today !== null && abs($today['success_rate'] - 66.7) < 0.1;
                    });
            });
    }

    public function test_los_deltas_comparan_con_la_ventana_anterior(): void
    {
        $c = $this->conversation();
        // 4 respuestas en los últimos 7 días vs 2 en la ventana 7-14 atrás.
        for ($i = 1; $i <= 4; $i++) {
            $this->bot($c, now()->subMinutes($i * 5)->toDateTimeString());
        }
        for ($i = 1; $i <= 2; $i++) {
            $this->bot($c, now()->subDays(7 + $i)->toDateTimeString());
        }

        $this->actingAs($this->owner)
            ->get(route('settings.ai.stats'))
            ->assertInertia(fn ($page) => $page->where('deltas.replies_7d_pct', 100));
    }

    public function test_no_filtra_lost_datos_de_otra_cuenta(): void
    {
        $otroFamilia = User::create(['name' => 'Otra', 'email' => 'otra@test.com', 'password' => bcrypt('password')]);
        $otherAccount = Account::create(['name' => 'Otra empresa', 'owner_user_id' => $otroFamilia->id]);
        $otroFamilia->update(['account_id' => $otherAccount->id, 'account_role' => User::ROLE_OWNER]);

        $c = $this->conversation();
        $c->forceFill(['account_id' => $otherAccount->id])->save();
        $this->bot($c, now()->subHour()->toDateTimeString());
        $this->fallback(now()->subMinutes(30)->toDateTimeString(), $otherAccount->id);

        $this->actingAs($this->owner)
            ->get(route('settings.ai.stats'))
            ->assertInertia(fn ($page) => $page
                ->where('stats.replies_total', 0)
                ->where('stats.fallbacks_30d', 0)
                ->where('stats.success_rate', 100));
    }
}