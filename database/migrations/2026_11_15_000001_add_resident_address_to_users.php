<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Residents give their home address when signing up, so administrators can check
// residency against barangay records before approving the account.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('USERS', 'address_location_id')) {
            return;
        }
        DB::statement('ALTER TABLE USERS
            ADD COLUMN address_location_id INT UNSIGNED NULL AFTER contact_number,
            ADD COLUMN address_details VARCHAR(150) NULL AFTER address_location_id,
            ADD KEY idx_users_address_location (address_location_id),
            ADD CONSTRAINT fk_users_address_location FOREIGN KEY (address_location_id) REFERENCES LOCATION (location_id)
                ON UPDATE CASCADE ON DELETE SET NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE USERS DROP FOREIGN KEY fk_users_address_location');
        DB::statement('ALTER TABLE USERS DROP KEY idx_users_address_location, DROP COLUMN address_details, DROP COLUMN address_location_id');
    }
};
