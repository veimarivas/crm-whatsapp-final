<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Espejo de miembros wacrm → Komo.
 *
 * El puente era de ida solamente: Komo creaba el user acá al aceptar una
 * invitación, pero un miembro dado de alta EN este proyecto no existía allá
 * — y allá es donde se asignan los contactos, así que no aparecía en ningún
 * desplegable de responsable.
 */
class TeamMemberMirrorTest extends TestCase
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

        config()->set('services.komo.url', 'http://komo.test');
        config()->set('services.komo.api_key', 'komo_live_x');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Daniel Pérez',
            'email' => 'daniel@test.com',
            'password' => 'secretpass123',
            'password_confirmation' => 'secretpass123',
            'account_role' => 'agent',
        ], $overrides);
    }

    public function test_crear_un_miembro_sin_link_lo_da_de_alta_aca_y_en_komo(): void
    {
        Http::fake(['*/api/v1/team/provision' => Http::response(['created' => true], 201)]);

        $this->actingAs($this->owner)
            ->post(route('team.members.store'), $this->payload())
            ->assertRedirect();

        $member = User::where('email', 'daniel@test.com')->sole();
        $this->assertSame($this->account->id, $member->account_id);
        $this->assertSame(User::ROLE_AGENT, $member->account_role);

        // El Komo recibe el MISMO email y password: es lo que le permite
        // entrar allá, que es donde trabaja los contactos asignados.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/v1/team/provision')
            && $r['email'] === 'daniel@test.com'
            && $r['password'] === 'secretpass123'
            && $r['role'] === 'agent');
    }

    public function test_el_viewer_entra_al_komo_como_agente(): void
    {
        Http::fake(['*' => Http::response(['created' => true], 201)]);

        $this->actingAs($this->owner)
            ->post(route('team.members.store'), $this->payload(['account_role' => 'viewer']))
            ->assertRedirect();

        // Komo no tiene un rol equivalente a "solo lectura".
        Http::assertSent(fn ($r) => $r['role'] === 'agent');
    }

    public function test_el_admin_se_espeja_como_admin(): void
    {
        Http::fake(['*' => Http::response(['created' => true], 201)]);

        $this->actingAs($this->owner)
            ->post(route('team.members.store'), $this->payload(['account_role' => 'admin']))
            ->assertRedirect();

        Http::assertSent(fn ($r) => $r['role'] === 'admin');
    }

    public function test_si_el_komo_no_responde_el_miembro_igual_se_crea_y_se_avisa(): void
    {
        Http::fake(['*' => Http::response(['message' => 'boom'], 500)]);

        $this->actingAs($this->owner)
            ->post(route('team.members.store'), $this->payload())
            ->assertRedirect()
            ->assertSessionHas('success', fn ($msg) => str_contains($msg, 'no está configurado')
                || str_contains($msg, 'no va a aparecer'));

        // No se pierde el alta local por un fallo de red.
        $this->assertNotNull(User::where('email', 'daniel@test.com')->first());
    }

    public function test_sin_integracion_configurada_avisa_en_vez_de_mentir(): void
    {
        config()->set('services.komo.url', null);
        Http::fake();

        $this->actingAs($this->owner)
            ->post(route('team.members.store'), $this->payload())
            ->assertRedirect();

        Http::assertNothingSent();
        $this->assertNotNull(User::where('email', 'daniel@test.com')->first());
    }

    public function test_no_se_puede_repetir_un_email(): void
    {
        Http::fake();

        $this->actingAs($this->owner)
            ->post(route('team.members.store'), $this->payload(['email' => 'admin@test.com']))
            ->assertSessionHasErrors('email');
    }

    public function test_el_comando_empuja_los_miembros_que_ya_existian(): void
    {
        // Los dados de alta ANTES de que existiera el puente no se espejaron
        // solos: el comando es la red de seguridad para esos.
        User::create([
            'name' => 'Silvia', 'email' => 'silvia@test.com', 'password' => bcrypt('password'),
            'account_id' => $this->account->id, 'account_role' => User::ROLE_AGENT,
        ]);

        Http::fake(['*' => Http::response(['created' => true], 201)]);

        $this->artisan('wacrm:sync-team-to-komo')->assertSuccessful();

        // El owner y la agente: los dos.
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r['email'] === 'silvia@test.com' && $r['role'] === 'agent');
        Http::assertSent(fn ($r) => $r['email'] === 'admin@test.com' && $r['role'] === 'admin');
    }

    public function test_el_comando_se_niega_a_mezclar_cuentas(): void
    {
        // La KOMO_API_KEY es de UNA cuenta de Komo: sincronizar varias metería
        // usuarios de un cliente en la cuenta de otro.
        $ajeno = User::create(['name' => 'Otro', 'email' => 'otro@otra.com', 'password' => bcrypt('password')]);
        $otra = Account::create(['name' => 'Otra empresa', 'owner_user_id' => $ajeno->id]);
        $ajeno->update(['account_id' => $otra->id, 'account_role' => User::ROLE_OWNER]);

        Http::fake();

        $this->artisan('wacrm:sync-team-to-komo')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_con_la_cuenta_elegida_solo_sincroniza_esa(): void
    {
        $ajeno = User::create(['name' => 'Otro', 'email' => 'otro@otra.com', 'password' => bcrypt('password')]);
        $otra = Account::create(['name' => 'Otra empresa', 'owner_user_id' => $ajeno->id]);
        $ajeno->update(['account_id' => $otra->id, 'account_role' => User::ROLE_OWNER]);

        Http::fake(['*' => Http::response(['created' => true], 201)]);

        $this->artisan('wacrm:sync-team-to-komo', ['--account' => $this->account->id]);

        // Solo el owner de MI cuenta; el de la otra empresa no se toca.
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['email'] === 'admin@test.com');
    }

    public function test_el_comando_avisa_si_falta_configurar_la_integracion(): void
    {
        config()->set('services.komo.url', null);
        Http::fake();

        $this->artisan('wacrm:sync-team-to-komo')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_el_dry_run_no_manda_nada(): void
    {
        Http::fake();

        $this->artisan('wacrm:sync-team-to-komo', ['--dry-run' => true])->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_solo_el_admin_da_de_alta_miembros(): void
    {
        $agente = User::create([
            'name' => 'Silvia', 'email' => 'silvia@test.com', 'password' => bcrypt('password'),
            'account_id' => $this->account->id, 'account_role' => User::ROLE_AGENT,
        ]);

        Http::fake();

        $this->actingAs($agente)->post(route('team.members.store'), $this->payload())->assertForbidden();
        $this->assertNull(User::where('email', 'daniel@test.com')->first());
    }
}
