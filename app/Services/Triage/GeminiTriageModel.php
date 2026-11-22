<?php

namespace App\Services\Triage;

use App\Services\GeminiClient;

// Calls Google Gemini to suggest a category and spot duplicates. Same instructions,
// contact-detail masking and answer checks as the other models; any failure falls back
// to the keyword rules. Note: on Google's free tier, Google may use the text it receives
// to improve its products (disclosed in the privacy notice).
class GeminiTriageModel implements TriageModel
{
    use TriagePrompt;

    public function __construct(private readonly GeminiClient $gemini) {}

    public function available(): bool
    {
        return (bool) config('triage.ai_enabled') && $this->gemini->available();
    }

    public function classify(string $description, array $categories, array $candidates): ?array
    {
        if (! $this->available() || $categories === []) {
            return null;
        }

        $categories = self::prepareCategories($categories);
        $categoryNames = array_column($categories, 'name');
        $candidateIds = array_column($candidates, 'id');

        try {
            $input = json_encode(
                self::buildPayload($description, $categories, $candidates),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
            );
        } catch (\JsonException) {
            return null;
        }

        // A resident is waiting, so each model gets a short time before the next is tried.
        $text = $this->gemini->generate(
            self::systemPrompt(),
            $input,
            self::outputSchema($categoryNames),
            (float) config('triage.gemini.model_timeout_seconds'),
            'Triage',
        );

        return $text === null ? null : self::parseAnswer($text, $categoryNames, $candidateIds);
    }
}
