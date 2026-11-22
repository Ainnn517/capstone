<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// One call to Google Gemini with a JSON answer schema. When a model is busy (Google
// answers 503 or the call times out), the next model in GEMINI_FALLBACK_MODELS is tried,
// so a busy period does not stop the AI features. Quota (429) and key (400/403) errors
// are not retried: another model would fail the same way.
class GeminiClient
{
    public function available(): bool
    {
        return filled(config('triage.gemini.key')) && $this->models() !== [];
    }

    /**
     * The text of a complete answer, or null (logged) when every model failed or declined.
     *
     * @param  array<string, mixed>  $schema
     */
    public function generate(string $instructions, string $input, array $schema, float $timeoutSeconds, string $feature): ?string
    {
        foreach ($this->models() as $model) {
            $response = $this->call($model, $instructions, $input, $schema, $timeoutSeconds, $feature);
            if ($response === false) {
                continue; // busy or timed out: try the next model
            }
            if ($response === null) {
                return null;
            }

            if ($response->json('promptFeedback.blockReason') || $response->json('candidates.0.finishReason') !== 'STOP') {
                Log::notice("{$feature}: Gemini did not return a complete answer.", [
                    'model' => $model,
                    'finish_reason' => $response->json('candidates.0.finishReason'),
                    'block_reason' => $response->json('promptFeedback.blockReason'),
                ]);

                return null;
            }

            // The answer may be split across parts; thought summaries (if any) are skipped.
            return collect($response->json('candidates.0.content.parts', []))
                ->reject(fn ($part): bool => ! empty($part['thought']))
                ->pluck('text')
                ->implode('');
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function models(): array
    {
        $fallbacks = array_map('trim', explode(',', (string) config('triage.gemini.fallback_models')));

        return array_values(array_unique(array_filter([trim((string) config('triage.gemini.model')), ...$fallbacks])));
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return Response|false|null the response; false = busy, try another model; null = give up
     */
    private function call(string $model, string $instructions, string $input, array $schema, float $timeoutSeconds, string $feature): Response|false|null
    {
        try {
            return Http::timeout(max(1, (int) ceil($timeoutSeconds)))
                ->connectTimeout(5)
                ->withHeaders(['x-goog-api-key' => (string) config('triage.gemini.key')])
                ->acceptJson()
                ->post('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent', [
                    'systemInstruction' => ['parts' => [['text' => $instructions]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $input]]]],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'responseJsonSchema' => $schema,
                    ],
                ])
                ->throw();
        } catch (ConnectionException $exception) {
            Log::notice("{$feature}: Gemini model timed out or could not be reached.", ['model' => $model, 'exception' => $exception::class]);

            return false;
        } catch (RequestException $exception) {
            $status = $exception->response->status();
            Log::notice("{$feature}: Gemini request failed.", ['model' => $model, 'status' => $status]);

            // 500/503: this model is busy, so try the next one. 429 (quota), 400/403 (key, model name): stop.
            return $exception->response->serverError() ? false : null;
        }
    }
}
