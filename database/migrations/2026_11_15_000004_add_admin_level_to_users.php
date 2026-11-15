<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Administrators get one of three levels: super (everything, including admin accounts),
// staff (works on reports and sign-ups), viewer (read-only, e.g. the barangay captain).
// Existing administrators become super administrators.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('USERS', 'admin_level')) {
            return;
        }
        DB::statement("ALTER TABLE USERS ADD COLUMN admin_level ENUM('super', 'staff', 'viewer') NULL AFTER role");
        DB::statement("UPDATE USERS SET admin_level = 'super' WHERE role = 'admin'");
        DB::statement("ALTER TABLE USERS ADD CONSTRAINT chk_users_admin_level CHECK ((role = 'admin') = (admin_level IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE USERS DROP CHECK chk_users_admin_level');
        DB::statement('ALTER TABLE USERS DROP COLUMN admin_level');
    }
};
