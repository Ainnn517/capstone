<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\IssueReport;
use App\Models\Location;
use App\Models\Team;
use App\Models\User;
use Tests\TestCase;

class ReferenceDataTest extends TestCase
{
    public function test_administrator_can_create_reference_data_and_changes_are_audited(): void
    {
        $administrator = User::factory()->admin()->create();

        $this->actingAs($administrator)
            ->postJson('/api/reference-data/categories', [
                'name' => 'Flooding',
                'description' => 'Flood and water accumulation.',
                'severity_weight' => 4,
            ])
            ->assertOk();
        $this->postJson('/api/reference-data/teams', ['name' => 'Flood Response Team'])->assertOk();
        $this->postJson('/api/reference-data/locations', [
            'name' => 'River Road',
            'area_name' => 'North Village',
            'is_active' => true,
        ])->assertOk();

        $this->getJson('/api/reference-data')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Flooding', 'severity_weight' => 4])
            ->assertJsonFragment(['name' => 'Flood Response Team'])
            ->assertJsonFragment(['name' => 'River Road', 'area_name' => 'North Village', 'is_active' => true]);
        $this->assertDatabaseCount('ADMIN_AUDIT_LOG', 3);
    }

    public function test_resident_cannot_modify_reference_data(): void
    {
        $resident = User::factory()->create();
        $this->actingAs($resident)
            ->postJson('/api/reference-data/teams', ['name' => 'Unauthorized Team'])
            ->assertForbidden();
    }

    public function test_locations_used_by_reports_cannot_be_deleted_but_can_be_deactivated(): void
    {
        $administrator = User::factory()->admin()->create();
        $resident = User::factory()->create();
        $category = Category::create(['name' => 'Road Damage', 'severity_weight' => 3]);
        $team = Team::create(['name' => 'Field Team']);
        $location = Location::create([
            'name' => 'Laurel Street',
            'area_name' => 'Village',
            'is_active' => true,
        ]);
        IssueReport::create([
            'tracking_code' => 'BR-'.strtoupper(bin2hex(random_bytes(6))),
            'user_id' => $resident->user_id,
            'category_id' => $category->category_id,
            'team_id' => $team->team_id,
            'description' => 'A report on the street.',
            'location_id' => $location->location_id,
            'status' => 'Submitted',
            'date_submitted' => now(),
        ]);

        $this->actingAs($administrator)
            ->deleteJson('/api/reference-data/locations/'.$location->location_id)
            ->assertStatus(409);
        $this->putJson('/api/reference-data/locations/'.$location->location_id, [
            'name' => $location->name,
            'area_name' => $location->area_name,
            'is_active' => false,
        ])->assertOk();
        // Administrators still see the location, now marked inactive.
        $this->getJson('/api/reference-data')->assertJsonFragment([
            'location_id' => $location->location_id,
            'is_active' => false,
        ]);
        // Residents no longer see it.
        $this->actingAs($resident)->getJson('/api/reference-data')->assertJsonMissing([
            'location_id' => $location->location_id,
        ]);
    }
}
