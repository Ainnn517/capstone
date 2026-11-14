<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// The admin Evaluation page was removed; drop its table where it was created.
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('EVALUATION_LABEL');
    }

    public function down(): void
    {
        // Not recreated: the feature no longer exists.
    }
};
