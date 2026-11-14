<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\EvaluationLabel;
use App\Models\IssueReport;
use App\Models\Location;
use App\Models\User;
use Tests\TestCase;

class EvaluationTest extends TestCase
{
    private User $admin;

    private User $resident;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->resident = User::factory()->create();
        foreach ([['Clogged Drainage', 3], ['Streetlights', 1], ['Road Damage', 3], ['Others', 1]] as [$name, $weight]) {
            Category::create(['name' => $name, 'severity_weight' => $weight]);
        }
    }

    public function test_reports_are_shown_blind_with_contact_details_masked(): void
    {
        $first = $this->report('Barado ang kanal. Text me at 09171234567.', 'Streetlights');
        $second = $this->report('Walang ilaw sa poste.', 'Streetlights');

        $response = $this->actingAs($this->admin)->getJson('/api/evaluation/next')
            ->assertOk()
            ->assertJsonPath('report.id', (string) $first->report_id)
            ->assertJsonPath('labelled', 0)
            ->assertJsonPath('total', 2);
        $this->assertStringContainsString('[phone]', $response->json('report.description'));
        $this->assertStringNotContainsString('0917', $response->json('report.description'));
        // Blind: neither the resident's choice nor a suggestion is sent.
        $this->assertSame(['id', 'description'], array_keys($response->json('report')));

        // Skipped and labelled reports are not offered again.
        $this->getJson('/api/evaluation/next?skip='.$first->report_id)->assertJsonPath('report.id', (string) $second->report_id);
        $this->label($first, 'Clogged Drainage');
        $this->getJson('/api/evaluation/next')->assertJsonPath('report.id', (string) $second->report_id)->assertJsonPath('labelled', 1);
    }

    public function test_summary_measures_residents_rules_and_ai_against_the_labels(): void
    {
        // Resident wrong, rules right.
        $a = $this->report('Barado ang kanal, bumabaha tuwing umuulan.', 'Others');
        // Resident right, rules right.
        $b = $this->report('Walang ilaw sa poste, madilim.', 'Streetlights');
        // Resident right, rules wrong (no keyword: rules fall back to Others).
        $c = $this->report('Three lamp posts are not working.', 'Streetlights');
        foreach ([[$a, 'Clogged Drainage'], [$b, 'Streetlights'], [$c, 'Streetlights']] as [$report, $label]) {
            $this->label($report, $label);
        }

        $summary = $this->getJson('/api/evaluation/summary')->assertOk();
        $summary->assertJsonPath('labelled', 3)
            ->assertJsonPath('labellers', 1)
            ->assertJsonPath('accuracy.resident', ['correct' => 2, 'total' => 3, 'percent' => 66.7])
            ->assertJsonPath('accuracy.rules', ['correct' => 2, 'total' => 3, 'percent' => 66.7])
            ->assertJsonPath('accuracy.ai', null)
            ->assertJsonCount(1, 'misses')
            ->assertJsonPath('misses.0.rules', 'Others');

        // Once AI results are stored (by triage:evaluate --from-db --ai), they are measured too.
        EvaluationLabel::query()->whereKey($a->report_id)->update(['ai_category_id' => Category::where('name', 'Clogged Drainage')->value('category_id'), 'ai_evaluated_at' => now()]);
        EvaluationLabel::query()->whereKey($c->report_id)->update(['ai_category_id' => Category::where('name', 'Others')->value('category_id'), 'ai_evaluated_at' => now()]);
        $this->getJson('/api/evaluation/summary')->assertJsonPath('accuracy.ai', ['correct' => 1, 'total' => 2, 'percent' => 50]);
    }

    public function test_relabelling_updates_the_label_and_clears_a_stale_ai_result(): void
    {
        $report = $this->report('Something happened.', 'Others');
        $this->label($report, 'Others');
        EvaluationLabel::query()->whereKey($report->report_id)->update(['ai_category_id' => Category::where('name', 'Others')->value('category_id'), 'ai_evaluated_at' => now()]);

        $this->label($report, 'Road Damage');

        $label = EvaluationLabel::find($report->report_id);
        $this->assertSame('Road Damage', $label->category->name);
        $this->assertNull($label->ai_category_id);
        $this->assertDatabaseHas('ADMIN_AUDIT_LOG', ['action' => 'label_evaluation_report']);
    }

    public function test_export_is_a_masked_csv_usable_by_the_evaluate_command(): void
    {
        $report = $this->report('Barado ang kanal, email juan@example.com', 'Others');
        $this->label($report, 'Clogged Drainage');

        $csv = $this->get('/api/evaluation/export')->assertOk()->streamedContent();

        $this->assertStringStartsWith('description,expected_category,resident_category,rules_prediction,ai_prediction', $csv);
        $this->assertStringContainsString('[email]', $csv);
        $this->assertStringNotContainsString('juan@example.com', $csv);
        $this->assertStringContainsString('"Clogged Drainage"', $csv);

        $this->artisan('triage:evaluate', ['--from-db' => true])
            ->expectsOutputToContain('Keyword rules')
            ->expectsOutputToContain('Accuracy: 1 / 1')
            ->assertSuccessful();
    }

    public function test_only_administrators_can_use_the_evaluation_tools(): void
    {
        $report = $this->report('Something happened.', 'Others');

        $this->getJson('/api/evaluation/next')->assertUnauthorized();
        $this->actingAs($this->resident)->getJson('/api/evaluation/next')->assertForbidden();
        $this->actingAs($this->resident)->getJson('/api/evaluation/summary')->assertForbidden();
        $this->actingAs($this->resident)
            ->postJson('/api/evaluation/labels', ['report_id' => $report->report_id, 'category_id' => Category::value('category_id')])
            ->assertForbidden();
        $this->assertSame(0, EvaluationLabel::count());
    }

    private function label(IssueReport $report, string $category): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/evaluation/labels', ['report_id' => $report->report_id, 'category_id' => Category::where('name', $category)->value('category_id')])
            ->assertOk();
    }

    private function report(string $description, string $residentCategory): IssueReport
    {
        $location = Location::firstOrCreate(['name' => 'Main Street', 'area_name' => 'Barangay One']);

        return IssueReport::create([
            'tracking_code' => 'BR-'.strtoupper(bin2hex(random_bytes(6))),
            'user_id' => $this->resident->user_id,
            'category_id' => Category::where('name', $residentCategory)->value('category_id'),
            'description' => $description,
            'location_id' => $location->location_id,
            'status' => 'Submitted',
        ]);
    }
}
