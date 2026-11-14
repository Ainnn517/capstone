<?php

namespace App\Services;

use App\Models\IssueReport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// Learns from the system's own history: which team resolves each category fastest,
// which open reports are past their target time, and how long resolution takes.
class InsightService
{
    /** @var array<string, list<array{team: string, resolved: int, averageDays: float}>>|null */
    private ?array $performance = null;

    /**
     * Teams ranked by average days to resolve, per category, over the recent window.
     *
     * @return array<string, list<array{team: string, resolved: int, averageDays: float}>>
     */
    public function teamPerformance(): array
    {
        if ($this->performance !== null) {
            return $this->performance;
        }

        $rows = DB::table('ISSUE_REPORT as report')
            ->join('CATEGORY as category', 'category.category_id', '=', 'report.category_id')
            ->join('TEAM as team', 'team.team_id', '=', 'report.team_id')
            ->where('report.status', 'Resolved')
            ->whereNotNull('report.date_resolved')
            ->where('report.date_resolved', '>=', now()->subDays((int) config('dss.history_window_days')))
            ->groupBy('category.name', 'team.name')
            ->selectRaw('category.name AS category, team.name AS team, COUNT(*) AS resolved,
                AVG(TIMESTAMPDIFF(MINUTE, report.date_submitted, report.date_resolved)) / 1440 AS average_days')
            ->get();

        $performance = [];
        foreach ($rows as $row) {
            $performance[$row->category][] = [
                'team' => $row->team,
                'resolved' => (int) $row->resolved,
                'averageDays' => round((float) $row->average_days, 1),
            ];
        }
        foreach ($performance as &$teams) {
            usort($teams, fn (array $a, array $b): int => [$a['averageDays'], -$a['resolved']] <=> [$b['averageDays'], -$b['resolved']]);
        }

        return $this->performance = $performance;
    }

    /**
     * The team to suggest for a category: the fastest with enough resolved history, otherwise
     * the team configured in Reference Data. Always explains why.
     *
     * @return array{team: ?string, reason: string, source: string}
     */
    public function recommendTeam(string $category, ?string $configuredTeam): array
    {
        $minimum = (int) config('dss.history_min_resolved');
        $candidates = array_values(array_filter(
            $this->teamPerformance()[$category] ?? [],
            fn (array $team): bool => $team['resolved'] >= $minimum,
        ));

        if ($candidates !== []) {
            $best = $candidates[0];

            return [
                'team' => $best['team'],
                'reason' => sprintf(
                    '%s resolved %d %s report%s in %s on average over the last %d days.',
                    $best['team'],
                    $best['resolved'],
                    $category,
                    $best['resolved'] === 1 ? '' : 's',
                    $this->days($best['averageDays']),
                    (int) config('dss.history_window_days'),
                ),
                'source' => 'history',
            ];
        }

        return [
            'team' => $configuredTeam,
            'reason' => $configuredTeam
                ? "{$configuredTeam} is the team set for {$category} in Reference Data (not enough resolved reports yet to learn from)."
                : "No team is set for {$category} in Reference Data yet.",
            'source' => 'configured',
        ];
    }

    // Target days to resolve a report of this priority.
    public function targetDays(string $priority): int
    {
        return (int) (config('dss.resolution_target_days')[$priority] ?? 10);
    }

    public function isOverdue(string $status, ?string $submittedAt, string $priority): bool
    {
        if (in_array($status, IssueReport::CLOSED_STATUSES, true) || $submittedAt === null) {
            return false;
        }

        return Carbon::parse($submittedAt)->diffInDays(now(), true) > $this->targetDays($priority);
    }

    /**
     * Average resolution time per category, plus how many open reports are overdue.
     *
     * @return array{categories: list<array{category: string, resolved: int, averageDays: ?float, targetDays: int, overdue: int}>, overdueTotal: int}
     */
    public function resolutionStats(): array
    {
        $resolved = DB::table('ISSUE_REPORT as report')
            ->join('CATEGORY as category', 'category.category_id', '=', 'report.category_id')
            ->where('report.status', 'Resolved')
            ->whereNotNull('report.date_resolved')
            ->groupBy('category.name')
            ->selectRaw('category.name AS category, COUNT(*) AS resolved,
                AVG(TIMESTAMPDIFF(MINUTE, report.date_submitted, report.date_resolved)) / 1440 AS average_days')
            ->get()
            ->keyBy('category');

        $open = DB::table('ISSUE_REPORT as report')
            ->join('CATEGORY as category', 'category.category_id', '=', 'report.category_id')
            ->whereNull('report.archived_at')
            ->whereNotIn('report.status', IssueReport::CLOSED_STATUSES)
            ->get(['category.name AS category', 'category.severity_weight', 'report.status', 'report.date_submitted']);

        $overdue = [];
        foreach ($open as $report) {
            if ($this->isOverdue($report->status, $report->date_submitted, $this->priority((int) $report->severity_weight))) {
                $overdue[$report->category] = ($overdue[$report->category] ?? 0) + 1;
            }
        }

        $categories = DB::table('CATEGORY')->orderBy('name')->get(['name', 'severity_weight'])
            ->map(fn ($category): array => [
                'category' => $category->name,
                'resolved' => (int) ($resolved[$category->name]->resolved ?? 0),
                'averageDays' => isset($resolved[$category->name]) ? round((float) $resolved[$category->name]->average_days, 1) : null,
                'targetDays' => $this->targetDays($this->priority((int) $category->severity_weight)),
                'overdue' => $overdue[$category->name] ?? 0,
            ])
            ->all();

        return ['categories' => $categories, 'overdueTotal' => array_sum($overdue)];
    }

    public function priority(int $severityWeight): string
    {
        return $severityWeight >= 5 ? 'Critical' : ($severityWeight >= 3 ? 'High' : 'Normal');
    }

    private function days(float $days): string
    {
        if ($days < 1) {
            $hours = max(1, (int) round($days * 24));

            return $hours.' hour'.($hours === 1 ? '' : 's');
        }

        return rtrim(rtrim(number_format($days, 1), '0'), '.').' day'.($days == 1.0 ? '' : 's');
    }
}
