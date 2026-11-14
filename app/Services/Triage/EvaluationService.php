<?php

namespace App\Services\Triage;

use App\Models\Category;
use App\Models\EvaluationLabel;
use App\Models\IssueReport;

// Builds the evaluation set from real reports and measures, against independent labels,
// how often residents, the keyword rules, and the AI pick the right category.
class EvaluationService
{
    public function __construct(private readonly KeywordCategorizer $keywords) {}

    /**
     * The next report to label, shown blind: description only (contact details masked),
     * without the resident's chosen category or any system suggestion.
     *
     * @param  list<int>  $skip
     * @return array{report: ?array{id: string, description: string}, labelled: int, total: int}
     */
    public function next(array $skip = []): array
    {
        $report = IssueReport::query()
            ->whereNotIn('report_id', EvaluationLabel::query()->select('report_id'))
            ->when($skip !== [], fn ($query) => $query->whereNotIn('report_id', $skip))
            ->orderBy('report_id')
            ->first(['report_id', 'description']);

        return [
            'report' => $report ? [
                'id' => (string) $report->report_id,
                'description' => AnthropicTriageModel::maskContactDetails($report->description),
            ] : null,
            'labelled' => EvaluationLabel::count(),
            'total' => IssueReport::count(),
        ];
    }

    /**
     * Accuracy of residents, keyword rules, and the AI against the labels.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $rows = $this->rows();
        $count = count($rows);
        $aiRows = array_values(array_filter($rows, fn (array $row): bool => $row['ai'] !== null));
        $accuracy = fn (array $set, string $field): ?array => $set === [] ? null : [
            'correct' => $correct = count(array_filter($set, fn (array $row): bool => $row[$field] === $row['label'])),
            'total' => count($set),
            'percent' => round(100 * $correct / count($set), 1),
        ];

        $perCategory = [];
        foreach ($rows as $row) {
            $entry = $perCategory[$row['label']] ??= ['category' => $row['label'], 'labelled' => 0, 'resident' => 0, 'rules' => 0];
            $entry['labelled']++;
            $entry['resident'] += (int) ($row['resident'] === $row['label']);
            $entry['rules'] += (int) ($row['rules'] === $row['label']);
            $perCategory[$row['label']] = $entry;
        }
        ksort($perCategory);

        $misses = array_values(array_filter($rows, fn (array $row): bool => $row['rules'] !== $row['label']));

        return [
            'labelled' => $count,
            'totalReports' => IssueReport::count(),
            'labellers' => EvaluationLabel::query()->whereNotNull('labelled_by')->distinct()->count('labelled_by'),
            'accuracy' => [
                'resident' => $accuracy($rows, 'resident'),
                'rules' => $accuracy($rows, 'rules'),
                'ai' => $accuracy($aiRows, 'ai'),
            ],
            'perCategory' => array_values($perCategory),
            'misses' => array_map(fn (array $row): array => [
                'description' => mb_strimwidth($row['description'], 0, 140, '…'),
                'label' => $row['label'],
                'rules' => $row['rules'],
                'resident' => $row['resident'],
            ], array_slice($misses, 0, 20)),
        ];
    }

    /**
     * One row per labelled report, with every prediction. Descriptions are masked.
     *
     * @return list<array{report_id: int, description: string, label: string, resident: ?string, rules: ?string, ai: ?string}>
     */
    public function rows(): array
    {
        $names = Category::query()->pluck('name')->all();
        $fallback = in_array('Others', $names, true) ? 'Others' : null;

        return EvaluationLabel::query()
            ->with(['report:report_id,description,category_id', 'report.category:category_id,name', 'category:category_id,name', 'aiCategory:category_id,name'])
            ->orderBy('report_id')
            ->get()
            ->filter(fn (EvaluationLabel $label): bool => $label->report !== null)
            ->map(fn (EvaluationLabel $label): array => [
                'report_id' => (int) $label->report_id,
                'description' => AnthropicTriageModel::maskContactDetails($label->report->description),
                'label' => $label->category->name,
                'resident' => $label->report->category?->name,
                // No keyword match means no suggestion; "Others" is what a person would then pick.
                'rules' => $this->keywords->suggest($label->report->description, $names)['category'] ?? $fallback,
                'ai' => $label->aiCategory?->name,
            ])
            ->values()
            ->all();
    }
}
