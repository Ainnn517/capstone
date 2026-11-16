<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Each audit entry records where the action came from: the client IP address (as passed on
// by the Cloudflare tunnel) and the browser. Entries made by scheduled tasks leave them empty.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ADMIN_AUDIT_LOG', 'ip_address')) {
            return;
        }
        DB::statement('ALTER TABLE ADMIN_AUDIT_LOG
            ADD COLUMN ip_address VARCHAR(45) NULL AFTER details,
            ADD COLUMN user_agent VARCHAR(255) NULL AFTER ip_address,
            ADD KEY idx_admin_audit_action_time (action, created_at)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ADMIN_AUDIT_LOG
            DROP KEY idx_admin_audit_action_time,
            DROP COLUMN user_agent,
            DROP COLUMN ip_address');
    }
};
