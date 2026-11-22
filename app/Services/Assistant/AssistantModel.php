<?php

namespace App\Services\Assistant;

// An AI model that answers an administrator's question from a block of report statistics.
// Swappable so tests never call a real service.
interface AssistantModel
{
    public function available(): bool;

    /**
     * @param  string  $instructions  the fixed rules for the model
     * @param  string  $input  JSON with the statistics and the question
     * @return array{answer: string, answerable: bool}|null null when the call fails, times out, or is declined
     */
    public function answer(string $instructions, string $input): ?array;
}
