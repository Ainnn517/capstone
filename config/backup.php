<?php

return [
    // Where backups are written. Keep this outside public/ and restrict access: backups
    // contain resident accounts, reports, GPS data and photos.
    // Database connection used for dumps and restores (the owner account; see config/database.php).
    'connection' => env('BACKUP_DB_CONNECTION', 'mysql_admin'),

    'path' => env('BACKUP_PATH') ?: storage_path('app/backups'),

    // Backups older than this many days are deleted after each new backup. 0 keeps all.
    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),

    // Also archive uploaded report photos (storage/app/private).
    'include_attachments' => (bool) env('BACKUP_INCLUDE_ATTACHMENTS', true),

    // Encrypt every backup file with age (https://age-encryption.org) for this PUBLIC key
    // ("age1..."). Keep the matching private key OFF this computer: then a stolen disk or
    // copied backup folder reveals nothing. Empty = backups are not encrypted.
    'age_recipient' => env('BACKUP_AGE_RECIPIENT'),
    'age_binary' => env('BACKUP_AGE_BINARY', 'age'),
];
