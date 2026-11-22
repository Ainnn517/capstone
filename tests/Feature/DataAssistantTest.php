<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\IssueReport;
use App\Models\Location;
use App\Models\User;
use App\Services\Assistant\AssistantModel;
use App\Services\Assistant\DataAssistant;
use App\Services\Assistant\GeminiAssistantModel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DataAssistantTest extends TestCase
{
    private User $resident;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resident = User::factory()->create([
            'full_name' => 'Juanita Delacruz-Santos',
            'email' => 'juanita.private@example.com',
        ]);
        $drainage = Category::create(['name' => 'Clogged Drainage', 'severity_weight' => 3]);
        $safety = Category::create(['name' => 'Public Safety', 'severity_weight' => 5]);
        $street = Location::create(['name' => 'Mango Street', 'area_name' => 'Phase 1']);
        foreach ([[$drainage, 'Submitted'], [$drainage, 'Resolved'], [$safety, 'Validated']] as [$category, $status]) {
            IssueReport::create([
                'tracking_code' => 'BR-'.strtoupper(bin2hex(random_bytes(6))),
                'user_id' => $this->resident->user_id,
                'category_id' => $category->category_id,
                'description' => 'Secret description text near house number 42 '.$status,
                'landmark' => 'Beside the blue gate of Aling Nena',
                'location_id' => $street->location_id,
                'status' => $status,
                'date_resolved' => $status === 'Resolved' ? now() : null,
            ]);
        }
    }

    /**
     * A fake AI that records what it was given.
     */
    private function fakeModel(?array $answer = ['answer' => 'Mango Street has 3 reports.', 'answerable' => true]): object
    {
        $fake = new class($answer) implements AssistantModel
        {
            public ?string $input = null;

            public function __construct(private readonly ?array $result) {}

            public function available(): bool
            {
                return true;
            }

            public function answer(string $instructions, string $input): ?array
            {
                $this->input = $input;

                return $this->result;
            }
        };
        $this->app->instance(AssistantModel::class, $fake);

        return $fake;
    }

    public function test_admin_gets_an_answer_built_from_statistics(): void
    {
        $fake = $this->fakeModel();

        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/analytics/ask', ['question' => 'Which street has the most reports?'])
            ->assertOk()
            ->assertJsonPath('answer', 'Mango Street has 3 reports.')
            ->assertJsonPath('answerable', true)
            ->assertJsonStructure(['asOf']);

        $sent = json_decode($fake->input, true);
        $this->assertSame('Which street has the most reports?', $sent['question']);
        $this->assertSame(3, $sent['statistics']['totals']['reports']);
        $this->assertSame(2, $sent['statistics']['totals']['open']);
        $this->assertSame('Mango Street', $sent['statistics']['streetTotals'][0]['street']);
        $this->assertSame(3, $sent['statistics']['streetTotals'][0]['allTime']);
        $this->assertSame(1, $sent['statistics']['dss']['recommendations'][0]['rank']);
    }

    public function test_statistics_never_contain_resident_details_or_report_text(): void
    {
        $json = json_encode(app(DataAssistant::class)->statistics());

        $this->assertStringNotContainsString('Juanita', $json);
        $this->assertStringNotContainsString('juanita.private@example.com', $json);
        $this->assertStringNotContainsString($this->resident->username, $json);
        $this->assertStringNotContainsString('Secret description', $json);
        $this->assertStringNotContainsString('Aling Nena', $json);
        foreach (IssueReport::pluck('tracking_code') as $code) {
            $this->assertStringNotContainsString($code, $json);
        }
        $this->assertStringContainsString('Mango Street', $json);
    }

    public function test_only_administrators_can_ask(): void
    {
        $this->fakeModel();

        $this->postJson('/api/analytics/ask', ['question' => 'How many reports?'])->assertUnauthorized();
        $this->actingAs($this->resident)->postJson('/api/analytics/ask', ['question' => 'How many reports?'])->assertForbidden();
    }

    public function test_question_length_is_validated(): void
    {
        $this->fakeModel();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/analytics/ask', ['question' => 'Hi'])->assertUnprocessable();
        $this->actingAs($admin)->postJson('/api/analytics/ask', ['question' => str_repeat('a', 301)])->assertUnprocessable();
    }

    public function test_a_failed_ai_call_gives_a_clear_message(): void
    {
        $this->fakeModel(null);

        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/analytics/ask', ['question' => 'How many reports?'])
            ->assertStatus(503)
            ->assertJsonPath('success', false);
    }

    public function test_box_is_hidden_and_endpoint_refuses_when_ai_is_off(): void
    {
        // phpunit.xml switches the AI off and leaves the keys empty.
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson('/api/analytics/dss')->assertOk()->assertJsonPath('assistantAvailable', false);
        $this->actingAs($admin)->postJson('/api/analytics/ask', ['question' => 'How many reports?'])->assertStatus(503);
    }

    public function test_each_admin_has_a_per_minute_limit(): void
    {
        config(['assistant.per_minute_per_admin' => 2]);
        $this->fakeModel();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/analytics/ask', ['question' => 'Question one?'])->assertOk();
        $this->actingAs($admin)->postJson('/api/analytics/ask', ['question' => 'Question two?'])->assertOk();
        $this->actingAs($admin)->postJson('/api/analytics/ask', ['question' => 'Question three?'])->assertStatus(429);
    }

    public function test_gemini_falls_back_to_the_next_model_when_busy(): void
    {
        config([
            'triage.ai_enabled' => true,
            'triage.gemini.key' => 'test-key',
            'triage.gemini.model' => 'busy-model',
            'triage.gemini.fallback_models' => 'spare-model',
        ]);
        Http::fake([
            '*busy-model:generateContent' => Http::response(['error' => ['code' => 503]], 503),
            '*spare-model:generateContent' => Http::response(['candidates' => [[
                'content' => ['parts' => [['text' => json_encode(['answer' => 'Two open reports.', 'answerable' => true])]]],
                'finishReason' => 'STOP',
            ]]]),
        ]);

        $result = app(GeminiAssistantModel::class)->answer('rules', '{"question":"How many open?"}');

        $this->assertSame(['answer' => 'Two open reports.', 'answerable' => true], $result);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'spare-model'));
    }

    public function test_gemini_does_not_try_other_models_when_the_quota_is_used_up(): void
    {
        config([
            'triage.ai_enabled' => true,
            'triage.gemini.key' => 'test-key',
            'triage.gemini.model' => 'main-model',
            'triage.gemini.fallback_models' => 'spare-model',
        ]);
        Http::fake(['*' => Http::response(['error' => ['code' => 429]], 429)]);

        $this->assertNull(app(GeminiAssistantModel::class)->answer('rules', '{}'));
        Http::assertSentCount(1);
    }
}
