<?php

namespace App\Services\Assistant;

// The answer format every assistant model returns, and the checks applied to it.
trait ParsesAssistantAnswer
{
    /**
     * @return array<string, mixed>
     */
    protected static function answerSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'answer' => ['type' => 'string'],
                // false when the statistics do not contain what the question asks.
                'answerable' => ['type' => 'boolean'],
            ],
            'required' => ['answer', 'answerable'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array{answer: string, answerable: bool}|null
     */
    protected static function parseAnswer(?string $text): ?array
    {
        try {
            $data = json_decode((string) $text, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $answer = is_array($data) ? trim((string) ($data['answer'] ?? '')) : '';
        if ($answer === '') {
            return null;
        }

        return [
            'answer' => mb_substr($answer, 0, (int) config('assistant.max_answer_characters')),
            'answerable' => (bool) ($data['answerable'] ?? true),
        ];
    }
}
