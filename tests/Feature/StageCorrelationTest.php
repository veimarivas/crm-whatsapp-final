<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApiKey;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * D5 — la etapa se correlaciona por uuid, no por nombre, y la estructura del
 * pipeline sincronizado la administra el Komo.
 *
 * El espejo de etapa funciona en las dos direcciones y hasta acá las dos
 * correspondían la etapa por su NOMBRE: dos homónimas en pipelines distintos
 * podían aterrizar el movimiento en la columna equivocada, sin error y sin
 * rastro. El uuid ya viajaba en `pipelines/sync` y se guarda como
 * `external_id` — solo faltaba usarlo.
 */
class StageCorrelationTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private User $owner;

    private Pipeline $pipeline;

    /** @var array<int, PipelineStage> */
    private array $stages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->owner->id]);
        $this->owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        // Pipeline sincronizado desde Komo: tiene `external_id`.
        $this->pipeline = Pipeline::create([
            'account_id' => $this->account->id,
            'name' => 'Ventas',
            'external_id' => (string) Str::uuid(),
        ]);

        foreach ([['Nuevo', 'open'], ['Contactado', 'open']] as $i => [$name, $type]) {
            $this->stages[$i] = PipelineStage::create([
                'pipeline_id' => $this->pipeline->id,
                'name' => $name,
                'stage_type' => $type,
                'position' => $i,
                'external_id' => (string) Str::uuid(),
            ]);
        }
    }

    private function deal(): Deal
    {
        $contact = Contact::create(['account_id' => $this->account->id, 'phone' => '584125550001', 'name' => 'Ana']);
        $conversation = Conversation::create([
            'account_id' => $this->account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        return Deal::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->stages[0]->id,
            'contact_id' => $contact->id,
            'conversation_id' => $conversation->id,
            'title' => 'Ana',
        ]);
    }

    public function test_el_webhook_de_movimiento_lleva_el_uuid_de_la_etapa(): void
    {
        WebhookEndpoint::create([
            'account_id' => $this->account->id,
            'url' => 'https://komo.test/webhooks/wacrm/x',
            'secret' => 'whsec_s',
            'events' => ['deal.stage_changed'],
            'is_active' => true,
        ]);

        Http::fake();

        $deal = $this->deal();

        $this->actingAs($this->owner)
            ->patch("/deals/{$deal->id}", ['stage_id' => $this->stages[1]->id]);

        Http::assertSent(function ($request) {
            $data = $request->data()['data'] ?? [];

            // El uuid de Komo, y el nombre igual: el contrato es aditivo y un
            // Komo sin desplegar tiene que seguir funcionando.
            return ($data['stage_external_id'] ?? null) === $this->stages[1]->external_id
                && ($data['stage_name'] ?? null) === 'Contactado';
        });
    }

    public function test_la_api_mueve_por_uuid_aunque_el_nombre_diga_otra_cosa(): void
    {
        [, $token] = ApiKey::issue($this->account->id, $this->owner->id, 'komo', ['conversations:write']);

        $deal = $this->deal();

        $this->withToken($token)
            ->patchJson("/api/v1/conversations/{$deal->conversation_id}/stage", [
                'stage_external_id' => $this->stages[1]->external_id,
                'stage_name' => 'Nuevo',
                'status' => 'open',
            ])
            ->assertOk()
            ->assertJsonPath('updated', true);

        $this->assertSame($this->stages[1]->id, $deal->fresh()->stage_id);
    }

    public function test_la_api_sin_uuid_sigue_moviendo_por_nombre(): void
    {
        [, $token] = ApiKey::issue($this->account->id, $this->owner->id, 'komo', ['conversations:write']);

        $deal = $this->deal();

        // Exactamente el payload de antes de D5: un Komo todavía sin desplegar.
        $this->withToken($token)
            ->patchJson("/api/v1/conversations/{$deal->conversation_id}/stage", [
                'stage_name' => 'Contactado',
                'status' => 'open',
            ])
            ->assertOk();

        $this->assertSame($this->stages[1]->id, $deal->fresh()->stage_id);
    }

    public function test_la_estructura_de_un_pipeline_sincronizado_no_se_edita_aca(): void
    {
        // Renombrar o borrar acá NO fallaba antes: el próximo `pipelines/sync`
        // lo revertía en silencio. Lo que cambia es que ahora se dice.
        $this->actingAs($this->owner)->from('/pipelines')
            ->patch("/pipelines/{$this->pipeline->id}", ['name' => 'Otro nombre'])
            ->assertSessionHasErrors('name');

        $this->actingAs($this->owner)->from('/pipelines')
            ->post("/pipelines/{$this->pipeline->id}/stages", ['name' => 'Etapa nueva'])
            ->assertSessionHasErrors('name');

        $this->actingAs($this->owner)->from('/pipelines')
            ->patch("/stages/{$this->stages[0]->id}", ['name' => 'Renombrada'])
            ->assertSessionHasErrors('name');

        $this->assertSame('Ventas', $this->pipeline->fresh()->name);
        $this->assertSame('Nuevo', $this->stages[0]->fresh()->name);
        $this->assertSame(2, $this->pipeline->stages()->count());
    }

    public function test_mover_un_deal_de_columna_sigue_permitido(): void
    {
        Http::fake();

        $deal = $this->deal();

        // El corte es sobre la ESTRUCTURA, no sobre la operación: arrastrar una
        // tarjeta es el gesto del asesor y se espeja bien en las dos
        // direcciones. Bloquearlo habría roto un flujo que funciona.
        $this->actingAs($this->owner)
            ->patch("/deals/{$deal->id}", ['stage_id' => $this->stages[1]->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->stages[1]->id, $deal->fresh()->stage_id);
    }

    public function test_un_pipeline_local_se_sigue_editando(): void
    {
        // Sin `external_id`: lo creó este proyecto y Komo no lo conoce.
        $local = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Solo de acá']);

        $this->actingAs($this->owner)->from('/pipelines')
            ->patch("/pipelines/{$local->id}", ['name' => 'Corregido'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Corregido', $local->fresh()->name);
    }
}
