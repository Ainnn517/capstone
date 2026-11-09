<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    // Add the default categories, teams, and optional pilot accounts.
    public function run(): void
    {
        // Add or refresh the report categories and severity weights.
        foreach ([
            ['Sanitation', 1],
            ['Clogged Drainage', 3],
            ['Streetlights', 1],
            ['Road Damage', 3],
            ['Public Safety', 5],
            ['Others', 1],
        ] as [$name, $severity]) {
            Category::updateOrCreate(['name' => $name], ['severity_weight' => $severity]);
        }

        // Add the response teams if they are not already present.
        foreach ([
            'Field Team A',
            'Field Team B',
            'Purok Leader',
            'Barangay Safety Team',
            'Maintenance & Engineering Crew',
        ] as $name) {
            Team::firstOrCreate(['name' => $name]);
        }

        $this->seedPilotUser('admin');
        $this->seedPilotUser('resident');
    }

    // Add a pilot account when its environment settings are provided.
    private function seedPilotUser(string $role): void
    {
        $prefix = 'PILOT_'.strtoupper($role).'_';
        $username = env($prefix.'USERNAME');
        $email = env($prefix.'EMAIL');
        $password = env($prefix.'PASSWORD');

        if (! is_string($username) || $username === '' || ! is_string($email) || $email === ''
            || ! is_string($password) || strlen($password) < 8) {
            return;
        }

        User::firstOrCreate(
            ['username' => $username],
            [
                'full_name' => env($prefix.'NAME', 'Pilot '.ucfirst($role)),
                'email' => $email,
                'password_hash' => Hash::make($password),
                'role' => $role,
            ],
        );
    }
}
