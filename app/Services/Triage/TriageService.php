<?php

namespace App\Services\Triage;

use App\Models\Category;
use App\Models\IssueReport;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

// AI-assisted triage. Everything returned here is a SUGGESTION shown to a person;
// nothing is changed automatically. When the AI is unavailable or fails, keyword
// rules are used, so reporting never depends on the AI service.
class TriageService
{
    public function __construct(
        private readonly TriageModel $model,
        private readonly KeywordCategorizer $keywords,
    ) {}

    /**
     * Suggest a category while a resident is writing a report.
     *
     * @return array{category: ?string, source: string, confidence: ?string, reason: ?string}
     */
    public function suggestCategory(string $description): array
    {
        $categories = $this->categories();
        $cacheKey = 'triage:category:'.hash('sha256', $description.'|'.implode(',', array_column($categories, 'name')));

        return Cache::remember($cacheKey, now()->addMinutes((int) config('triage.cache_minutes')), function () use ($description, $categories): array {
            $ai = $this->model->classify($description, $categories, []);
            if ($ai !== null) {
                return ['category' => $ai['category'], 'source' => 'ai', 'confidence' => $ai['confidence'], 'reason' => $ai['reason']];
            }

            return $this->ruleCategory($description, $categories);
        });
    }

    /**
     * Review a submitted report for administrators: a category check and possible duplicates.
     *
     * @return array{category: array{category: ?string, source: string, confidence: ?string, reason: ?string}, duplicates: list<array<string, mixed>>, source: string}
     */
    public function reviewReport(IssueReport $report): array
    {
        $report->loadMissing('category');
        $categories = $this->categories();
        $candidates = $this->duplicateCandidates($report);
        $cacheKey = 'triage:report:'.$report->report_id.':'.hash('sha256', $report->description.'|'.$candidates->pluck('report_id')->implode(','));

        return Cache::remember($cacheKey, now()->addMinutes((int) config('triage.cache_minutes')), function () use ($report, $categories, $candidates): array {
            $ai = $this->model->classify(
                $report->description,
                $categories,
                $candidates->map(fn (IssueReport $candidate): array => [
                    'id' => (int) $candidate->report_id,
                    'text' => $candidate->description,
                ])->values()->all(),
            );

            if ($ai !== null) {
                $category = ['category' => $ai['category'], 'source' => 'ai', 'confidence' => $ai['confidence'], 'reason' => $ai['reason']];
                $duplicates = $candidates->whereIn('report_id', $ai['duplicateIds']);
                $source = 'ai';
            } else {
                // Rules: an open report in the same category at the same location is a possible duplicate.
                $category = $this->ruleCategory($report->description, $categories);
                $duplicates = $candidates->where('category_id', $report->category_id);
                $source = 'rules';
            }

            return [
                'category' => $category,
                'duplicates' => $duplicates->values()->map(fn (IssueReport $duplicate): array => [
                    'id' => (string) $duplicate->report_id,
                    'tracking' => $duplicate->tracking_code,
                    'category' => $duplicate->category?->name,
                    'description' => $duplicate->description,
                    'status' => $duplicate->status,
                    'submitted' => Carbon::parse($duplicate->date_submitted, config('app.timezone'))->toIso8601String(),
                ])->all(),
                'source' => $source,
            ];
        });
    }

    /**
     * Open, unarchived reports at the same location within the configured window.
     *
     * @return Collection<int, IssueReport>
     */
    private function duplicateCandidates(IssueReport $report)
    {
        return IssueReport::query()
            ->with('category:category_id,name')
            ->where('location_id', $report->location_id)
            ->where('report_id', '<>', $report->report_id)
            ->whereNull('archived_at')
            ->whereNotIn('status', IssueReport::CLOSED_STATUSES)
            ->where('date_submitted', '>=', now()->subDays((int) config('triage.duplicate_window_days')))
            ->orderByDesc('date_submitted')
            ->limit((int) config('triage.max_duplicate_candidates'))
            ->get(['report_id', 'tracking_code', 'category_id', 'description', 'status', 'date_submitted']);
    }

    /**
     * @param  list<array{name: string, description: ?string}>  $categories
     * @return array{category: ?string, source: string, confidence: ?string, reason: ?string}
     */
    private function ruleCategory(string $description, array $categories): array
    {
        $match = $this->keywords->suggest($description, array_column($categories, 'name'));
        if ($match === null) {
            return ['category' => null, 'source' => 'none', 'confidence' => null, 'reason' => null];
        }

        return [
            'category' => $match['category'],
            'source' => 'rules',
            'confidence' => 'low',
            'reason' => 'Matched keywords: '.implode(', ', array_slice($match['matches'], 0, 5)).'.',
        ];
    }

    /**
     * @return list<array{name: string, description: ?string}>
     */
    private function categories(): array
    {
        return Category::query()->orderBy('name')->get(['name', 'description'])
            ->map(fn (Category $category): array => ['name' => $category->name, 'description' => $category->description])
            ->all();
    }
}
