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
        // Add or refresh the report categories and severity weights. Descriptions are shown to
        // admins in Reference Data and help the AI choose a category; they are only filled in
        // when empty, so wording an admin has changed is kept.
        foreach ([
            ['Sanitation', 1, 'Uncollected garbage, illegal dumping, littering, foul smells, and dead animals in public places.'],
            ['Clogged Drainage', 3, 'Blocked or overflowing canals, drains, and esteros, and street flooding caused by poor drainage.'],
            ['Streetlights', 1, 'Street lamps or lamp posts that are broken, flickering, or not lit, leaving streets dark at night.'],
            ['Road Damage', 3, 'Potholes, cracks, sinkholes, and damaged roads or sidewalks.'],
            ['Public Safety', 5, "Threats to people's safety: crime or theft, fights and disturbances, open manholes, exposed or fallen electrical wires, fires, and stray or aggressive animals."],
            ['Others', 1, 'Concerns that do not fit any other category, such as noise complaints.'],
        ] as [$name, $severity, $description]) {
            $category = Category::updateOrCreate(['name' => $name], ['severity_weight' => $severity]);
            if (blank($category->description)) {
                $category->update(['description' => $description]);
            }
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

    // Add a pilot account when its environment settings are provided. Never in production
    // (use `php artisan admin:create` there), and never changes an account that already exists.
    private function seedPilotUser(string $role): void
    {
        if (app()->isProduction()) {
            return;
        }

        $username = config('pilot.'.$role.'.username');
        $email = config('pilot.'.$role.'.email');
        $password = config('pilot.'.$role.'.password');

        if (! is_string($username) || $username === '' || ! is_string($email) || $email === ''
            || ! is_string($password) || strlen($password) < 12) {
            return;
        }

        $user = User::firstOrNew(['username' => $username]);
        if ($user->exists) {
            return;
        }
        $user->fill([
            'full_name' => config('pilot.'.$role.'.name'),
            'email' => $email,
            'password_hash' => Hash::make($password),
        ]);
        $user->forceFill([
            'role' => $role,
            'admin_level' => $role === 'admin' ? 'super' : null,
            'account_status' => 'approved',
            'email_verified_at' => now(),
        ])->save();
    }
}
