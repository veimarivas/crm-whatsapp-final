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
 * Centro de analítica de tiempo de respuesta (settings/response-time).
 *
 * Fija la definición del panel: una «respuesta» es el primer mensaje saliente
 * posterior al del cliente, sea de un agente o de la IA — lo contrario que en
 * /supervision, que mide la espera humana.
 */
class ResponseTimeTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private User $agente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Administrador', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        $this->agente = User::create([
            'name' => 'Daniel', 'email' => 'daniel@test.com', 'password' => bcrypt('password'),
            'account_id' => $this->account->id, 'account_role' => User::ROLE_AGENT,
        ]);

        // El panel recorta por día: congela «ahora» para que los mensajes del
        // fixture nunca caigan en un límite de medianoche según la hora real.
        Carbon::setTestNow('2026-08-08 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function conversation(?User $agent = null): Conversation
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
            'assigned_agent_id' => $agent?->id,
        ]);
    }

    private function msg(Conversation $c, string $type, string $at, ?User $sender = null): void
    {
        Message::create([
            'conversation_id' => $c->id,
            'sender_type' => $type,
            'sender_id' => $sender?->id,
            'content_text' => 'x',
        ])->forceFill(['created_at' => $at])->save();
    }

    public function test_exige_sesion(): void
    {
        $this->get('/settings/response-time')->assertRedirect('/login');
    }

    public function test_abre_con_30_dias_por_defecto(): void
    {
        $this->actingAs($this->owner)
            ->get(route('settings.response-time'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/ResponseTime')
                ->where('days', 30)
                ->where('ranges', [7, 15, 30, 90])
                ->where('kpis.total_replies', 0)
                ->has('histogram', 6)
            );
    }

    public function test_respeta_la_ventana_solicitada_y_rechaza_la_que_no_existe(): void
    {
        $this->actingAs($this->owner)
            ->get(route('settings.response-time', ['days' => 7]))
            ->assertInertia(fn ($page) => $page->where('days', 7)->has('daily', 7));

        $this->actingAs($this->owner)
            ->get(route('settings.response-time', ['days' => 12]))
            ->assertInertia(fn ($page) => $page->where('days', 30));
    }

    public function test_mide_agente_y_ia_y_arma_histograma_mediana_y_delta(): void
    {
        $c1 = $this->conversation($this->agente);
        $this->msg($c1, Message::SENDER_CUSTOMER, now()->subHours(2)->toDateTimeString());
        $this->msg($c1, Message::SENDER_AGENT, now()->subHours(2)->addSeconds(90)->toDateTimeString(), $this->agente);

        $c2 = $this->conversation();
        $this->msg($c2, Message::SENDER_CUSTOMER, now()->subHours(1)->toDateTimeString());
        $this->msg($c2, Message::SENDER_BOT, now()->subHours(1)->addSeconds(240)->toDateTimeString());

        $this->actingAs($this->owner)
            ->get(route('settings.response-time'))
            ->assertInertia(function ($page) {
                $page->component('Settings/ResponseTime')
                    ->where('kpis.total_replies', 2)
                    ->where('histogram', fn ($rows) => count($rows) === 6 && ($rows[1]['count'] ?? 0) === 2)
                    ->where('byAgent', fn ($rows) => collect($rows)
                        ->contains(fn ($a) => $a['name'] === '✨ IA' && $a['median_seconds'] === 240 && $a['count'] === 1)
                        && collect($rows)->contains(fn ($a) => $a['name'] === 'Daniel' && $a['median_seconds'] === 90))
                    ->where('daily', fn ($rows) => collect($rows)
                        ->contains(fn ($d) => $d['median_seconds'] === 240 && $d['count'] === 2));
            });
    }

    public function test_no_filtra_mensajes_de_otra_cuenta(): void
    {
        $c1 = $this->conversation($this->agente);
        $this->msg($c1, Message::SENDER_CUSTOMER, now()->subHours(2)->toDateTimeString());
        $this->msg($c1, Message::SENDER_AGENT, now()->subHours(2)->addSeconds(90)->toDateTimeString(), $this->agente);

        // Cuenta ajena: no debe sumar ni un solo número.
        $foreignOwner = User::create(['name' => 'Otra', 'email' => 'otra@test.com', 'password' => bcrypt('password')]);
        $foreignAccount = Account::create(['name' => 'Otra empresa', 'owner_user_id' => $foreignOwner->id]);
        $foreignOwner->update(['account_id' => $foreignAccount->id, 'account_role' => User::ROLE_OWNER]);
        $fc = $this->conversation();
        $fc->forceFill(['account_id' => $foreignAccount->id])->save();
        $this->msg($fc, Message::SENDER_CUSTOMER, now()->subHours(3)->toDateTimeString());
        $this->msg($fc, Message::SENDER_BOT, now()->subHours(2)->toDateTimeString());

        $this->actingAs($this->owner)
            ->get(route('settings.response-time'))
            ->assertInertia(fn ($page) => $page
                ->where('kpis.total_replies', 1)
                ->where('byAgent', fn ($rows) => collect($rows)->contains(fn ($a) => $a['name'] === 'Daniel')));
    }

    public function test_export_exige_sesion(): void
    {
        $this->get(route('settings.response-time.export'))->assertRedirect('/login');
    }

    public function test_exporta_el_mismo_panel_a_csv(): void
    {
        $c1 = $this->conversation($this->agente);
        $this->msg($c1, Message::SENDER_CUSTOMER, now()->subHours(2)->toDateTimeString());
        $this->msg($c1, Message::SENDER_AGENT, now()->subHours(2)->addSeconds(90)->toDateTimeString(), $this->agente);

        $c2 = $this->conversation();
        $this->msg($c2, Message::SENDER_CUSTOMER, now()->subHours(1)->toDateTimeString());
        $this->msg($c2, Message::SENDER_BOT, now()->subHours(1)->addSeconds(240)->toDateTimeString());

        $response = $this->actingAs($this->owner)
            ->get(route('settings.response-time.export'));

$response->assertOk();
        // fputcsv encierra en comillas las celdas con espacios; se quitan
        // porque no se quiere probar cómo escapa PHP, sino el contenido.
        $content = str_replace('"', '', $response->streamedContent());

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content, 'El CSV abre en Excel sin romper tildes.');
        $this->assertStringContainsString('Daniel;No;1;90;90', $content);
        $this->assertStringContainsString('✨ IA;Sí;1;240;240', $content);
        $this->assertStringContainsString('Mediana diaria', $content);
        $this->assertStringContainsString('Histograma de espera', $content);
    }

    public function test_export_respeta_la_ventana_y_no_filtra_otra_cuenta(): void
    {
        $c1 = $this->conversation($this->agente);
        $this->msg($c1, Message::SENDER_CUSTOMER, now()->subHours(2)->toDateTimeString());
        $this->msg($c1, Message::SENDER_AGENT, now()->subHours(2)->addSeconds(90)->toDateTimeString(), $this->agente);

        $foreignOwner = User::create(['name' => 'Otra', 'email' => 'otra@test.com', 'password' => bcrypt('password')]);
        $foreignAccount = Account::create(['name' => 'Otra empresa', 'owner_user_id' => $foreignOwner->id]);
        $foreignOwner->update(['account_id' => $foreignAccount->id, 'account_role' => User::ROLE_OWNER]);
        $fc = $this->conversation();
        $fc->forceFill(['account_id' => $foreignAccount->id])->save();
        $this->msg($fc, Message::SENDER_CUSTOMER, now()->subHours(3)->toDateTimeString());
        $this->msg($fc, Message::SENDER_BOT, now()->subHours(2)->toDateTimeString());

        // Días inválidos → cae a 30; el mensaje ajeno (a 1 h de moda) no suma.
        $content = $this->actingAs($this->owner)
            ->get(route('settings.response-time.export', ['days' => 12]))
            ->streamedContent();

        $this->assertStringContainsString('Daniel;No;1;90;90', $content);
        $this->assertStringNotContainsString('Agente eliminado;Sí;1;', $content);
    }
}