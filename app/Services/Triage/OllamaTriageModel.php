<?php

namespace App\Services\Triage;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Runs triage on a model served by Ollama on this computer (free, works offline).
// Report text never leaves the server. Same instructions, masking and answer checks
// as the cloud model; any failure falls back to the keyword rules.
class OllamaTriageModel implements TriageModel
{
    use TriagePrompt;

    public function available(): bool
    {
        return (bool) config('triage.ai_enabled') && filled(config('triage.ollama.url')) && filled(config('triage.ollama.model'));
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
            $response = Http::timeout((int) ceil((float) config('triage.ollama.timeout_seconds')))
                ->connectTimeout(3)
                ->acceptJson()
                ->post(rtrim((string) config('triage.ollama.url'), '/').'/api/chat', [
                    'model' => (string) config('triage.ollama.model'),
                    'stream' => false,
                    // Ollama constrains the reply to this JSON schema.
                    'format' => self::outputSchema($categoryNames),
                    // Keep the model loaded between suggestions so residents don't wait for it to load.
                    'keep_alive' => (string) config('triage.ollama.keep_alive'),
                    'options' => ['temperature' => 0],
                    'messages' => [
                        ['role' => 'system', 'content' => self::systemPrompt()],
                        ['role' => 'user', 'content' => json_encode(
                            self::buildPayload($description, $categories, $candidates),
                            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
                        )],
                    ],
                ])
                ->throw();
        } catch (ConnectionException $exception) {
            Log::notice('Local AI (Ollama) could not be reached or timed out; using keyword rules.', ['exception' => $exception::class]);

            return null;
        } catch (RequestException $exception) {
            // 404 usually means the model has not been downloaded: run `ollama pull <model>`.
            Log::notice('Local AI (Ollama) request failed; using keyword rules.', ['status' => $exception->response->status()]);

            return null;
        } catch (\JsonException) {
            return null;
        }

        // "stop" is a normal finish; "length" means the answer was cut off.
        if ($response->json('done_reason', 'stop') !== 'stop') {
            Log::notice('Local AI (Ollama) did not return a complete answer.', ['done_reason' => $response->json('done_reason')]);

            return null;
        }

        return self::parseAnswer($response->json('message.content'), $categoryNames, $candidateIds);
    }
}
