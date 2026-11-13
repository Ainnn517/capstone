<?php

namespace App\Console\Commands;

use App\Models\AdminAuditLog;
use App\Models\Attachment;
use App\Models\IssueReport;
use App\Models\User;
use App\Services\AttachmentFileRemover;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// Data retention clean-up, as promised in the privacy notice. Scheduled daily; safe to re-run.
class PurgeData extends Command
{
    protected $signature = 'data:purge {--dry-run : Show what would be deleted without deleting anything}';

    protected $description = 'Delete old rejected applications, archived reports, audit entries, and expired reset links';

    public function handle(AttachmentFileRemover $files): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $days = config('security.retention');

        $rejected = User::query()
            ->where('role', 'resident')
            ->where('account_status', 'rejected')
            ->whereDoesntHave('reports')
            ->when(($days['rejected_applications_days'] ?? 0) > 0,
                fn ($query) => $query->where('created_at', '<', now()->subDays($days['rejected_applications_days'])),
                fn ($query) => $query->whereRaw('1 = 0'))
            ->get();

        $archived = IssueReport::query()
            ->whereNotNull('archived_at')
            ->when(($days['archived_reports_days'] ?? 0) > 0,
                fn ($query) => $query->where('archived_at', '<', now()->subDays($days['archived_reports_days'])),
                fn ($query) => $query->whereRaw('1 = 0'))
            ->get();

        $auditQuery = AdminAuditLog::query()
            ->when(($days['audit_log_days'] ?? 0) > 0,
                fn ($query) => $query->where('created_at', '<', now()->subDays($days['audit_log_days'])),
                fn ($query) => $query->whereRaw('1 = 0'));

        $tokenQuery = DB::table('PASSWORD_RESET_TOKENS')
            ->where('created_at', '<', now()->subMinutes((int) config('auth.passwords.users.expire')));

        $summary = [
            'rejected_applications' => $rejected->count(),
            'archived_reports' => $archived->count(),
            'audit_entries' => $auditQuery->count(),
            'expired_reset_links' => $tokenQuery->count(),
        ];
        foreach ($summary as $label => $count) {
            $this->line(sprintf('%-24s %d', str_replace('_', ' ', $label).':', $count));
        }
        if ($dryRun) {
            $this->info('Dry run: nothing was deleted.');

            return self::SUCCESS;
        }
        if (array_sum($summary) === 0) {
            $this->info('Nothing to purge.');

            return self::SUCCESS;
        }

        $paths = DB::transaction(function () use ($rejected, $archived, $auditQuery, $tokenQuery, $summary): array {
            foreach ($rejected as $user) {
                DB::table('PASSWORD_RESET_TOKENS')->where('email', $user->email)->delete();
                $user->delete();
            }
            $paths = Attachment::query()
                ->whereIn('report_id', $archived->pluck('report_id'))
                ->whereNotNull('file_path')
                ->pluck('file_path')
                ->all();
            IssueReport::query()->whereIn('report_id', $archived->pluck('report_id'))->delete();
            $auditQuery->delete();
            $tokenQuery->delete();
            AdminAuditLog::create([
                'actor_user_id' => null,
                'action' => 'scheduled_data_purge',
                'details' => $summary,
            ]);

            return $paths;
        });
        $files->remove($paths);

        $this->info('Purge complete.');

        return self::SUCCESS;
    }
}
