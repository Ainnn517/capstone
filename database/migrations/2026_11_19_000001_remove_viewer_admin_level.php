<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The read-only "viewer" administrator level is removed: every administrator is now either
// super or staff (shown as "Admin"), and both see the whole dashboard. Any viewer accounts
// become staff so they keep their access.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE USERS SET admin_level = 'staff' WHERE admin_level = 'viewer'");
        DB::statement("ALTER TABLE USERS MODIFY COLUMN admin_level ENUM('super', 'staff') NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE USERS MODIFY COLUMN admin_level ENUM('super', 'staff', 'viewer') NULL");
    }
};
