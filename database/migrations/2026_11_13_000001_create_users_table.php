<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Baseline schema (generated from database/mysql_schema.sql). On a database that already has
// this table the migration only records itself as done, so existing installations can run
// `php artisan migrate` safely. Make later schema changes in NEW migrations.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('USERS')) {
            DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `USERS` (
  `user_id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('resident','admin') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'resident',
  `account_status` enum('pending','approved','rejected','suspended') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `privacy_consent_at` datetime DEFAULT NULL,
  `privacy_notice_version` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `contact_number` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role_status` (`role`,`account_status`),
  CONSTRAINT `chk_users_consent_pair` CHECK (((`privacy_consent_at` is null) = (`privacy_notice_version` is null))),
  CONSTRAINT `chk_users_username_length` CHECK ((char_length(`username`) >= 3))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('USERS');
    }
};
