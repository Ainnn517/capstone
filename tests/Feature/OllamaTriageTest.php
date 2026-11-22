<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use App\Services\Triage\AnthropicTriageModel;
use App\Services\Triage\OllamaTriageModel;
use App\Services\Triage\TriageModel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OllamaTriageTest extends TestCase
{
    private const CATEGORIES = [
        ['name' => 'Clogged Drainage', 'description' => null],
        ['name' => 'Streetlights', 'description' => null],
        ['name' => 'Others', 'description' => null],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['triage.ai_enabled' => true, 'triage.provider' => 'ollama', 'triage.ollama.url' => 'http://127.0.0.1:11434', 'triage.ollama.model' => 'qwen2.5:3b']);
    }

    private function fakeOllamaReply(array $answer, string $doneReason = 'stop'): void
    {
        Http::fake(['127.0.0.1:11434/api/chat' => Http::response([
            'message' => ['role' => 'assistant', 'content' => json_encode($answer)],
            'done' => true,
            'done_reason' => $doneReason,
        ])]);
    }

    public function test_provider_setting_chooses_the_model(): void
    {
        $this->assertInstanceOf(OllamaTriageModel::class, app(TriageModel::class));

        config(['triage.provider' => 'anthropic']);
        $this->assertInstanceOf(AnthropicTriageModel::class, app(TriageModel::class));
    }

    public function test_local_model_answer_is_used_and_only_masked_text_is_sent(): void
    {
        $this->fakeOllamaReply(['category' => 'Clogged Drainage', 'duplicate_ids' => [5, 99], 'confidence' => 'high', 'reason' => 'Flooding from a blocked canal.']);

        $result = app(OllamaTriageModel::class)->classify(
            'Barado ang kanal, text me 09171234567',
            self::CATEGORIES,
            [['id' => 5, 'text' => 'Baha sa kanto']],
        );

        $this->assertSame('Clogged Drainage', $result['category']);
        $this->assertSame([5], $result['duplicateIds'], 'Ids the server did not supply are dropped.');
        $this->assertSame('high', $result['confidence']);

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->url() === 'http://127.0.0.1:11434/api/chat'
                && $body['model'] === 'qwen2.5:3b'
                && $body['stream'] === false
                && $body['format']['properties']['category']['enum'] === ['Clogged Drainage', 'Streetlights', 'Others']
                && str_contains($body['messages'][1]['content'], '[phone]')
                && ! str_contains($body['messages'][1]['content'], '09171234567');
        });
    }

    public function test_unknown_category_or_cut_off_answer_is_discarded(): void
    {
        $this->fakeOllamaReply(['category' => 'Weather', 'duplicate_ids' => [], 'confidence' => 'high', 'reason' => 'x']);
        $this->assertNull(app(OllamaTriageModel::class)->classify('Something.', self::CATEGORIES, []));

        $this->fakeOllamaReply(['category' => 'Others', 'duplicate_ids' => [], 'confidence' => 'low', 'reason' => 'x'], 'length');
        $this->assertNull(app(OllamaTriageModel::class)->classify('Something.', self::CATEGORIES, []));
    }

    public function test_ollama_not_running_falls_back_to_keyword_rules(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'));
        Category::create(['name' => 'Clogged Drainage', 'severity_weight' => 3]);
        Category::create(['name' => 'Others', 'severity_weight' => 1]);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/triage/category', ['description' => 'Barado ang kanal sa harap ng bahay namin.'])
            ->assertOk()
            ->assertJsonPath('suggestion.category', 'Clogged Drainage')
            ->assertJsonPath('suggestion.source', 'rules');
    }

    public function test_categories_get_keyword_hints_and_others_goes_last(): void
    {
        $prepared = OllamaTriageModel::prepareCategories([
            ['name' => 'Others', 'description' => null],
            ['name' => 'Clogged Drainage', 'description' => null],
            ['name' => 'Streetlights', 'description' => 'Broken or missing street lamps.'],
        ]);

        $this->assertSame(['Clogged Drainage', 'Streetlights', 'Others'], array_column($prepared, 'name'));
        $this->assertStringContainsString('canal', $prepared[0]['description']);
        $this->assertStringStartsWith('Broken or missing street lamps. Examples: streetlight', $prepared[1]['description'], 'An admin-written description is kept, followed by examples.');
        $this->assertSame('Only when no other category fits.', $prepared[2]['description']);
    }

    public function test_missing_model_falls_back(): void
    {
        Http::fake(['127.0.0.1:11434/api/chat' => Http::response(['error' => "model 'qwen2.5:3b' not found"], 404)]);

        $this->assertNull(app(OllamaTriageModel::class)->classify('Barado ang kanal.', self::CATEGORIES, []));
    }
}
