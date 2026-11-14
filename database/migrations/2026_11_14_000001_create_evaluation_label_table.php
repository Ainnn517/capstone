<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Evaluation set: independent "correct category" labels for real reports (admin Evaluation page).
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('EVALUATION_LABEL')) {
            DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `EVALUATION_LABEL` (
  `report_id` int unsigned NOT NULL,
  `category_id` int unsigned NOT NULL,
  `labelled_by` int unsigned DEFAULT NULL,
  `labelled_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ai_category_id` int unsigned DEFAULT NULL,
  `ai_evaluated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`report_id`),
  KEY `idx_eval_category` (`category_id`),
  KEY `idx_eval_labelled_by` (`labelled_by`),
  KEY `idx_eval_ai_category` (`ai_category_id`),
  CONSTRAINT `fk_eval_ai_category` FOREIGN KEY (`ai_category_id`) REFERENCES `CATEGORY` (`category_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_eval_category` FOREIGN KEY (`category_id`) REFERENCES `CATEGORY` (`category_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_eval_labelled_by` FOREIGN KEY (`labelled_by`) REFERENCES `USERS` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_eval_report` FOREIGN KEY (`report_id`) REFERENCES `ISSUE_REPORT` (`report_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('EVALUATION_LABEL');
    }
};
