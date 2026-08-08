<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Supervision\ResponseMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Panel de seguimiento del admin. Fija las definiciones: si cambian, los
 * números que el admin usa para decidir cambian de sentido.
 *
 * Es el gemelo del `SupervisionMetricsTest` del Komo — mismas reglas sobre
 * otra tabla. Si se toca una definición hay que tocar los dos.
 */
class SupervisionTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private User $agente;

    private User $otro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Administrador', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        $this->agente = $this->makeAgent('daniel@test.com', 'Daniel');
        $this->otro = $this->makeAgent('silvia@test.com', 'Silvia');

        Carbon::setTestNow('2026-08-07 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeAgent(string $email, string $name): User
    {
        return User::create([
            'name' => $name, 'email' => $email, 'password' => bcrypt('password'),
            'account_id' => $this->account->id, 'account_role' => User::ROLE_AGENT,
        ]);
    }

    private function makeConversation(?User $agent = null, string $name = 'Ana', string $status = 'open'): Conversation
    {
        $contact = Contact::create([
            'account_id' => $this->account->id,
            'name' => $name,
            'phone' => '5917'.random_int(1000000, 9999999),
        ]);

        return Conversation::create([
            'account_id' => $this->account->id,
            'contact_id' => $contact->id,
            'status' => $status,
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

    private function build(int $days = 30): array
    {
        return (new ResponseMetrics($this->account->id, now()->subDays($days)->startOfDay()))->build();
    }

    public function test_mide_la_primera_respuesta_desde_el_primer_mensaje_del_contacto(): void
    {
        $c = $this->makeConversation($this->agente);
        $this->msg($c, Message::SENDER_CUSTOMER, now()->subHours(3)->toDateTimeString());
        $this->msg($c, Message::SENDER_AGENT, now()->subHours(3)->addMinutes(10)->toDateTimeString(), $this->agente);

        $row = collect($this->build()['conversations'])->firstWhere('id', $c->id);

        $this->assertSame(600, $row['first_response_seconds']);
        $this->assertSame('asignado', $row['first_responder']);
        $this->assertNull($row['awaiting_minutes']);
    }

    public function test_los_mensajes_seguidos_del_contacto_no_reinician_el_reloj(): void
    {
        $c = $this->makeConversation($this->agente);
        $this->msg($c, Message::SENDER_CUSTOMER, now()->subHours(2)->toDateTimeString());
        $this->msg($c, Message::SENDER_CUSTOMER, now()->subHours(2)->addMinutes(5)->toDateTimeString());
        $this->msg($c, Message::SENDER_AGENT, now()->subHours(2)->addMinutes(20)->toDateTimeString(), $this->agente);

        // 20 minutos desde el PRIMERO, no 15 desde el último.
        $this->assertSame(1200, collect($this->build()['conversations'])->firstWhere('id', $c->id)['first_response_seconds']);
    }

    public function test_la_respuesta_de_la_ia_no_cierra_la_espera_del_humano(): void
    {
        $c = $this->makeConversation($this->agente);
        $this->msg($c, Message::SENDER_CUSTOMER, now()->subMinutes(90)->toDateTimeString());
        $this->msg($c, Message::SENDER_BOT, now()->subMinutes(89)->toDateTimeString());

        $row = collect($this->build()['conversations'])->firstWhere('id', $c->id);

        $this->assertSame('ia', $row['first_responder']);
        $this->assertNull($row['first_response_seconds'], 'La IA no cuenta como respuesta humana.');
        $this->assertSame(90, $row['awaiting_minutes']);
        $this->assertTrue($row['breached_sla']);
    }

    public function test_distingue_al_asignado_de_otro_agente_que_contesta_por_el(): void
    {
        $c = $this->makeConversation($this->agente);
        $this->msg($c, Message::SENDER_CUSTOMER, now()->subHour()->toDateTimeString());
        $this->msg($c, Message::SENDER_AGENT, now()->subMinutes(50)->toDateTimeString(), $this->otro);

        $this->assertSame('otro_agente', collect($this->build()['conversations'])->firstWhere('id', $c->id)['first_responder']);
    }

    public function test_el_saliente_proactivo_no_cuenta_como_respuesta(): void
    {
        $c = $this->makeConversation($this->agente);
        $this->msg($c, Message::SENDER_AGENT, now()->subHours(5)->toDateTimeString(), $this->agente);
        $this->msg($c, Message::SENDER_CUSTOMER, now()->subHours(4)->toDateTimeString());

        $row = collect($this->build()['conversations'])->firstWhere('id', $c->id);

        $this->assertNull($row['avg_response_seconds'], 'No hubo respuesta a un entrante.');
        $this->assertSame(240, $row['awaiting_minutes']);
    }

    public function test_cuenta_los_contactos_asignados_aunque_no_tengan_actividad(): void
    {
        // Con actividad en el periodo
        $activa = $this->makeConversation($this->agente, 'Ana');
        $this->msg($activa, Message::SENDER_CUSTOMER, now()->subHour()->toDateTimeString());

        // Asignada pero sin un solo mensaje: sigue siendo carga suya.
        $this->makeConversation($this->agente, 'Beto');
        // Y una cerrada, que también cuenta como contacto asignado.
        $this->makeConversation($this->agente, 'Carla', 'closed');

        $daniel = collect($this->build()['agents'])->firstWhere('id', $this->agente->id);

        $this->assertSame(3, $daniel['assigned_contacts'], 'La carga es todo lo asignado, no solo lo que se movió.');
        $this->assertSame(1, $daniel['conversations'], 'Activas en el periodo: solo una.');
        $this->assertSame(2, $daniel['open_conversations']);
        $this->assertSame(1, $daniel['closed_conversations']);
    }

    public function test_agrupa_por_agente_y_reporta_las_sin_asignar_aparte(): void
    {
        $suya = $this->makeConversation($this->agente, 'Ana');
        $this->msg($suya, Message::SENDER_CUSTOMER, now()->subHours(2)->toDateTimeString());
        $this->msg($suya, Message::SENDER_AGENT, now()->subHours(2)->addMinutes(5)->toDateTimeString(), $this->agente);

        $huerfana = $this->makeConversation(null, 'Beto');
        $this->msg($huerfana, Message::SENDER_CUSTOMER, now()->subHours(2)->toDateTimeString());

        $agents = collect($this->build()['agents']);

        $daniel = $agents->firstWhere('id', $this->agente->id);
        $this->assertSame(1, $daniel['answered']);
        $this->assertSame(300, $daniel['avg_first_response_seconds']);

        $nadie = $agents->firstWhere('id', null);
        $this->assertSame(1, $nadie['never_answered']);
        $this->assertSame(1, $nadie['breached_sla']);
    }

    public function test_ignora_la_actividad_fuera_de_la_ventana(): void
    {
        $c = $this->makeConversation($this->agente);
        $this->msg($c, Message::SENDER_CUSTOMER, now()->subDays(60)->toDateTimeString());

        $this->assertCount(0, $this->build(30)['conversations']);
        $this->assertCount(1, $this->build(90)['conversations']);
    }

    public function test_la_serie_diaria_rellena_los_dias_sin_actividad(): void
    {
        $c = $this->makeConversation($this->agente);
        $this->msg($c, Message::SENDER_CUSTOMER, now()->subDays(2)->setTime(9, 0)->toDateTimeString());
        $this->msg($c, Message::SENDER_AGENT, now()->subDays(2)->setTime(9, 10)->toDateTimeString(), $this->agente);

        $daily = collect($this->build(7)['daily']);

        $this->assertCount(8, $daily);
        $this->assertSame(600, $daily->firstWhere('date', now()->subDays(2)->format('Y-m-d'))['avg_response_seconds']);
        $this->assertNull($daily->firstWhere('date', now()->subDay()->format('Y-m-d'))['avg_response_seconds'],
            'Un día sin respuestas no promedia cero.');
    }

    public function test_solo_el_admin_entra_al_panel(): void
    {
        $this->actingAs($this->agente)->get(route('supervision.index'))->assertForbidden();
        $this->actingAs($this->owner)->get(route('supervision.index'))->assertOk();
    }

    public function test_el_index_lleva_mediana_comparativa_heatmap_compliance_y_backlog(): void
    {
        // 2026-08-05 = miércoles (w=3).
        $c = $this->makeConversation($this->agente);
        $this->msg($c, Message::SENDER_CUSTOMER, '2026-08-05 09:00:00');
        $this->msg($c, Message::SENDER_AGENT, '2026-08-05 09:10:00', $this->agente);
        // Un contacto que sigue esperando hace 2 horas → balde "1-4 h".
        $c2 = $this->makeConversation($this->agente, 'Carla');
        $this->msg($c2, Message::SENDER_CUSTOMER, '2026-08-07 10:00:00');

        $this->actingAs($this->owner)
            ->get(route('supervision.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('median_by_agent', 1)
                ->where('median_by_agent.0.name', 'Daniel')
                ->where('median_by_agent.0.median', 600)
                ->where('backlog.1.label', '1-4 h')
                ->where('backlog.1.count', 1)
                ->where('heatmap', function ($rows) {
                    $mie = collect($rows)->firstWhere('label', 'Mié');
                    return $mie !== null && $mie['hours'][9] === 1;
                })
                ->where('compliance', function ($rows) {
                    $day = collect($rows)->firstWhere('date', '2026-08-05');
                    return $day !== null && $day['total'] === 1 && $day['within'] === 1 && $day['pct'] === 100;
                }));
    }

    public function test_el_backlog_no_cuenta_una_respuesta_de_la_ia(): void
    {
        $c = $this->makeConversation($this->agente);
        // Contacto escribió hace 3 h, la IA contestó a los 2 minutos... y el
        // humano nunca respondió: el reloj NO se detiene con la IA.
        $this->msg($c, Message::SENDER_CUSTOMER, '2026-08-07 09:00:00');
        $this->msg($c, Message::SENDER_BOT, '2026-08-07 09:02:00');

        $this->actingAs($this->owner)
            ->get(route('supervision.index'))
            ->assertInertia(fn ($page) => $page
                ->where('backlog.1.label', '1-4 h')
                ->where('backlog.1.count', 1));
    }

    public function test_la_ficha_incluye_el_promedio_del_equipo(): void
    {
        $c = $this->makeConversation($this->agente);
        $this->msg($c, Message::SENDER_CUSTOMER, '2026-08-07 11:00:00');
        $this->msg($c, Message::SENDER_AGENT, '2026-08-07 11:05:00', $this->agente);

        $this->actingAs($this->owner)
            ->get(route('supervision.agent', $this->agente->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('teamDaily')
                ->where('daily', function ($rows) {
                    $today = collect($rows)->firstWhere('date', '2026-08-07');
                    return $today !== null && $today['avg_response_seconds'] === 300;
                }));
    }

    public function test_los_datos_nuevos_no_se_filtran_por_cuenta(): void
    {
        // Otra cuenta con mensajes que el panel del owner no debe ver.
        $otroFamiliar = User::create(['name' => 'Otra cuenta', 'email' => 'otra@test.com', 'password' => bcrypt('password')]);
        $otherAccount = Account::create(['name' => 'Otra empresa', 'owner_user_id' => $otroFamiliar->id]);
        $otroFamiliar->update(['account_id' => $otherAccount->id, 'account_role' => User::ROLE_OWNER]);

        $oc = $this->makeConversation();
        $oc->forceFill(['account_id' => $otherAccount->id])->save();
        $this->msg($oc, Message::SENDER_CUSTOMER, '2026-08-07 10:00:00');

        $this->actingAs($this->owner)
            ->get(route('supervision.index'))
            ->assertInertia(fn ($page) => $page
                ->where('median_by_agent', [])
                ->where('backlog', function ($buckets) {
                    return collect($buckets)->every(fn ($b) => $b['count'] === 0);
                }));
    }

    public function test_solo_el_admin_exporta_el_csv(): void
    {
        $this->actingAs($this->agente)->get(route('supervision.export'))->assertForbidden();
        $this->actingAs($this->owner)->get(route('supervision.export'))->assertOk();
    }

    public function test_exporta_agentes_y_conversaciones_a_csv(): void
    {
        $c = $this->makeConversation($this->agente, 'Ana');
        $this->msg($c, Message::SENDER_CUSTOMER, '2026-08-07 10:00:00');
        $this->msg($c, Message::SENDER_AGENT, '2026-08-07 10:10:00', $this->agente);

        $response = $this->actingAs($this->owner)->get(route('supervision.export'));

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content, 'El CSV abre en Excel sin romper tildes.');
        $this->assertStringContainsString('Por agente', $content);
        $this->assertStringContainsString('Daniel', $content);
        $this->assertStringContainsString('Contacto por contacto', $content);
        $this->assertStringContainsString('Ana', $content);
        $this->assertStringContainsString('asignado', $content);
    }

    public function test_export_no_filtra_los_datos_de_otra_cuenta(): void
    {
        $otroFamiliar = User::create(['name' => 'Otra cuenta', 'email' => 'otra@test.com', 'password' => bcrypt('password')]);
        $otherAccount = Account::create(['name' => 'Otra empresa', 'owner_user_id' => $otroFamiliar->id]);
        $otroFamiliar->update(['account_id' => $otherAccount->id, 'account_role' => User::ROLE_OWNER]);

        $oc = $this->makeConversation($this->agente, 'Ana');
        $oc->forceFill(['account_id' => $otherAccount->id])->save();
        $this->msg($oc, Message::SENDER_CUSTOMER, '2026-08-07 10:00:00');
        $this->msg($oc, Message::SENDER_AGENT, '2026-08-07 10:10:00', $this->agente);

        $content = $this->actingAs($this->owner)
            ->get(route('supervision.export'))
            ->streamedContent();

        $this->assertStringNotContainsString('Ana', $content);
        $this->assertStringNotContainsString('Daniel', $content);
    }
}
