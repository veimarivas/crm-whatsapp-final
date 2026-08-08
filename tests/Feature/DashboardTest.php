<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dashboard: el gráfico de 7 días desglosa el volumen por `sender_type`
 * (entrante del cliente / saliente del agente / saliente de la IA) en el
 * prop `chart`, con aislamiento entre cuentas.
 */
class DashboardTest extends TestCase
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

        Carbon::setTestNow('2026-08-07 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function conversation(?Account $account = null): Conversation
    {
        $account ??= $this->account;
        $contact = Contact::create([
            'account_id' => $account->id,
            'name' => 'Ana',
            'phone' => '5917'.random_int(1000000, 9999999),
        ]);

        return Conversation::create([
            'account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
    }

    private function message(Conversation $c, string $senderType, string $at): void
    {
        Message::create([
            'conversation_id' => $c->id,
            'sender_type' => $senderType,
            'content_text' => 'hola',
        ])->forceFill(['created_at' => $at])->save();
    }

    public function test_exige_sesion(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_el_chart_desglosa_por_sender_type(): void
    {
        $c = $this->conversation();
        $this->message($c, Message::SENDER_CUSTOMER, now()->subMinutes(10)->toDateTimeString());
        $this->message($c, Message::SENDER_AGENT, now()->subMinutes(5)->toDateTimeString());
        $this->message($c, Message::SENDER_BOT, now()->subMinutes(2)->toDateTimeString());
        $this->message($c, Message::SENDER_CUSTOMER, now()->subDay()->toDateTimeString());

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->component('Dashboard')
                    ->has('chart', 7)
                    ->where('chart', function ($rows) {
                        $today = collect($rows)->first(fn ($r) => $r['day'] === Carbon::today()->toDateString());
                        if ($today === null) {
                            return false;
                        }

                        return $today['inbound'] === 1
                            && $today['agent_out'] === 1
                            && $today['bot_out'] === 1;
                    });
            });
    }

    public function test_no_cuenta_datos_de_otra_cuenta(): void
    {
        $otroFamilia = User::create(['name' => 'Otra', 'email' => 'otra@test.com', 'password' => bcrypt('password')]);
        $otherAccount = Account::create(['name' => 'Otra empresa', 'owner_user_id' => $otroFamilia->id]);
        $otroFamilia->update(['account_id' => $otherAccount->id, 'account_role' => User::ROLE_OWNER]);

        $c = $this->conversation($otherAccount);
        $this->message($c, Message::SENDER_CUSTOMER, now()->subMinutes(30)->toDateTimeString());
        $this->message($c, Message::SENDER_BOT, now()->subMinutes(1)->toDateTimeString());

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('chart', function ($rows) {
                return collect($rows)->every(fn ($r) => $r['inbound'] === 0 && $r['agent_out'] === 0 && $r['bot_out'] === 0);
            }));
    }
}