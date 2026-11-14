<?php

// Creates the fixed accounts the browser tests sign in with. Runs only against the
// dedicated test database (the E2E harness passes DB_DATABASE=barangay_reporting_test).

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$base = dirname(__DIR__, 3);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'barangay_reporting_test') {
    fwrite(STDERR, "Refusing to create E2E accounts outside barangay_reporting_test.\n");
    exit(1);
}

foreach ([
    ['e2eadmin', 'E2E Administrator', 'e2e-admin@example.test', 'admin'],
    ['e2eresident', 'E2E Resident', 'e2e-resident@example.test', 'resident'],
] as [$username, $name, $email, $role]) {
    $user = new User(['username' => $username, 'full_name' => $name, 'email' => $email, 'password_hash' => Hash::make('E2e-password123!')]);
    $user->forceFill(['role' => $role, 'account_status' => 'approved', 'email_verified_at' => now()])->save();
}

echo "ok\n";
