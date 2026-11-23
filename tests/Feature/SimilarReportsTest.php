<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\IssueReport;
use App\Models\Location;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SimilarReportsTest extends TestCase
{
    private Location $lawaan;

    private Category $drainage;

    private User $neighbour;

    private User $resident;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lawaan = Location::create(['name' => 'Lawaan Street', 'area_name' => 'Phase 2']);
        $this->drainage = Category::create(['name' => 'Clogged Drainage', 'severity_weight' => 3]);
        Category::create(['name' => 'Public Safety', 'severity_weight' => 5]);
        $this->neighbour = User::factory()->create();
        $this->resident = User::factory()->create();
    }

    private function report(User $by, string $status, ?Category $category = null, int $daysAgo = 2): IssueReport
    {
        return IssueReport::create([
            'tracking_code' => 'BR-SIM-'.strtoupper(bin2hex(random_bytes(3))),
            'user_id' => $by->user_id,
            'category_id' => ($category ?? $this->drainage)->category_id,
            'description' => 'Secret words from the neighbour.',
            'location_id' => $this->lawaan->location_id,
            'status' => $status,
            'date_submitted' => now()->subDays($daysAgo),
            'date_resolved' => $status === 'Resolved' ? now() : null,
        ]);
    }

    private function check(string $category = 'Clogged Drainage'): TestResponse
    {
        return $this->actingAs($this->resident)
            ->getJson('/api/reports/similar?'.http_build_query(['category' => $category, 'location_id' => $this->lawaan->location_id]));
    }

    public function test_open_reports_of_the_same_kind_on_the_street_are_counted_without_details(): void
    {
        $first = $this->report($this->neighbour, 'Dispatched', daysAgo: 5);
        $this->report($this->neighbour, 'Submitted', daysAgo: 1);
        $this->report($this->neighbour, 'Resolved');   // closed: not counted
        $this->report($this->neighbour, 'Rejected');   // closed: not counted

        $response = $this->check()->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('mine', 0)
            ->assertJsonPath('teamWorking', true)
            ->assertJsonPath('street', 'Lawaan Street');

        $this->assertStringNotContainsString('Secret words', $response->getContent());
        $this->assertStringNotContainsString($first->tracking_code, $response->getContent());
        $this->assertStringNotContainsString($this->neighbour->full_name, $response->getContent());
    }

    public function test_the_residents_own_open_report_is_recognised(): void
    {
        $this->report($this->resident, 'Validated');

        $this->check()->assertOk()->assertJsonPath('count', 1)->assertJsonPath('mine', 1)->assertJsonPath('teamWorking', false);
    }

    public function test_nothing_is_shown_when_there_is_no_match_or_the_category_is_private(): void
    {
        $this->report($this->neighbour, 'Submitted', Category::where('name', 'Public Safety')->first());

        $this->check()->assertOk()->assertJsonPath('count', 0);
        $this->check('Public Safety')->assertOk()->assertJsonPath('count', 0);
        $this->check('No Such Category')->assertOk()->assertJsonPath('count', 0);
    }

    public function test_only_residents_can_check_and_input_is_validated(): void
    {
        $this->getJson('/api/reports/similar?category=Clogged%20Drainage&location_id=1')->assertUnauthorized();
        $this->actingAs(User::factory()->admin()->create())->getJson('/api/reports/similar?category=X&location_id=1')->assertForbidden();
        $this->actingAs($this->resident)->getJson('/api/reports/similar?category=X')->assertUnprocessable();
    }
}
