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
        if (! Schema::hasTable('ADMIN_AUDIT_LOG')) {
            DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `ADMIN_AUDIT_LOG` (
  `audit_id` int unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` int unsigned DEFAULT NULL,
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` json NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`audit_id`),
  KEY `idx_admin_audit_actor_time` (`actor_user_id`,`created_at`),
  CONSTRAINT `fk_admin_audit_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `USERS` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ADMIN_AUDIT_LOG');
    }
};
