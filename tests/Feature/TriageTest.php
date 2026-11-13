<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\IssueReport;
use App\Models\Location;
use App\Models\User;
use App\Services\Triage\AnthropicTriageModel;
use App\Services\Triage\KeywordCategorizer;
use App\Services\Triage\TriageModel;
use Tests\TestCase;

class TriageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach ([['Sanitation', 1], ['Clogged Drainage', 3], ['Streetlights', 1], ['Road Damage', 3], ['Public Safety', 5], ['Others', 1]] as [$name, $weight]) {
            Category::create(['name' => $name, 'severity_weight' => $weight]);
        }
    }

    // ------------------------------------------------------------ keyword rules

    public function test_keyword_rules_understand_english_tagalog_and_bisaya(): void
    {
        $rules = new KeywordCategorizer;
        $names = Category::pluck('name')->all();

        $this->assertSame('Clogged Drainage', $rules->suggest('Barado ang kanal, bumabaha tuwing umuulan.', $names)['category']);
        $this->assertSame('Streetlights', $rules->suggest('Patay ang suga sa poste, ngitngit kaayo.', $names)['category']);
        $this->assertSame('Road Damage', $rules->suggest('There is a big pothole near the corner.', $names)['category']);
        $this->assertSame('Public Safety', $rules->suggest('Open manhole beside the waiting shed.', $names)['category']);
        $this->assertNull($rules->suggest('Loud karaoke every weekend.', $names));
    }

    public function test_keyword_rules_match_whole_words_only(): void
    {
        // "drain" must not match inside "drained", and "fire" must not match inside "fireflies".
        $this->assertNull((new KeywordCategorizer)->suggest('I am drained; the fireflies are pretty.', Category::pluck('name')->all()));
    }

    // ------------------------------------------------- resident suggestion API

    public function test_resident_gets_a_keyword_suggestion_when_the_ai_is_not_configured(): void
    {
        $resident = User::factory()->create();

        $this->actingAs($resident)
            ->postJson('/api/triage/category', ['description' => 'Barado ang kanal sa harap ng bahay namin.'])
            ->assertOk()
            ->assertJsonPath('suggestion.category', 'Clogged Drainage')
            ->assertJsonPath('suggestion.source', 'rules');
    }

    public function test_resident_gets_an_ai_suggestion_when_the_ai_answers(): void
    {
        $this->fakeAi(['category' => 'Streetlights', 'duplicateIds' => [], 'confidence' => 'high', 'reason' => 'Mentions a dark street.']);
        $resident = User::factory()->create();

        $this->actingAs($resident)
            ->postJson('/api/triage/category', ['description' => 'Sobrang dilim sa dulo ng kalye namin.'])
            ->assertOk()
            ->assertJsonPath('suggestion.category', 'Streetlights')
            ->assertJsonPath('suggestion.source', 'ai')
            ->assertJsonPath('suggestion.reason', 'Mentions a dark street.');
    }

    public function test_ai_failure_falls_back_to_keyword_rules(): void
    {
        $this->fakeAi(null); // Timeout, error, or refusal.
        $resident = User::factory()->create();

        $this->actingAs($resident)
            ->postJson('/api/triage/category', ['description' => 'Malaking lubak sa gitna ng daan.'])
            ->assertOk()
            ->assertJsonPath('suggestion.category', 'Road Damage')
            ->assertJsonPath('suggestion.source', 'rules');
    }

    public function test_suggestion_requires_a_resident_and_a_meaningful_description(): void
    {
        $administrator = User::factory()->admin()->create();
        $resident = User::factory()->create();

        $this->postJson('/api/triage/category', ['description' => 'Barado ang kanal sa amin.'])->assertUnauthorized();
        $this->actingAs($administrator)
            ->postJson('/api/triage/category', ['description' => 'Barado ang kanal sa amin.'])
            ->assertForbidden();
        $this->actingAs($resident)
            ->postJson('/api/triage/category', ['description' => 'short'])
            ->assertUnprocessable();
    }

    // ------------------------------------------------------- admin review API

    public function test_rules_flag_open_reports_in_the_same_category_and_location_as_possible_duplicates(): void
    {
        $administrator = User::factory()->admin()->create();
        $resident = User::factory()->create();
        $street = Location::create(['name' => 'Mango Street', 'area_name' => 'Village 9']);
        $otherStreet = Location::create(['name' => 'Laurel Street', 'area_name' => 'Village 9']);

        $report = $this->report($resident, 'Road Damage', $street, 'Big pothole near the corner.');
        $duplicate = $this->report($resident, 'Road Damage', $street, 'Lubak sa may kanto, delikado.');
        $this->report($resident, 'Road Damage', $street, 'Old pothole, already fixed.', 'Resolved');
        $this->report($resident, 'Road Damage', $otherStreet, 'Pothole on another street.');
        $this->report($resident, 'Streetlights', $street, 'Streetlight is out.');

        $this->actingAs($administrator)
            ->getJson('/api/reports/'.$report->report_id.'/triage')
            ->assertOk()
            ->assertJsonPath('source', 'rules')
            ->assertJsonCount(1, 'duplicates')
            ->assertJsonPath('duplicates.0.id', (string) $duplicate->report_id);
    }

    public function test_ai_duplicates_are_limited_to_real_candidates(): void
    {
        $administrator = User::factory()->admin()->create();
        $resident = User::factory()->create();
        $street = Location::create(['name' => 'Mango Street', 'area_name' => 'Village 9']);
        $report = $this->report($resident, 'Others', $street, 'Open hole without cover near the shed.');
        $sameIssue = $this->report($resident, 'Public Safety', $street, 'Walang takip yung butas sa may shed.');
        $elsewhere = $this->report($resident, 'Public Safety', Location::create(['name' => 'Far Street', 'area_name' => 'Village 9']), 'Unrelated.');

        // The AI names a real candidate and an id from another street; only the candidate is kept.
        $this->fakeAi(['category' => 'Public Safety', 'duplicateIds' => [$sameIssue->report_id, $elsewhere->report_id], 'confidence' => 'high', 'reason' => 'Same uncovered hole.']);

        $this->actingAs($administrator)
            ->getJson('/api/reports/'.$report->report_id.'/triage')
            ->assertOk()
            ->assertJsonPath('source', 'ai')
            ->assertJsonPath('category.category', 'Public Safety')
            ->assertJsonCount(1, 'duplicates')
            ->assertJsonPath('duplicates.0.id', (string) $sameIssue->report_id);
    }

    public function test_only_administrators_can_review_reports(): void
    {
        $resident = User::factory()->create();
        $report = $this->report($resident, 'Others', Location::create(['name' => 'Main', 'area_name' => 'One']), 'Something happened here.');

        $this->actingAs($resident)->getJson('/api/reports/'.$report->report_id.'/triage')->assertForbidden();
    }

    // ------------------------------------------------------------------ privacy

    public function test_ai_payload_contains_report_text_only_with_contact_details_masked(): void
    {
        $payload = AnthropicTriageModel::buildPayload(
            'Flooded street. Call me at 0917 123 4567 or +63 917 765 4321, email juan@example.com',
            [['name' => 'Clogged Drainage', 'description' => null]],
            [['id' => 7, 'text' => 'Baha dito, text 09181234567']],
        );
        $json = json_encode($payload);

        $this->assertStringNotContainsString('0917', $json);
        $this->assertStringNotContainsString('765 4321', $json);
        $this->assertStringNotContainsString('juan@example.com', $json);
        $this->assertStringNotContainsString('09181234567', $json);
        $this->assertStringContainsString('[phone]', $json);
        $this->assertStringContainsString('[email]', $json);
        $this->assertSame(['categories', 'new_report', 'open_reports_same_location'], array_keys($payload));
        $this->assertSame(['id', 'text'], array_keys($payload['open_reports_same_location'][0]));
    }

    public function test_real_ai_is_never_called_without_an_api_key(): void
    {
        $this->assertFalse(app(AnthropicTriageModel::class)->available());
        $this->assertNull(app(AnthropicTriageModel::class)->classify('Barado ang kanal.', [['name' => 'Others', 'description' => null]], []));
    }

    /**
     * @param  array{category: string, duplicateIds: list<int>, confidence: string, reason: string}|null  $answer
     */
    private function fakeAi(?array $answer): void
    {
        $this->app->instance(TriageModel::class, new class($answer) implements TriageModel
        {
            public function __construct(private readonly ?array $answer) {}

            public function available(): bool
            {
                return true;
            }

            public function classify(string $description, array $categories, array $candidates): ?array
            {
                return $this->answer;
            }
        });
    }

    private function report(User $resident, string $category, Location $location, string $description, string $status = 'Submitted'): IssueReport
    {
        return IssueReport::create([
            'tracking_code' => 'BR-'.strtoupper(bin2hex(random_bytes(6))),
            'user_id' => $resident->user_id,
            'category_id' => Category::where('name', $category)->value('category_id'),
            'description' => $description,
            'location_id' => $location->location_id,
            'status' => $status,
            'date_resolved' => $status === 'Resolved' ? now() : null,
        ]);
    }
}
