<?php

namespace App\Services\Triage;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Illuminate\Support\Facades\Log;
use JsonException;

// Calls Claude to suggest a category and spot duplicates. Privacy rules:
// only report TEXT is sent (no names, accounts, GPS, or photos), and email
// addresses and phone numbers inside the text are masked first.
class AnthropicTriageModel implements TriageModel
{
    use TriagePrompt;

    public function available(): bool
    {
        return (bool) config('triage.ai_enabled') && filled(config('services.anthropic.key'));
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
            $client = new Client(
                apiKey: (string) config('services.anthropic.key'),
                requestOptions: [
                    'timeout' => (float) config('triage.timeout_seconds'),
                    'maxRetries' => (int) config('triage.max_retries'),
                ],
            );

            $message = $client->beta->messages->create(
                model: (string) config('triage.model'),
                maxTokens: 2048,
                system: self::systemPrompt(),
                messages: [[
                    'role' => 'user',
                    'content' => json_encode(
                        self::buildPayload($description, $categories, $candidates),
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
                    ),
                ]],
                outputConfig: [
                    'effort' => (string) config('triage.effort'),
                    'format' => [
                        'type' => 'json_schema',
                        'schema' => self::outputSchema($categoryNames),
                    ],
                ],
                // If the model declines for policy reasons, retry on the server-defined fallback model.
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (AuthenticationException|PermissionDeniedException $exception) {
            Log::warning('Triage AI is misconfigured; check ANTHROPIC_API_KEY.', ['status' => $exception->getCode()]);

            return null;
        } catch (RateLimitException) {
            Log::notice('Triage AI was rate limited; using keyword rules.');

            return null;
        } catch (APIStatusException $exception) {
            Log::notice('Triage AI request failed; using keyword rules.', ['status' => $exception->getCode()]);

            return null;
        } catch (APIConnectionException $exception) {
            Log::notice('Triage AI could not be reached or timed out; using keyword rules.', ['exception' => $exception::class]);

            return null;
        } catch (APIException|JsonException $exception) {
            Log::notice('Triage AI call failed; using keyword rules.', ['exception' => $exception::class]);

            return null;
        }

        // Only a normal finish carries a complete answer (not "refusal" or "max_tokens").
        if ($message->stopReason !== 'end_turn') {
            Log::notice('Triage AI did not return a complete answer.', ['stop_reason' => $message->stopReason]);

            return null;
        }

        $text = null;
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text = $block->text;
                break;
            }
        }

        return self::parseAnswer($text, $categoryNames, $candidateIds);
    }
}
