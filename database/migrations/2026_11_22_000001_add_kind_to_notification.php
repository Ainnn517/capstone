<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Updates page: "own" = about the resident's own report (My Reports tab),
// "street" = a problem on the resident's street being worked on or fixed (My Street tab).
// Existing notifications are all about the resident's own reports, so they become "own".
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('NOTIFICATION', 'kind')) {
            return;
        }
        DB::statement("ALTER TABLE NOTIFICATION
            ADD COLUMN kind ENUM('own','street') NOT NULL DEFAULT 'own' AFTER type,
            ADD INDEX idx_notification_user_kind (user_id, kind, is_read)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE NOTIFICATION DROP INDEX idx_notification_user_kind');
        DB::statement('ALTER TABLE NOTIFICATION DROP COLUMN kind');
    }
};
