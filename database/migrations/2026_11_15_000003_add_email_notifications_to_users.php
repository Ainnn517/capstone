<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Residents get an email when the barangay changes a report's status. On by default;
// they can turn it off in Account or with the link in any of those emails.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('USERS', 'email_notifications')) {
            return;
        }
        DB::statement('ALTER TABLE USERS
            ADD COLUMN email_notifications TINYINT(1) NOT NULL DEFAULT 1 AFTER address_details,
            ADD CONSTRAINT chk_users_email_notifications CHECK (email_notifications IN (0, 1))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE USERS DROP CHECK chk_users_email_notifications');
        DB::statement('ALTER TABLE USERS DROP COLUMN email_notifications');
    }
};
