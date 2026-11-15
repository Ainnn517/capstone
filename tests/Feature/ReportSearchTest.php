<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\IssueReport;
use App\Models\Location;
use App\Models\User;
use Tests\TestCase;

class ReportSearchTest extends TestCase
{
    public function test_admin_can_search_reports_by_code_text_landmark_and_resident(): void
    {
        $maria = User::factory()->create(['full_name' => 'Maria Santos', 'username' => 'msantos']);
        $jose = User::factory()->create(['full_name' => 'Jose Cruz', 'username' => 'jcruz']);
        $this->report($maria, 'BR-AAA-1', 'Broken streetlight by the chapel', 'Near chapel');
        $this->report($jose, 'BR-BBB-2', 'Clogged drainage canal', 'Corner store');
        $archived = $this->report($jose, 'BR-CCC-3', 'Garbage not collected 100% of the week', null);
        $archived->update(['archived_at' => now()]);

        $admin = User::factory()->admin()->create();
        $codes = fn (string $url): array => collect($this->actingAs($admin)->getJson($url)->assertOk()->json('reports'))
            ->pluck('tracking')->sort()->values()->all();

        $this->assertSame(['BR-AAA-1'], $codes('/api/reports?view=active&q=aaa'));
        $this->assertSame(['BR-BBB-2'], $codes('/api/reports?view=active&q=drainage'));
        $this->assertSame(['BR-BBB-2'], $codes('/api/reports?view=active&q=corner'));
        $this->assertSame(['BR-AAA-1'], $codes('/api/reports?view=active&q=Santos'));
        $this->assertSame(['BR-BBB-2'], $codes('/api/reports?view=active&q=jcruz'));
        $this->assertSame(['BR-AAA-1', 'BR-BBB-2'], $codes('/api/reports?view=active'));
        // Archived reports are searched in their own list, and % is matched literally.
        $this->assertSame([], $codes('/api/reports?view=active&q=garbage'));
        $this->assertSame(['BR-CCC-3'], $codes('/api/reports/archived?q=100%25'));
        $this->assertSame([], $codes('/api/reports/archived?q=5%25'));

        $this->actingAs($admin)->getJson('/api/reports?q='.str_repeat('x', 101))->assertStatus(422);
    }

    private function report(User $resident, string $code, string $description, ?string $landmark): IssueReport
    {
        $category = Category::firstOrCreate(['name' => 'Others'], ['severity_weight' => 1]);
        $location = Location::firstOrCreate(['name' => 'Main Street', 'area_name' => 'Barangay One']);

        return IssueReport::create([
            'tracking_code' => $code,
            'user_id' => $resident->user_id,
            'category_id' => $category->category_id,
            'description' => $description,
            'location_id' => $location->location_id,
            'landmark' => $landmark,
            'status' => 'Submitted',
        ]);
    }
}
