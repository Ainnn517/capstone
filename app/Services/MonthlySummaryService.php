<?php

namespace App\Services;

use App\Models\IssueReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

// Figures for the printable monthly summary. Archived reports are included: archiving hides a
// report from the working lists, but it still happened in that month.
class MonthlySummaryService
{
    public function __construct(private readonly InsightService $insights) {}

    /**
     * @return array<string, mixed>
     */
    public function build(CarbonImmutable $month): array
    {
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();

        $submitted = IssueReport::with(['category', 'location'])
            ->whereBetween('date_submitted', [$start, $end])
            ->get();
        $resolved = IssueReport::with(['category', 'team'])
            ->where('status', 'Resolved')
            ->whereBetween('date_resolved', [$start, $end])
            ->get();

        return [
            'month' => $start,
            'generatedAt' => CarbonImmutable::now(),
            'totals' => [
                'submitted' => $submitted->count(),
                'resolved' => $resolved->count(),
                'rejected' => $submitted->where('status', 'Rejected')->count(),
                'stillOpen' => $submitted->whereNotIn('status', IssueReport::CLOSED_STATUSES)->count(),
                'averageDays' => $this->averageDays($resolved),
            ],
            'categories' => $this->byCategory($submitted, $resolved),
            'areas' => $submitted->groupBy(fn (IssueReport $report) => $report->location->area_name)
                ->map(fn (Collection $reports, string $area): array => [
                    'area' => $area,
                    'submitted' => $reports->count(),
                    'open' => $reports->whereNotIn('status', IssueReport::CLOSED_STATUSES)->count(),
                ])
                ->sortByDesc('submitted')->values()->all(),
            'teams' => $resolved->filter(fn (IssueReport $report) => $report->team !== null)
                ->groupBy(fn (IssueReport $report) => $report->team->name)
                ->map(fn (Collection $reports, string $team): array => [
                    'team' => $team,
                    'resolved' => $reports->count(),
                    'averageDays' => $this->averageDays($reports),
                ])
                ->sortByDesc('resolved')->values()->all(),
            'confirmation' => [
                'confirmed' => $resolved->filter(fn (IssueReport $report) => $report->confirmationState() === 'confirmed')->count(),
                'awaiting' => $resolved->filter(fn (IssueReport $report) => $report->confirmationState() === 'awaiting')->count(),
                'noReply' => $resolved->filter(fn (IssueReport $report) => $report->confirmationState() === 'accepted')->count(),
                'reopened' => $submitted->where('reopen_count', '>', 0)->count(),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function byCategory(Collection $submitted, Collection $resolved): array
    {
        $names = $submitted->pluck('category.name')->merge($resolved->pluck('category.name'))->unique()->sort();

        return $names->map(function (string $name) use ($submitted, $resolved): array {
            $ofCategory = $submitted->where('category.name', $name);
            $resolvedOf = $resolved->where('category.name', $name);
            $weight = (int) ($ofCategory->first() ?? $resolvedOf->first())->category->severity_weight;
            $priority = $this->insights->priority($weight);

            return [
                'category' => $name,
                'priority' => $priority,
                'submitted' => $ofCategory->count(),
                'resolved' => $resolvedOf->count(),
                'averageDays' => $this->averageDays($resolvedOf),
                'targetDays' => $this->insights->targetDays($priority),
            ];
        })->values()->all();
    }

    // "2.5 days", "6 hours", "under 1 hour", or a dash when there is nothing to average.
    public static function duration(?float $days): string
    {
        if ($days === null) {
            return '—';
        }
        if ($days >= 1) {
            return rtrim(rtrim(number_format($days, 1), '0'), '.').' '.($days == 1.0 ? 'day' : 'days');
        }
        $hours = (int) round($days * 24);

        return $hours < 1 ? 'under 1 hour' : $hours.' '.($hours === 1 ? 'hour' : 'hours');
    }

    private function averageDays(Collection $resolved): ?float
    {
        if ($resolved->isEmpty()) {
            return null;
        }

        return round($resolved->avg(fn (IssueReport $report) => CarbonImmutable::parse($report->date_submitted)
            ->diffInMinutes(CarbonImmutable::parse($report->date_resolved), true) / 1440), 3);
    }
}
