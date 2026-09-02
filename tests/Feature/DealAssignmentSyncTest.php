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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La columna que muestra y filtra /pipelines es deals.assigned_to, pero la
 * asignación se guardaba solo en conversations.assigned_agent_id: el deal
 * quedaba "Sin asignar" aunque el lead tuviera responsable en Komo.
 *
 * Estos tests fijan que ambas columnas se mantienen en espejo por cada
 * camino que cambia la asignación (inbox individual, bulk, API desde Komo)
 * y que el deal nuevo copia la asignación de la conversación.
 */
class DealAssignmentSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $account;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->user->id]);
        $this->user->update(['account_id' => $this->account->id, 'account_role' => 'owner']);

        $this->agent = User::create([
            'name' => 'Agente',
            'email' => 'agente@test.com',
            'password' => bcrypt('password'),
            'account_id' => $this->account->id,
            'account_role' => 'agent',
        ]);
    }

    private function makeConversation(string $phone = '584125550001'): Conversation
    {
        $contact = Contact::create([
            'account_id' => $this->account->id,
            'phone' => $phone,
            'name' => 'Ana',
        ]);

        return Conversation::create([
            'account_id' => $this->account->id,
            'contact_id' => $contact->id,
            'status' => Conversation::STATUS_OPEN,
        ]);
    }

    private function makeDeal(Conversation $conversation): Deal
    {
        $pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas']);
        $stage = PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Nuevo', 'position' => 0, 'stage_type' => 'open']);

        return Deal::create([
            'account_id' => $this->account->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'contact_id' => $conversation->contact_id,
            'conversation_id' => $conversation->id,
            'title' => 'Lead',
            'status' => 'open',
        ]);
    }

    private function apiKey(): string
    {
        [, $plaintext] = ApiKey::issue($this->account->id, $this->user->id, 'test', ['conversations:write']);

        return $plaintext;
    }

    public function test_assign_desde_el_inbox_espeja_la_asignacion_en_el_deal(): void
    {
        $conversation = $this->makeConversation();
        $deal = $this->makeDeal($conversation);

        $this->actingAs($this->user)
            ->patchJson("/inbox/conversations/{$conversation->id}/assign", ['agent_id' => $this->agent->id])
            ->assertOk();

        $this->assertSame($this->agent->id, $deal->fresh()->assigned_to);

        // Quitar la asignación también espeja (vuelve a null en /pipelines).
        $this->actingAs($this->user)
            ->patchJson("/inbox/conversations/{$conversation->id}/assign", ['agent_id' => null])
            ->assertOk();

        $this->assertNull($deal->fresh()->assigned_to);
    }

    public function test_assign_desde_komo_espeja_la_asignacion_en_el_deal(): void
    {
        $conversation = $this->makeConversation();
        $deal = $this->makeDeal($conversation);

        $this->withToken($this->apiKey())
            ->patchJson("/api/v1/conversations/{$conversation->id}/assign", ['email' => $this->agent->email])
            ->assertOk()
            ->assertJsonPath('assigned_agent_id', $this->agent->id);

        $this->assertSame($this->agent->id, $deal->fresh()->assigned_to);
    }

    public function test_bulk_assign_espeja_la_asignacion_en_los_deals(): void
    {
        $c1 = $this->makeConversation('584125550001');
        $c2 = $this->makeConversation('584125550002');
        $deal1 = $this->makeDeal($c1);
        $deal2 = $this->makeDeal($c2);

        $this->actingAs($this->user)
            ->postJson('/inbox/bulk-action', [
                'conversation_ids' => [$c1->id, $c2->id],
                'action' => 'assign',
                'agent_id' => $this->agent->id,
            ])
            ->assertOk();

        $this->assertSame($this->agent->id, $deal1->fresh()->assigned_to);
        $this->assertSame($this->agent->id, $deal2->fresh()->assigned_to);
    }

    public function test_deal_nuevo_copia_la_asignacion_de_la_conversacion(): void
    {
        $pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas', 'is_default' => true]);
        $stage = PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Nuevo', 'position' => 0, 'stage_type' => 'open']);

        $contact = Contact::create(['account_id' => $this->account->id, 'phone' => '584125559999', 'name' => 'Nuevo']);

        // La conversación llega YA asignada (el Komo la crea y asigna antes
        // de que el cliente escriba el primer mensaje).
        $conversation = Conversation::create([
            'account_id' => $this->account->id,
            'contact_id' => $contact->id,
            'status' => Conversation::STATUS_OPEN,
            'assigned_agent_id' => $this->agent->id,
        ]);

        // createLeadDeal es privado y solo se dispara en el flujo de inbound;
        // se invoca directo para fijar que copia assigned_agent_id al deal.
        // F0/T0.2b: vive en el `Ingestor` — se mudó ahí junto con todo lo que
        // no era específico de Meta. El comportamiento no cambió.
        $method = new \ReflectionMethod(\App\Services\Channels\Ingestor::class, 'createLeadDeal');
        $method->setAccessible(true);
        $method->invoke(app(\App\Services\Channels\Ingestor::class), $contact, $conversation);

        $deal = Deal::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($deal, 'El lead nuevo debe crear un deal.');
        $this->assertSame($stage->id, $deal->stage_id, 'El deal cae en la primera etapa abierta.');
        $this->assertSame($this->agent->id, $deal->assigned_to);
    }

    public function test_comando_repara_los_deals_existentes(): void
    {
        // Deals creados antes del fix: la conversación tiene agente asignado
        // pero el deal quedó con assigned_to = null (el bug de /pipelines).
        $c1 = $this->makeConversation('584125550011');
        $c1->update(['assigned_agent_id' => $this->agent->id]);
        $deal1 = $this->makeDeal($c1);

        $c2 = $this->makeConversation('584125550012');
        $deal2 = $this->makeDeal($c2);

        // El deal ya correcto no debe tocarse.
        $this->actingAs($this->user)
            ->patchJson("/inbox/conversations/{$c2->id}/assign", ['agent_id' => $this->agent->id])
            ->assertOk();

        $this->assertSame(2, Deal::count());

        $this->artisan('wacrm:sync-deal-assignments')
            ->expectsOutputToContain('1 deals actualizados de 2 revisados')
            ->assertExitCode(0);

        $this->assertSame($this->agent->id, $deal1->fresh()->assigned_to);
        $this->assertSame($this->agent->id, $deal2->fresh()->assigned_to);
    }
}
