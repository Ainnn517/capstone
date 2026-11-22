<?php

namespace Tests\Feature;

use App\Models\IssueReport;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DemoResidentsTest extends TestCase
{
    public function test_adds_realistic_demo_residents_without_sending_anything_and_removes_them(): void
    {
        Mail::fake();
        Notification::fake();
        $this->seed(DatabaseSeeder::class);
        $realResident = User::factory()->create(['email' => 'real.person@gmail.com']);
        User::factory()->admin()->create(['email' => 'admin@barangay.test']);

        $this->artisan('demo:residents', ['--force' => true])->assertSuccessful();

        $demo = User::query()->where('email', 'like', '%@example.com')->get();
        $this->assertCount(24, $demo);
        $this->assertTrue($demo->every(fn (User $user): bool => $user->role === 'resident' && $user->account_status === 'approved' && ! $user->email_notifications));

        $reports = IssueReport::query()->whereIn('user_id', $demo->pluck('user_id'))->get();
        $this->assertCount(24, $reports);
        $this->assertTrue(Carbon::parse($reports->min('date_submitted'))->greaterThan(now()->subDays(180)));
        $this->assertTrue(Carbon::parse($reports->max('date_submitted'))->lessThanOrEqualTo(now()));
        $this->assertGreaterThanOrEqual(5, $reports->whereNotIn('status', IssueReport::CLOSED_STATUSES)->count());
        $this->assertTrue($reports->where('status', 'Resolved')->every(fn (IssueReport $report): bool => $report->date_resolved !== null
            && Carbon::parse($report->date_resolved)->greaterThan(Carbon::parse($report->date_submitted)) && $report->team_id !== null));

        // Every report has a status history that ends in its current status.
        foreach ($reports as $report) {
            $last = DB::table('STATUS_LOG')->where('report_id', $report->report_id)->orderByDesc('changed_at')->orderByDesc('log_id')->first();
            $this->assertSame($report->status, $last->new_status);
        }

        Mail::assertNothingSent();
        Notification::assertNothingSent();

        $this->artisan('demo:residents', ['--force' => true])->assertFailed(); // already there

        $this->artisan('demo:residents', ['--remove' => true, '--force' => true])->assertSuccessful();
        $this->assertSame(0, User::query()->where('email', 'like', '%@example.com')->count());
        $this->assertSame(0, IssueReport::query()->count());
        $this->assertModelExists($realResident);
    }
}
