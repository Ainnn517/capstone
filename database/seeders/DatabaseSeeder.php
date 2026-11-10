<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Location;
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

        foreach (config('dss.team_by_category', []) as $categoryName => $teamName) {
            $team = Team::query()->where('name', $teamName)->first();
            Category::query()->where('name', $categoryName)->update([
                'recommended_team_id' => $team?->team_id,
            ]);
        }

        foreach (config('locations', []) as $area => $streets) {
            foreach ($streets ?: ['Area-wide'] as $street) {
                Location::updateOrCreate(
                    ['name' => $street, 'area_name' => $area],
                    ['is_active' => true],
                );
            }
        }

        $this->seedPilotUser('admin');
        $this->seedPilotUser('resident');
    }

    // Add a pilot account when its environment settings are provided.
    private function seedPilotUser(string $role): void
    {
        $username = config('pilot.'.$role.'.username');
        $email = config('pilot.'.$role.'.email');
        $password = config('pilot.'.$role.'.password');

        if (! is_string($username) || $username === '' || ! is_string($email) || $email === ''
            || ! is_string($password) || strlen($password) < 12) {
            return;
        }

        $user = User::firstOrNew(['username' => $username]);
        $user->fill([
            'full_name' => config('pilot.'.$role.'.name'),
            'email' => $email,
            'password_hash' => Hash::make($password),
        ]);
        $user->forceFill([
            'role' => $role,
            'account_status' => 'approved',
            'email_verified_at' => now(),
        ])->save();
    }
}
