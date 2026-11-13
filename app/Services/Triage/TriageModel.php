<?php

namespace App\Services\Triage;

// An AI model that reads report text. Swappable so tests never call a real service.
interface TriageModel
{
    // Whether the model can be called at all (for example, an API key is configured).
    public function available(): bool;

    /**
     * @param  list<array{name: string, description: ?string}>  $categories
     * @param  list<array{id: int, text: string}>  $candidates  open reports at the same location
     * @return array{category: string, duplicateIds: list<int>, confidence: string, reason: string}|null
     *                                                                                                   null when the call fails, times out, or is declined
     */
    public function classify(string $description, array $categories, array $candidates): ?array;
}
