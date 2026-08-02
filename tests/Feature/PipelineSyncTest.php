<?php

namespace Tests\Feature;

use App\Jobs\DeliverWebhookJob;
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
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sincronización de la estructura de pipelines Komo → wacrm y del espejo
 * inverso de movimientos (drag & drop en /pipelines → lead del Komo).
 */
class PipelineSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->user->id]);
        $this->user->update(['account_id' => $this->account->id, 'account_role' => 'owner']);
        $this->user->refresh();
    }

    private function apiKey(): string
    {
        [, $plaintext] = ApiKey::issue($this->account->id, $this->user->id, 'test', ['conversations:write']);

        return $plaintext;
    }

    private function syncPayload(): array
    {
        return [
            'pipelines' => [
                [
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'name' => 'Ventas',
                    'is_default' => true,
                    'stages' => [
                        ['id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Nuevo', 'position' => 1, 'color' => '#0ea5e9', 'stage_type' => 'open'],
                        ['id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Contactado', 'position' => 2, 'color' => '#8b5cf6', 'stage_type' => 'open'],
                        ['id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Ganado', 'position' => 100, 'color' => '#10b981', 'stage_type' => 'won'],
                        ['id' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Perdido', 'position' => 101, 'color' => '#ef4444', 'stage_type' => 'lost'],
                    ],
                ],
            ],
        ];
    }

    public function test_sync_crea_pipeline_y_etapas_desde_komo(): void
    {
        $payload = $this->syncPayload();
        $pipe = $payload['pipelines'][0];

        $this->withToken($this->apiKey())
            ->postJson('/api/v1/pipelines/sync', $payload)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $pipeline = Pipeline::forAccount($this->account->id)->where('external_id', $pipe['id'])->first();
        $this->assertNotNull($pipeline);
        $this->assertSame('Ventas', $pipeline->name);
        $this->assertTrue($pipeline->is_default);
        $this->assertSame(4, $pipeline->stages()->count());

        $won = $pipeline->stages()->where('name', 'Ganado')->first();
        $this->assertSame('won', $won->stage_type);
    }

    public function test_sync_actualiza_etapas_existentes_por_nombre(): void
    {
        // Pipeline local sembrado antes de la integración (sin external_id).
        $local = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas']);
        $nuevo = PipelineStage::create(['pipeline_id' => $local->id, 'name' => 'Nuevo', 'position' => 0]);

        $payload = $this->syncPayload();
        $pipe = $payload['pipelines'][0];

        $this->withToken($this->apiKey())->postJson('/api/v1/pipelines/sync', $payload)->assertOk();

        $pipeline = $local->fresh();
        $this->assertSame($pipe['id'], $pipeline->external_id);

        // "Nuevo" se absorbe por nombre y queda vinculado al external_id del Komo.
        $matched = $pipeline->stages()->where('name', 'Nuevo')->first();
        $this->assertSame($nuevo->id, $matched->id);
        $this->assertSame($pipe['stages'][0]['id'], $matched->external_id);
    }

    public function test_sync_elimina_etapas_extra_y_mueve_sus_deals(): void
    {
        $local = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas']);
        $nuevo = PipelineStage::create(['pipeline_id' => $local->id, 'name' => 'Nuevo', 'position' => 0, 'stage_type' => 'open']);
        $extra = PipelineStage::create(['pipeline_id' => $local->id, 'name' => 'Propuesta', 'position' => 1, 'stage_type' => 'open']);

        $contact = Contact::create(['account_id' => $this->account->id, 'name' => 'Juan', 'phone' => '59111111']);
        Deal::create([
            'account_id' => $this->account->id, 'pipeline_id' => $local->id, 'stage_id' => $extra->id,
            'contact_id' => $contact->id, 'title' => 'Deal en Propuesta', 'status' => 'open',
        ]);

        $this->withToken($this->apiKey())->postJson('/api/v1/pipelines/sync', $this->syncPayload())->assertOk();

        // "Propuesta" no existe en Komo → desaparece y el deal va a "Nuevo".
        $this->assertNull($local->fresh()->stages()->where('name', 'Propuesta')->first());
        $deal = Deal::forAccount($this->account->id)->first();
        $this->assertSame($nuevo->fresh()->id, $deal->fresh()->stage_id);
    }

    public function test_sync_elimina_pipeline_que_komo_ya_no_reporta(): void
    {
        // Pipeline sincronizado que Komo borró: deja de venir en el payload.
        $pipeId = (string) \Illuminate\Support\Str::uuid();
        $sync = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Viejo', 'external_id' => $pipeId]);
        PipelineStage::create(['pipeline_id' => $sync->id, 'name' => 'Nuevo', 'position' => 0]);

        $payload = $this->syncPayload();

        $this->withToken($this->apiKey())->postJson('/api/v1/pipelines/sync', $payload)->assertOk();

        $this->assertNull(Pipeline::forAccount($this->account->id)->where('external_id', $pipeId)->first());
        $this->assertSame(1, Pipeline::forAccount($this->account->id)->count());
    }

    public function test_requiere_scope_conversations_write(): void
    {
        [, $key] = ApiKey::issue($this->account->id, $this->user->id, 'test', ['contacts:read']);

        $this->withToken($key)->postJson('/api/v1/pipelines/sync', $this->syncPayload())->assertForbidden();
    }

    public function test_drag_y_drop_mueve_estado_y_espeja_webhook(): void
    {
        Queue::fake();

        $pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas', 'external_id' => (string) \Illuminate\Support\Str::uuid()]);
        $nuevo = PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Nuevo', 'position' => 0, 'stage_type' => 'open']);
        $ganado = PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Ganado', 'position' => 100, 'stage_type' => 'won']);

        $contact = Contact::create(['account_id' => $this->account->id, 'name' => 'Ana', 'phone' => '59122222']);
        $conversation = Conversation::create(['account_id' => $this->account->id, 'contact_id' => $contact->id]);
        $deal = Deal::create([
            'account_id' => $this->account->id, 'pipeline_id' => $pipeline->id, 'stage_id' => $nuevo->id,
            'contact_id' => $contact->id, 'conversation_id' => $conversation->id, 'title' => 'Deal', 'status' => 'open',
        ]);

        WebhookEndpoint::create([
            'account_id' => $this->account->id,
            'url' => 'https://komo.test/webhooks/wacrm/x',
            'secret' => 'whsec_test',
            'events' => ['deal.stage_changed'],
        ]);

        $this->actingAs($this->user)
            ->patch(route('deals.update', $deal), ['stage_id' => $ganado->id])
            ->assertRedirect();

        $deal->refresh();
        $this->assertSame($ganado->id, $deal->stage_id);
        $this->assertSame('won', $deal->status);

        Queue::assertPushed(DeliverWebhookJob::class, fn ($job) => $job->event === 'deal.stage_changed'
            && $job->data['conversation_id'] === $conversation->id
            && $job->data['stage_name'] === 'Ganado'
            && $job->data['status'] === 'won');
    }

    public function test_sync_suscribe_el_webhook_a_deal_stage_changed(): void
    {
        WebhookEndpoint::create([
            'account_id' => $this->account->id,
            'url' => 'https://komo.test/webhooks/wacrm/x',
            'secret' => 'whsec_test',
            'events' => ['message.received'],
        ]);

        $this->withToken($this->apiKey())->postJson('/api/v1/pipelines/sync', $this->syncPayload())->assertOk();

        $endpoint = WebhookEndpoint::first();
        $this->assertTrue(in_array('deal.stage_changed', $endpoint->events, true));
    }

    public function test_deals_se_ordenan_por_actividad_reciente(): void
    {
        $pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas']);
        $stage = PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Nuevo', 'position' => 0, 'stage_type' => 'open']);

        $mk = function (string $phone, string $name, ?string $lastMessageAt = null, ?string $createdAt = null) use ($pipeline, $stage) {
            $contact = Contact::create(['account_id' => $this->account->id, 'name' => $name, 'phone' => $phone]);
            $conversation = Conversation::create([
                'account_id' => $this->account->id,
                'contact_id' => $contact->id,
                'last_message_at' => $lastMessageAt,
                'last_message_text' => 'Hola',
            ]);

            $deal = Deal::create([
                'account_id' => $this->account->id,
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'contact_id' => $contact->id,
                'conversation_id' => $conversation->id,
                'title' => "Deal $name",
                'status' => 'open',
            ]);

            if ($createdAt) {
                $deal->forceFill(['created_at' => $createdAt])->save();
            }

            return $deal;
        };

        // Conversación con mensaje AHORA: el más reciente, aunque el deal sea viejo.
        $recent = $mk('59111111', 'Reciente', now()->toDateTimeString(), now()->subDays(10)->toDateTimeString());
        // Sin conversación: cae a su fecha de creación (ayer).
        $staleContact = Contact::create(['account_id' => $this->account->id, 'name' => 'Antiguo', 'phone' => '59122222']);
        $fallback = Deal::create([
            'account_id' => $this->account->id, 'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id,
            'contact_id' => $staleContact->id, 'title' => 'Sin conversación', 'status' => 'open',
        ]);
        $fallback->forceFill(['created_at' => now()->subDay()])->save();
        // Conversación con mensaje hace 3 días, aunque el deal se creó hoy.
        $old = $mk('59133333', 'Viejo', now()->subDays(3)->toDateTimeString(), now()->toDateTimeString());

        $this->actingAs($this->user)
            ->get(route('pipelines.index', ['pipeline' => $pipeline->id]))
            ->assertInertia(fn ($page) => $page
                ->component('Pipelines/Index')
                ->has('deals', 3)
                ->where('deals.0.id', $recent->id)
                ->where('deals.0.last_message_text', 'Hola')
                ->where('deals.1.id', $fallback->id)
                ->where('deals.2.id', $old->id));
    }

    public function test_etapa_terminal_de_komo_cae_a_la_etapa_con_stage_type(): void
    {
        // Pipeline que ya llegó sincronizado con la etapa terminal "Ganado".
        $pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'Ventas']);
        $nuevo = PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Nuevo', 'position' => 0, 'stage_type' => 'open']);
        PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Ganado', 'position' => 100, 'stage_type' => 'won']);

        $contact = Contact::create(['account_id' => $this->account->id, 'name' => 'Ana', 'phone' => '59122222']);
        $conversation = Conversation::create(['account_id' => $this->account->id, 'contact_id' => $contact->id]);
        $deal = Deal::create([
            'account_id' => $this->account->id, 'pipeline_id' => $pipeline->id, 'stage_id' => $nuevo->id,
            'contact_id' => $contact->id, 'conversation_id' => $conversation->id, 'title' => 'Deal', 'status' => 'open',
        ]);

        $this->withToken($this->apiKey())->patchJson("/api/v1/conversations/{$conversation->id}/stage", [
            'stage_name' => 'Ganado',
            'status' => 'won',
        ])->assertOk()->assertJsonPath('updated', true);

        $ganado = $pipeline->stages()->where('name', 'Ganado')->first();
        $this->assertSame($ganado->id, $deal->fresh()->stage_id);
        $this->assertSame('won', $deal->fresh()->status);
    }
}
