<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\QuickReply;
use App\Models\User;
use App\Services\WhatsApp\SuggestedQuickReplies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Carga del pack de plantillas sugeridas para inscripciones. */
class SuggestedQuickRepliesTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private User $agente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Instituto', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        $this->agente = User::create([
            'name' => 'Daniel', 'email' => 'daniel@test.com', 'password' => bcrypt('password'),
            'account_id' => $this->account->id, 'account_role' => User::ROLE_AGENT,
        ]);
    }

    public function test_carga_el_pack_como_plantillas_compartidas(): void
    {
        $this->actingAs($this->owner)->post(route('quick-replies.load-suggested'))->assertRedirect();

        $total = count(SuggestedQuickReplies::all());

        $this->assertSame($total, QuickReply::count());
        // user_id null = visible para todo el equipo.
        $this->assertSame($total, QuickReply::whereNull('user_id')->count());
    }

    public function test_volver_a_cargarlo_no_duplica(): void
    {
        $this->actingAs($this->owner)->post(route('quick-replies.load-suggested'));
        $this->actingAs($this->owner)->post(route('quick-replies.load-suggested'));

        $this->assertSame(count(SuggestedQuickReplies::all()), QuickReply::count());
    }

    public function test_respeta_una_plantilla_existente_con_el_mismo_atajo(): void
    {
        QuickReply::create([
            'account_id' => $this->account->id,
            'user_id' => $this->owner->id,
            'shortcut' => 'promo',
            'content' => 'Mi version propia',
        ]);

        $this->actingAs($this->owner)->post(route('quick-replies.load-suggested'));

        // La suya no se pisa: sigue siendo la de él, no la sugerida.
        $this->assertSame('Mi version propia', QuickReply::where('shortcut', 'promo')->sole()->content);
    }

    public function test_solo_las_que_faltan_se_ofrecen_en_la_pantalla(): void
    {
        $this->actingAs($this->owner)->post(route('quick-replies.load-suggested'));

        $suggested = $this->actingAs($this->owner)->get(route('quick-replies.index'))
            ->viewData('page')['props']['suggested'];

        $this->assertSame([], $suggested, 'Ya están todas cargadas.');
    }

    public function test_el_agente_no_puede_cargar_plantillas_del_equipo(): void
    {
        $this->actingAs($this->agente)->post(route('quick-replies.load-suggested'))->assertForbidden();

        $this->assertSame(0, QuickReply::count());
    }

    public function test_el_listado_no_revienta_con_plantillas_cargadas(): void
    {
        // Regresion: QuickReply no tenia la relacion `user` que el listado
        // carga con with(). Con cero plantillas Eloquent ni la resuelve, pero
        // con una sola la pantalla devolvia 500.
        QuickReply::create([
            'account_id' => $this->account->id,
            'user_id' => $this->owner->id,
            'shortcut' => 'mia',
            'content' => 'Hola',
        ]);

        $this->actingAs($this->owner)->get(route('quick-replies.index'))->assertOk();
    }

    public function test_las_plantillas_traen_la_variable_del_nombre(): void
    {
        // Si el saludo no personaliza, el pack pierde la mitad de su gracia.
        $conNombre = collect(SuggestedQuickReplies::all())
            ->filter(fn ($s) => str_contains($s['content'], '{name}'))
            ->count();

        $this->assertGreaterThan(10, $conNombre);
    }
}
