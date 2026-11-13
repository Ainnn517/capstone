<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Services\Triage\KeywordCategorizer;
use App\Services\Triage\TriageModel;
use Illuminate\Console\Command;

// Measures how often the triage suggestions pick the expected category on a labelled
// set of report descriptions. Keyword rules run for free; --ai also calls the AI model,
// which uses the configured API key and costs money.
class EvaluateTriage extends Command
{
    protected $signature = 'triage:evaluate
        {--file=database/triage/evaluation-set.csv : CSV with description,expected_category columns}
        {--ai : Also evaluate the AI model (sends each description to the AI service; costs money)}';

    protected $description = 'Measure category-suggestion accuracy for the keyword rules and, optionally, the AI';

    public function handle(KeywordCategorizer $keywords, TriageModel $model): int
    {
        $path = base_path((string) $this->option('file'));
        if (! is_file($path)) {
            $this->error("Evaluation file not found: {$path}");

            return self::FAILURE;
        }

        $rows = $this->readRows($path);
        $categories = Category::query()->orderBy('name')->get(['name', 'description'])
            ->map(fn (Category $category): array => ['name' => $category->name, 'description' => $category->description])
            ->all();
        if ($categories === []) {
            $this->error('No categories found. Seed the database first (php artisan db:seed).');

            return self::FAILURE;
        }
        $names = array_column($categories, 'name');
        $unknown = array_diff(array_unique(array_column($rows, 'expected')), $names);
        if ($unknown !== []) {
            $this->warn('Expected categories not in the database (rows will count as misses): '.implode(', ', $unknown));
        }

        $this->info(sprintf('Evaluating %d labelled descriptions.', count($rows)));

        $this->report('Keyword rules', $rows, function (string $text) use ($keywords, $names): ?string {
            // With no keyword match, the system makes no suggestion; "Others" is the
            // fallback a person would pick, so count it as the rules' answer.
            return $keywords->suggest($text, $names)['category'] ?? (in_array('Others', $names, true) ? 'Others' : null);
        });

        if ($this->option('ai')) {
            if (! $model->available()) {
                $this->error('The AI is not available: set ANTHROPIC_API_KEY and TRIAGE_AI_ENABLED=true.');

                return self::FAILURE;
            }
            if (! $this->confirm(sprintf('This sends %d descriptions to the AI service and costs money. Continue?', count($rows)))) {
                return self::SUCCESS;
            }
            $this->report('AI ('.config('triage.model').')', $rows, fn (string $text): ?string => $model->classify($text, $categories, [])['category'] ?? null);
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array{description: string, expected: string}>  $rows
     * @param  callable(string): ?string  $predict
     */
    private function report(string $label, array $rows, callable $predict): void
    {
        $correct = 0;
        $failed = 0;
        $misses = [];
        $started = microtime(true);
        foreach ($rows as $row) {
            $predicted = $predict($row['description']);
            if ($predicted === null) {
                $failed++;
            }
            if ($predicted === $row['expected']) {
                $correct++;
            } else {
                $misses[] = [mb_strimwidth($row['description'], 0, 60, '…'), $row['expected'], $predicted ?? '(no answer)'];
            }
        }
        $seconds = microtime(true) - $started;

        $this->newLine();
        $this->line("<options=bold>{$label}</>");
        $this->line(sprintf('  Accuracy: %d / %d (%.1f%%)', $correct, count($rows), 100 * $correct / max(1, count($rows))));
        if ($failed > 0) {
            $this->line("  No answer: {$failed}");
        }
        $this->line(sprintf('  Average time per description: %.2f s', $seconds / max(1, count($rows))));
        if ($misses !== []) {
            $this->table(['Description', 'Expected', 'Predicted'], $misses);
        }
    }

    /**
     * @return list<array{description: string, expected: string}>
     */
    private function readRows(string $path): array
    {
        $handle = fopen($path, 'r');
        $rows = [];
        $header = fgetcsv($handle, escape: '\\');
        while (($line = fgetcsv($handle, escape: '\\')) !== false) {
            if (count($line) < 2 || trim($line[0]) === '') {
                continue;
            }
            $rows[] = ['description' => trim($line[0]), 'expected' => trim($line[1])];
        }
        fclose($handle);

        return $rows;
    }
}
