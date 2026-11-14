<?php

namespace App\Services;

use App\Models\IssueReport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonException;

class DssRecommendationService
{
    public function __construct(private readonly InsightService $insights) {}

    /**
     * @return array{recommendations: list<array<string, mixed>>, sourceReportCount: int}
     */
    public function recommendations(): array
    {
        $reports = DB::table('ISSUE_REPORT as report')
            ->join('CATEGORY as category', 'category.category_id', '=', 'report.category_id')
            ->join('LOCATION as location', 'location.location_id', '=', 'report.location_id')
            ->whereNull('report.archived_at')
            ->whereNotIn('report.status', IssueReport::CLOSED_STATUSES)
            ->select([
                'report.date_submitted',
                'category.name as category',
                'category.severity_weight',
                'team.name as recommended_team',
                'location.name as street',
                'location.area_name as area',
            ])
            ->leftJoin('TEAM as team', 'team.team_id', '=', 'category.recommended_team_id')
            ->get();

        $weights = config('dss.weights');
        $groups = [];

        foreach ($reports as $report) {
            $location = $report->street.', '.$report->area;
            try {
                $key = json_encode([$location, $report->category], JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                Log::error('Could not encode a DSS group key.', ['exception' => $exception]);
                throw $exception;
            }

            $groups[$key] ??= [
                'location' => $location,
                'category' => $report->category,
                'recommendedTeam' => $report->recommended_team,
                'ages' => [],
                'severityTotal' => 0,
                'criticalCount' => 0,
                'overdueCount' => 0,
            ];
            $groups[$key]['ages'][] = max(
                0,
                now()->diffInSeconds(Carbon::parse($report->date_submitted), true) / 86400,
            );
            $severityWeight = (int) $report->severity_weight;
            $groups[$key]['severityTotal'] += $severityWeight;
            if ($severityWeight >= 5) {
                $groups[$key]['criticalCount']++;
            }
            if ($this->insights->isOverdue('Submitted', $report->date_submitted, $this->insights->priority($severityWeight))) {
                $groups[$key]['overdueCount']++;
            }
        }

        $recommendations = collect($groups)->map(function (array $group) use ($weights): array {
            $count = count($group['ages']);
            $averageAgeDays = array_sum($group['ages']) / $count;
            $countScore = $count * $weights['report_count'];
            $ageScore = $averageAgeDays * $weights['average_age_days'];
            $severityScore = $group['severityTotal'] * $weights['severity_weight'];
            $score = $countScore + $ageScore + $severityScore;

            return [
                'location' => $group['location'],
                'category' => $group['category'],
                'reportCount' => $count,
                'averageAgeDays' => round($averageAgeDays, 2),
                'criticalCount' => $group['criticalCount'],
                'score' => round($score, 2),
                'urgency' => $score >= config('dss.high_urgency_threshold') ? 'High' : 'Moderate',
                'scoreBreakdown' => [
                    'reportCount' => $countScore,
                    'averageAgeDays' => round($ageScore, 2),
                    'severity' => $severityScore,
                ],
                'recommendedTeam' => ($team = $this->insights->recommendTeam($group['category'], $group['recommendedTeam']))['team'],
                'teamReason' => $team['reason'],
                'teamSource' => $team['source'],
                'overdueCount' => $group['overdueCount'],
                'recommendedAction' => config('dss.action_by_category')[$group['category']]
                    ?? 'Review the reports and identify the appropriate response.',
            ];
        })->sortByDesc('score')->take(5)->values();

        return [
            'recommendations' => $recommendations->all(),
            'sourceReportCount' => $reports->count(),
            'overdueTotal' => $this->insights->resolutionStats()['overdueTotal'],
        ];
    }
}
