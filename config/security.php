<?php

return [
    'max_registered_accounts' => (int) env('MAX_REGISTERED_ACCOUNTS', 9000),
    'report_page_size' => (int) env('REPORT_PAGE_SIZE', 50),
    'notification_page_size' => (int) env('NOTIFICATION_PAGE_SIZE', 10),
    'report_creation_per_minute' => (int) env('REPORT_CREATION_PER_MINUTE', 10),
    'report_creation_per_day' => (int) env('REPORT_CREATION_PER_DAY', 20),
    'registration_consent_version' => env('REGISTRATION_CONSENT_VERSION', '2026-11-13'),
    // Administrators confirm each sign-in with a code emailed to them.
    'admin_two_factor' => (bool) env('ADMIN_TWO_FACTOR', true),

    // Automatic clean-up (php artisan data:purge, scheduled daily). Days; 0 disables a rule.
    // Report attachments: photos and one short video. Each file and the whole report are capped.
    'uploads' => [
        'max_photos' => 5,
        'photo_max_kb' => 10240,
        'video_max_kb' => 10240,
        'report_total_kb' => 25600,
    ],
    'retention' => [
        'rejected_applications_days' => (int) env('RETENTION_REJECTED_DAYS', 30),
        // Pending sign-ups whose email was never verified (for example a fake address).
        'unverified_registrations_days' => (int) env('RETENTION_UNVERIFIED_DAYS', 7),
        'archived_reports_days' => (int) env('RETENTION_ARCHIVED_DAYS', 365),
        'audit_log_days' => (int) env('RETENTION_AUDIT_DAYS', 730),
    ],

    'trusted_proxies' => array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))),
];
