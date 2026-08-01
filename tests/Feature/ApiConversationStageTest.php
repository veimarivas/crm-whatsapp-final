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
 * Espejo de etapa Komo → wacrm: PATCH /api/v1/conversations/{id}/stage.
 * El Komo es la fuente de verdad del pipeline; esta llamada mueve el deal.
 */
class ApiConversationStageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Account $account;
    private Pipeline $pipeline;
    private PipelineStage $negociacion;
    private PipelineStage $cierre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->user->id]);
        $this->user->update(['account_id' => $this->account->id, 'account_role' => 'owner']);

        $this->pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas']);
        foreach ([
            'Nuevo', 'Contactado', 'Propuesta', 'Negociación', 'Cierre',
        ] as $i => $name) {
            $stage = PipelineStage::create(['pipeline_id' => $this->pipeline->id, 'name' => $name, 'color' => '#000', 'position' => $i]);
            if ($name === 'Negociación') {
                $this->negociacion = $stage;
            }
            if ($name === 'Cierre') {
                $this->cierre = $stage;
            }
        }
    }

    private function seedConversationWithDeal(): array
    {
        $contact = Contact::create(['account_id' => $this->account->id, 'name' => 'Juan', 'phone' => '59111111']);
        $conversation = Conversation::create(['account_id' => $this->account->id, 'contact_id' => $contact->id, 'status' => 'open']);
        $deal = Deal::create([
            'account_id' => $this->account->id, 'pipeline_id' => $this->pipeline->id,
            'stage_id' => $this->negociacion->id, 'contact_id' => $contact->id,
            'conversation_id' => $conversation->id, 'title' => 'Deal', 'status' => 'open',
        ]);

        return [$conversation, $deal];
    }

    public function test_mueve_a_la_etapa_por_nombre(): void
    {
        [$conversation, $deal] = $this->seedConversationWithDeal();
        [, $plaintext] = ApiKey::issue($this->account->id, $this->user->id, 'test', ['conversations:write']);

        $this->withToken($plaintext)->patchJson("/api/v1/conversations/{$conversation->id}/stage", [
            'stage_name' => 'Contactado',
            'status' => 'open',
        ])->assertOk()->assertJsonPath('updated', true);

        $this->assertSame($this->pipeline->stages()->where('name', 'Contactado')->first()->id, $deal->fresh()->stage_id);
        $this->assertSame('open', $deal->fresh()->status);
    }

    public function test_estado_terminal_sin_etapa_con_nombre_cae_a_la_ultima(): void
    {
        [$conversation, $deal] = $this->seedConversationWithDeal();
        [, $plaintext] = ApiKey::issue($this->account->id, $this->user->id, 'test', ['conversations:write']);

        $this->withToken($plaintext)->patchJson("/api/v1/conversations/{$conversation->id}/stage", [
            'stage_name' => 'Ganado',
            'status' => 'won',
        ])->assertOk()->assertJsonPath('updated', true);

        $this->assertSame($this->cierre->id, $deal->fresh()->stage_id);
        $this->assertSame('won', $deal->fresh()->status);
    }

    public function test_estado_no_terminal_sin_etapa_con_nombre_conserva_la_etapa(): void
    {
        [$conversation, $deal] = $this->seedConversationWithDeal();
        [, $plaintext] = ApiKey::issue($this->account->id, $this->user->id, 'test', ['conversations:write']);

        $this->withToken($plaintext)->patchJson("/api/v1/conversations/{$conversation->id}/stage", [
            'stage_name' => 'Etapa inexistente',
            'status' => 'open',
        ])->assertOk()->assertJsonPath('updated', false);

        $this->assertSame($this->negociacion->id, $deal->fresh()->stage_id);
        $this->assertSame('open', $deal->fresh()->status);
    }

    public function test_sin_deal_devuelve_ok_sin_cambios(): void
    {
        $contact = Contact::create(['account_id' => $this->account->id, 'name' => 'Ana', 'phone' => '59122222']);
        $conversation = Conversation::create(['account_id' => $this->account->id, 'contact_id' => $contact->id, 'status' => 'open']);
        [, $plaintext] = ApiKey::issue($this->account->id, $this->user->id, 'test', ['conversations:write']);

        $this->withToken($plaintext)->patchJson("/api/v1/conversations/{$conversation->id}/stage", [
            'stage_name' => 'Nuevo',
            'status' => 'open',
        ])->assertOk()->assertJsonPath('updated', false);
    }

    public function test_requiere_scope_conversations_write(): void
    {
        [$conversation] = $this->seedConversationWithDeal();
        [, $plaintext] = ApiKey::issue($this->account->id, $this->user->id, 'test', ['contacts:read']);

        $this->withToken($plaintext)->patchJson("/api/v1/conversations/{$conversation->id}/stage", [
            'stage_name' => 'Nuevo',
            'status' => 'open',
        ])->assertForbidden();
    }
}
