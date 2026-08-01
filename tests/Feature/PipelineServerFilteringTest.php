<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PipelineServerFilteringTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Vendedor',
            'email' => 'ventas@test.com',
            'password' => bcrypt('password'),
        ]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $this->user->id]);
        $this->user->update(['account_id' => $this->account->id, 'account_role' => 'owner']);
        $this->user->refresh();
    }

    private function seedPipeline(): array
    {
        $pipeline = Pipeline::create(['account_id' => $this->account->id, 'name' => 'P']);
        $stageA = PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Nuevo', 'color' => '#000', 'position' => 0]);
        $stageB = PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Cierre', 'color' => '#000', 'position' => 1]);

        $contact = Contact::create(['account_id' => $this->account->id, 'name' => 'Juan', 'phone' => '59111111']);
        Deal::create([
            'account_id' => $this->account->id, 'pipeline_id' => $pipeline->id, 'stage_id' => $stageA->id,
            'contact_id' => $contact->id, 'assigned_to' => $this->user->id, 'title' => 'Deal A', 'value' => 100, 'status' => 'open',
        ]);
        Deal::create([
            'account_id' => $this->account->id, 'pipeline_id' => $pipeline->id, 'stage_id' => $stageB->id,
            'title' => 'Deal B', 'value' => 200, 'status' => 'won',
        ]);

        return [$pipeline, $stageA, $stageB, $contact];
    }

    public function test_filters_by_responsible(): void
    {
        [$pipeline] = $this->seedPipeline();

        $response = $this->actingAs($this->user)->get('/pipelines?responsible='.$this->user->id);
        $response->assertInertia(fn ($page) => $page
            ->component('Pipelines/Index')
            ->has('deals', 1)
            ->where('deals.0.title', 'Deal A'));
    }

    public function test_filters_unassigned_responsible(): void
    {
        $this->seedPipeline();

        $response = $this->actingAs($this->user)->get('/pipelines?responsible=none');
        $response->assertInertia(fn ($page) => $page
            ->component('Pipelines/Index')
            ->has('deals', 1)
            ->where('deals.0.title', 'Deal B'));
    }

    public function test_filters_by_status(): void
    {
        $this->seedPipeline();

        $response = $this->actingAs($this->user)->get('/pipelines?status=won');
        $response->assertInertia(fn ($page) => $page
            ->component('Pipelines/Index')
            ->has('deals', 1)
            ->where('deals.0.title', 'Deal B'));
    }

    public function test_combined_responsible_and_status_filters(): void
    {
        $this->seedPipeline();

        $response = $this->actingAs($this->user)->get('/pipelines?responsible='.$this->user->id.'&status=won');
        $response->assertInertia(fn ($page) => $page->component('Pipelines/Index')->has('deals', 0));
    }

    public function test_search_by_title_and_contact(): void
    {
        $this->seedPipeline();

        $byTitle = $this->actingAs($this->user)->get('/pipelines?q=Deal A');
        $byTitle->assertInertia(fn ($page) => $page->component('Pipelines/Index')->has('deals', 1)->where('deals.0.title', 'Deal A'));

        $byContact = $this->actingAs($this->user)->get('/pipelines?q=Juan');
        $byContact->assertInertia(fn ($page) => $page->component('Pipelines/Index')->has('deals', 1)->where('deals.0.title', 'Deal A'));
    }
}
