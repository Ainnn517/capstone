<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Print a sample inspirational quote in the terminal.
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Data retention: remove old rejected applications, archived reports, audit entries and
// expired reset links (periods in config/security.php). Needs the scheduler running on the
// server: a cron entry `* * * * * php /path/to/artisan schedule:run`.
Schedule::command('data:purge')->dailyAt('02:00');
