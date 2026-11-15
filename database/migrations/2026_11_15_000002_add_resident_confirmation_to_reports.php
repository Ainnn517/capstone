<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// After the barangay marks a report Resolved, the resident confirms the fix or reopens
// the report. resident_confirmed_at records the confirmation; reopen_count limits reopening.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ISSUE_REPORT', 'resident_confirmed_at')) {
            return;
        }
        DB::statement("ALTER TABLE ISSUE_REPORT
            ADD COLUMN resident_confirmed_at DATETIME NULL AFTER date_resolved,
            ADD COLUMN reopen_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER resident_confirmed_at,
            ADD CONSTRAINT chk_report_confirmed_resolved CHECK (resident_confirmed_at IS NULL OR status = 'Resolved')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ISSUE_REPORT DROP CHECK chk_report_confirmed_resolved');
        DB::statement('ALTER TABLE ISSUE_REPORT DROP COLUMN reopen_count, DROP COLUMN resident_confirmed_at');
    }
};
