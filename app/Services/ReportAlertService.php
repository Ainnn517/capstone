<?php

namespace App\Services;

use App\Mail\ReportStatusMail;
use App\Models\IssueReport;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

// Emails a resident when the barangay changes the status of their report. Sending never
// blocks the admin's action: a delivery failure is logged and the in-app notification remains.
class ReportAlertService
{
    private const HEADLINES = [
        'Validated' => 'The barangay reviewed your report and accepted it.',
        'Dispatched' => 'A team has been sent to handle your report.',
        'Resolved' => 'The barangay marked your report as resolved.',
        'Rejected' => 'The barangay could not accept your report.',
    ];

    public function statusChanged(IssueReport $report, string $message): void
    {
        $headline = self::HEADLINES[$report->status] ?? null;
        $resident = User::find($report->user_id);
        if ($headline === null || ! $resident || ! $resident->email_notifications || $resident->email_verified_at === null) {
            return;
        }
        if ($report->status === 'Dispatched' && $report->team) {
            $headline = "The {$report->team->name} has been sent to handle your report.";
        }

        try {
            Mail::to($resident->email)->send(new ReportStatusMail(
                fullName: $resident->full_name,
                tracking: $report->tracking_code,
                status: $report->status,
                headline: $headline,
                adminMessage: $message,
                respondBy: $report->confirmationDeadline()?->format('F j'),
                reportUrl: url('/resident#reports'),
                unsubscribeUrl: URL::signedRoute('email-alerts.unsubscribe', ['user' => $resident->user_id]),
            ));
        } catch (Throwable $exception) {
            Log::error('Report status email could not be sent.', [
                'report_id' => $report->report_id,
                'exception' => $exception::class,
            ]);
        }
    }
}
