<?php

namespace App\Services;

use App\Console\Commands\DemoResidents;
use App\Models\IssueReport;
use Illuminate\Support\Facades\DB;

// "My Street" updates: tells residents on the same street that a problem there is being
// worked on (Dispatched) or has been fixed (Resolved). Shares only the category, street and
// status. In-portal only, never emailed. See config/updates.php.
class StreetUpdateService
{
    /**
     * @return int how many residents were told
     */
    public function statusChanged(IssueReport $report, string $newStatus): int
    {
        if (! config('updates.street_updates_enabled') || ! in_array($newStatus, ['Dispatched', 'Resolved'], true)) {
            return 0;
        }

        $report->loadMissing(['category:category_id,name', 'location:location_id,name,area_name', 'resident:user_id,email']);
        $category = $report->category?->name;
        $location = $report->location;
        if ($category === null || $location === null || in_array($category, (array) config('updates.excluded_categories'), true)) {
            return 0;
        }
        // Demo reports (php artisan demo:residents) never reach real residents.
        if (str_ends_with((string) $report->resident?->email, '@'.DemoResidents::DEMO_DOMAIN)) {
            return 0;
        }

        $place = $location->name === 'Area-wide' ? $location->area_name : $location->name;
        $issue = self::issuePhrase($category);
        $message = $newStatus === 'Resolved'
            ? ucfirst($issue)." on {$place} has been fixed."
            : "A team is working on {$issue} on {$place}.";

        $recipients = DB::table('USERS')
            ->where('role', 'resident')
            ->where('account_status', 'approved')
            ->where('address_location_id', $location->location_id)
            ->where('user_id', '<>', $report->user_id)
            ->where('email', 'not like', '%@'.DemoResidents::DEMO_DOMAIN)
            ->limit((int) config('updates.max_recipients'))
            ->pluck('user_id');

        if ($recipients->isEmpty()) {
            return 0;
        }
        $now = now();
        DB::table('NOTIFICATION')->insert($recipients->map(fn (int $userId): array => [
            'report_id' => $report->report_id,
            'user_id' => $userId,
            'message' => $message,
            'type' => $newStatus === 'Resolved' ? 'resolved' : 'assigned',
            'kind' => 'street',
            'timestamp' => $now,
            'is_read' => false,
        ])->all());

        return $recipients->count();
    }

    // "a Clogged Drainage issue", "an Electrical issue"; "Others" reads as "a community concern".
    public static function issuePhrase(string $category): string
    {
        if (strcasecmp($category, 'Others') === 0 || strcasecmp($category, 'Other') === 0) {
            return 'a community concern';
        }
        $article = preg_match('/^[aeiou]/i', $category) ? 'an' : 'a';

        return "{$article} {$category} issue";
    }
}
