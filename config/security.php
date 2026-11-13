<?php

return [
    'max_registered_accounts' => (int) env('MAX_REGISTERED_ACCOUNTS', 9000),
    'report_page_size' => (int) env('REPORT_PAGE_SIZE', 50),
    'notification_page_size' => (int) env('NOTIFICATION_PAGE_SIZE', 10),
    'report_creation_per_minute' => (int) env('REPORT_CREATION_PER_MINUTE', 10),
    'report_creation_per_day' => (int) env('REPORT_CREATION_PER_DAY', 20),
    'registration_consent_version' => env('REGISTRATION_CONSENT_VERSION', '2026-11-13'),
    'trusted_proxies' => array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))),
];
