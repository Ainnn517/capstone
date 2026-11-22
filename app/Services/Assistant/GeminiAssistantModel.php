<?php

namespace App\Services\Assistant;

use App\Services\GeminiClient;

// Answers "Ask the data" questions with Google Gemini (same key and models as triage).
class GeminiAssistantModel implements AssistantModel
{
    use ParsesAssistantAnswer;

    public function __construct(private readonly GeminiClient $gemini) {}

    public function available(): bool
    {
        return (bool) config('triage.ai_enabled') && $this->gemini->available();
    }

    public function answer(string $instructions, string $input): ?array
    {
        if (! $this->available()) {
            return null;
        }

        $text = $this->gemini->generate(
            $instructions,
            $input,
            self::answerSchema(),
            (float) config('assistant.model_timeout_seconds'),
            'Data assistant',
        );

        return $text === null ? null : self::parseAnswer($text);
    }
}
