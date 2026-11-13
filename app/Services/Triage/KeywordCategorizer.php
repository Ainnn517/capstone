<?php

namespace App\Services\Triage;

// Rule-based category suggestion: counts whole-word keyword matches per category.
// Used when the AI is unavailable, and as a transparent baseline for evaluation.
class KeywordCategorizer
{
    /**
     * @param  list<string>  $categories  category names that currently exist
     * @return array{category: string, matches: list<string>}|null null when nothing matches or two categories tie
     */
    public function suggest(string $description, array $categories): ?array
    {
        $text = ' '.mb_strtolower($description).' ';
        $scores = [];
        foreach (config('triage.keywords', []) as $category => $keywords) {
            if (! in_array($category, $categories, true)) {
                continue;
            }
            $matches = [];
            foreach ($keywords as $keyword) {
                $pattern = '/(?<![\p{L}\p{N}])'.preg_quote(mb_strtolower($keyword), '/').'(?![\p{L}\p{N}])/u';
                if (preg_match($pattern, $text) === 1) {
                    $matches[] = $keyword;
                }
            }
            if ($matches !== []) {
                $scores[$category] = $matches;
            }
        }

        if ($scores === []) {
            return null;
        }

        uasort($scores, fn (array $a, array $b): int => count($b) <=> count($a));
        $ranked = array_keys($scores);
        if (count($ranked) > 1 && count($scores[$ranked[0]]) === count($scores[$ranked[1]])) {
            return null; // Ambiguous: let the person choose.
        }

        return ['category' => $ranked[0], 'matches' => $scores[$ranked[0]]];
    }
}
