<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 'Withdrawn': a resident cancelled their report before a team was dispatched.
return new class extends Migration
{
    private const WITH = "'Submitted', 'Validated', 'Dispatched', 'Resolved', 'Rejected', 'Withdrawn'";

    private const WITHOUT = "'Submitted', 'Validated', 'Dispatched', 'Resolved', 'Rejected'";

    public function up(): void
    {
        DB::statement('ALTER TABLE ISSUE_REPORT MODIFY status ENUM('.self::WITH.") NOT NULL DEFAULT 'Submitted'");
        DB::statement('ALTER TABLE STATUS_LOG MODIFY old_status ENUM('.self::WITH.') NULL, MODIFY new_status ENUM('.self::WITH.') NOT NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE ISSUE_REPORT SET status = 'Rejected' WHERE status = 'Withdrawn'");
        DB::statement("UPDATE STATUS_LOG SET new_status = 'Rejected' WHERE new_status = 'Withdrawn'");
        DB::statement("UPDATE STATUS_LOG SET old_status = 'Rejected' WHERE old_status = 'Withdrawn'");
        DB::statement('ALTER TABLE ISSUE_REPORT MODIFY status ENUM('.self::WITHOUT.") NOT NULL DEFAULT 'Submitted'");
        DB::statement('ALTER TABLE STATUS_LOG MODIFY old_status ENUM('.self::WITHOUT.') NULL, MODIFY new_status ENUM('.self::WITHOUT.') NOT NULL');
    }
};
