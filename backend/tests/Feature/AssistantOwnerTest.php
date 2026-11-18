<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Agency;
use App\Services\CapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

/**
 * @group assistant
 */
class AssistantOwnerTest extends TestCase
{
    use RefreshDatabase;

    protected $owner;
    protected $agency;

    protected function setUp(): void
    {
        parent::setUp();
        // Fresh DB
        Artisan::call('migrate:fresh');
        // Create agency and owner
        $this->agency = Agency::factory()->create(['status' => 'active']);
        $this->owner = User::factory()->create([
            'agency_id' => $this->agency->id,
            'role' => 'agency_owner',
            'is_active' => true,
        ]);
    }

    protected function actingAsOwner()
    {
        return $this->actingAs($this->owner, 'api');
    }

    public function test_question_list_is_filtered_by_role()
    {
        $response = $this->actingAsOwner()
            ->getJson('/api/assistant/questions')
            ->assertStatus(200)
            ->assertJsonStructure([
                'categories' => [
                    '*' => [
                        'name',
                        'questions' => [
                            '*' => [
                                'key', 'label', 'description', 'params'
                            ]
                        ]
                    ]
                ]
            ]);
        $categories = $response->json('categories');
        $found = false;
        foreach ($categories as $cat) {
            foreach ($cat['questions'] as $q) {
                if (isset($q['key']) && str_contains($q['key'], 'owner_')) {
                    $found = true;
                    break 2;
                }
            }
        }
        $this->assertTrue($found, 'Owner specific questions should be present');
    }

    public function test_agency_overview_handler_returns_expected_structure()
    {
        $payload = ['question_key' => 'owner_agency_overview'];
        $response = $this->actingAsOwner()
            ->postJson('/api/assistant/ask', $payload)
            ->assertStatus(200)
            ->assertJsonStructure([
                'question_key', 'title', 'generated_at', 'blocks', 'empty_message'
            ]);
        $blocks = $response->json('blocks');
        $hasStats = collect($blocks)->contains(fn($b) => $b['type'] === 'stats');
        $this->assertTrue($hasStats, 'Agency overview should include a stats block');
    }

    public function test_employee_capacity_uses_capacity_service()
    {
        // Create work items
        \App\Models\WorkItem::factory()->count(3)->create([
            'agency_id' => $this->agency->id,
            'status' => 'scheduled',
            'due_date' => now()->addDays(2),
            'hours_estimated' => 4,
        ]);
        // Employee with active assignment
        $employee = User::factory()->create([
            'agency_id' => null,
            'role' => 'employee',
            'is_active' => true,
        ]);
        \App\Models\EmployeeAssignment::factory()->create([
            'employee_id' => $employee->id,
            'agency_id' => $this->agency->id,
            'status' => 'active',
            'hours_assigned' => 6,
        ]);
        $payload = [
            'question_key' => 'owner_employee_capacity',
            'params' => ['employee_id' => $employee->id]
        ];
        $response = $this->actingAsOwner()
            ->postJson('/api/assistant/ask', $payload)
            ->assertStatus(200)
            ->assertJsonStructure([
                'question_key', 'title', 'generated_at', 'blocks'
            ]);
        $capacityService = app(CapacityService::class);
        $capacity = $capacityService->getEmployeeCapacity($employee, $this->agency);
        $blocks = $response->json('blocks');
        $progress = collect($blocks)->firstWhere('type', 'progress');
        $this->assertNotNull($progress, 'Progress block should be present');
        $this->assertEquals($capacity['used'], $progress['items'][0]['used'] ?? null);
    }
}
