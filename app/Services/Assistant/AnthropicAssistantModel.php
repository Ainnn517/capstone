<?php

namespace App\Services\Assistant;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use Illuminate\Support\Facades\Log;

// Answers "Ask the data" questions with Claude (same key and model as triage).
class AnthropicAssistantModel implements AssistantModel
{
    use ParsesAssistantAnswer;

    public function available(): bool
    {
        return (bool) config('triage.ai_enabled') && filled(config('services.anthropic.key'));
    }

    public function answer(string $instructions, string $input): ?array
    {
        if (! $this->available()) {
            return null;
        }

        try {
            $client = new Client(
                apiKey: (string) config('services.anthropic.key'),
                requestOptions: [
                    'timeout' => (float) config('assistant.timeout_seconds'),
                    'maxRetries' => (int) config('triage.max_retries'),
                ],
            );
            $message = $client->beta->messages->create(
                model: (string) config('triage.model'),
                maxTokens: 4096,
                system: $instructions,
                messages: [['role' => 'user', 'content' => $input]],
                outputConfig: [
                    'effort' => 'low',
                    'format' => ['type' => 'json_schema', 'schema' => self::answerSchema()],
                ],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (APIException $exception) {
            Log::notice('Data assistant: Claude request failed.', ['exception' => $exception::class, 'status' => $exception->getCode()]);

            return null;
        }

        if ($message->stopReason !== 'end_turn') {
            Log::notice('Data assistant: Claude did not return a complete answer.', ['stop_reason' => $message->stopReason]);

            return null;
        }
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return self::parseAnswer($block->text);
            }
        }

        return null;
    }
}
