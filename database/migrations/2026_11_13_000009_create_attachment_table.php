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
        if (! Schema::hasTable('ATTACHMENT')) {
            DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `ATTACHMENT` (
  `attachment_id` int unsigned NOT NULL AUTO_INCREMENT,
  `report_id` int unsigned NOT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mime_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_data` mediumblob,
  `uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`attachment_id`),
  KEY `idx_attachment_report` (`report_id`),
  CONSTRAINT `fk_attachment_report` FOREIGN KEY (`report_id`) REFERENCES `ISSUE_REPORT` (`report_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_attachment_storage` CHECK ((((`file_data` is not null) and (`mime_type` is not null) and (`file_path` is null)) or ((`file_data` is null) and (`file_path` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ATTACHMENT');
    }
};
