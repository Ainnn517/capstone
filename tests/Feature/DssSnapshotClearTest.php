<?php

namespace Tests\Feature;

use App\Models\DssAnalyticsRecord;
use App\Models\User;
use Tests\TestCase;

class DssSnapshotClearTest extends TestCase
{
    public function test_administrator_can_clear_all_snapshots_and_it_is_audited(): void
    {
        $administrator = User::factory()->admin()->create();
        foreach (range(1, 12) as $_) {
            DssAnalyticsRecord::create(['created_by' => $administrator->user_id, 'source_report_count' => 0, 'recommendations' => []]);
        }

        $this->actingAs($administrator)
            ->deleteJson('/api/analytics/dss/snapshots', ['confirmed' => true])
            ->assertOk()
            ->assertJsonPath('cleared', 12);

        $this->assertSame(0, DssAnalyticsRecord::count());
        $this->assertDatabaseHas('ADMIN_AUDIT_LOG', ['actor_user_id' => $administrator->user_id, 'action' => 'clear_dss_snapshots']);
        // Live recommendations still work afterwards.
        $this->getJson('/api/analytics/dss')->assertOk()->assertJsonCount(0, 'history');
    }

    public function test_clearing_requires_explicit_confirmation(): void
    {
        $administrator = User::factory()->admin()->create();
        DssAnalyticsRecord::create(['created_by' => $administrator->user_id, 'source_report_count' => 0, 'recommendations' => []]);

        $this->actingAs($administrator)->deleteJson('/api/analytics/dss/snapshots')->assertUnprocessable();
        $this->deleteJson('/api/analytics/dss/snapshots', ['confirmed' => 'yes'])->assertUnprocessable();

        $this->assertSame(1, DssAnalyticsRecord::count());
    }

    public function test_only_administrators_can_clear_snapshots(): void
    {
        $resident = User::factory()->create();
        DssAnalyticsRecord::create(['created_by' => null, 'source_report_count' => 0, 'recommendations' => []]);

        $this->deleteJson('/api/analytics/dss/snapshots', ['confirmed' => true])->assertUnauthorized();
        $this->actingAs($resident)->deleteJson('/api/analytics/dss/snapshots', ['confirmed' => true])->assertForbidden();

        $this->assertSame(1, DssAnalyticsRecord::count());
    }

    public function test_snapshot_history_includes_who_saved_it_and_its_recommendations(): void
    {
        $administrator = User::factory()->admin()->create(['full_name' => 'Barangay Secretary']);

        $this->actingAs($administrator)->postJson('/api/analytics/dss/snapshots')->assertCreated();

        $this->getJson('/api/analytics/dss')
            ->assertOk()
            ->assertJsonPath('history.0.savedBy', 'Barangay Secretary')
            ->assertJsonStructure(['history' => [['id', 'generatedAt', 'sourceReportCount', 'savedBy', 'recommendations']]]);
    }
}
