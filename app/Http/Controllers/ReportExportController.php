<?php

namespace App\Http\Controllers;

use App\Models\AdminAuditLog;
use App\Models\IssueReport;
use App\Services\InsightService;
use App\Services\MonthlySummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Downloads and printouts for barangay meetings and reports to the municipality.
// Exports leave out residents' names and contact details (the tracking code links back to them).
class ReportExportController extends ApiController
{
    private const MAX_RANGE_DAYS = 366;

    // Reports submitted between two dates, as a CSV file that opens in Excel.
    public function csv(Request $request): StreamedResponse|JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        if ($validator->fails()) {
            return $this->error('Choose a valid date range.', 422);
        }
        $from = CarbonImmutable::parse($request->query('from'))->startOfDay();
        $to = CarbonImmutable::parse($request->query('to'))->endOfDay();
        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            return $this->error('Export at most one year at a time.', 422);
        }

        AdminAuditLog::create([
            'actor_user_id' => $this->currentUser()->user_id,
            'action' => 'export_reports',
            'details' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);

        $insights = app(InsightService::class);
        $filename = "barangay-reports-{$from->toDateString()}-to-{$to->toDateString()}.csv";

        return response()->streamDownload(function () use ($from, $to, $insights): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 marker so Excel shows ñ and other letters correctly.
            fputcsv($out, ['Tracking code', 'Submitted', 'Category', 'Description', 'Priority', 'Area', 'Street', 'Landmark', 'Status', 'Team', 'Resolved', 'Days to resolve', 'Resident answer', 'Times reopened', 'Archived']);

            IssueReport::with(['category', 'location', 'team'])
                ->whereBetween('date_submitted', [$from, $to])
                ->orderBy('date_submitted')
                ->chunk(500, function ($reports) use ($out, $insights): void {
                    foreach ($reports as $report) {
                        $days = $report->date_resolved
                            ? round(CarbonImmutable::parse($report->date_submitted)->diffInMinutes(CarbonImmutable::parse($report->date_resolved), true) / 1440, 1)
                            : '';
                        fputcsv($out, array_map([$this, 'cell'], [
                            $report->tracking_code,
                            $report->date_submitted,
                            $report->category->name,
                            $report->description,
                            $insights->priority((int) $report->category->severity_weight),
                            $report->location->area_name,
                            $report->location->name,
                            $report->landmark ?? '',
                            $report->status,
                            $report->team?->name ?? '',
                            $report->date_resolved ?? '',
                            $days,
                            match ($report->confirmationState()) {
                                'confirmed' => 'Confirmed fixed',
                                'awaiting' => 'Waiting',
                                'accepted' => 'No reply',
                                default => '',
                            },
                            (int) $report->reopen_count,
                            $report->archived_at ? 'Yes' : 'No',
                        ]));
                    }
                });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // Printable one-page summary of a month (opens in a new tab; Print or Save as PDF).
    public function summary(Request $request, MonthlySummaryService $summaries): View
    {
        $month = $request->query('month');
        $parsed = is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)
            ? CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')
            : CarbonImmutable::now();

        return view('pages.monthly-summary', $summaries->build($parsed->startOfMonth()));
    }

    // Spreadsheet programs run cells that start with = + - @ as formulas; prefix them so a
    // report description can never become a formula in someone's Excel.
    private function cell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
