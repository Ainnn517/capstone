<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// Adds (or removes) fictional demo residents, each with one report from the last six months,
// so the analytics, DSS, hotspot map and "Ask the data" have realistic history to show.
//
// - Demo accounts are recognised by their @example.com email (a reserved domain) and are
//   removed with --remove, together with their reports and history.
// - Rows are written directly, so no emails or notifications are sent.
// - Demo accounts cannot sign in (random password nobody knows).
class DemoResidents extends Command
{
    protected $signature = 'demo:residents
        {--remove : Delete every demo resident (@example.com) and their reports}
        {--force : Do not ask for confirmation}';

    protected $description = 'Add or remove fictional demo residents with one realistic report each (last 6 months)';

    public const DEMO_DOMAIN = 'example.com';

    /**
     * Name, street, area, category, description, landmark, days ago, outcome, hours to resolve, cluster.
     * Outcomes: resolved, confirmed (resolved + resident confirmed), rejected, withdrawn,
     * dispatched, validated, submitted.
     */
    private const REPORTS = [
        ['Rosario Dela Peña', 'Sampaguita Street', 'St. Joseph Village 9', 'Sanitation', 'Hindi nakolekta ang basura sa kanto ng Sampaguita mula pa noong Lunes, nangangamoy na at may langaw.', 'Kanto malapit sa sari-sari store', 172, 'confirmed', 30, null],
        ['Eduardo Manalo', 'Mango Street', 'St. Joseph Village 10 - Phase 1', 'Streetlights', 'Two streetlights along Mango Street are not working. The road is very dark at night and people walk home from the terminal.', null, 160, 'confirmed', 70, 'mango'],
        ['Marites Villanueva', 'Area-wide', 'Southern Heights', 'Clogged Drainage', 'Barado ang kanal sa may entrance ng Southern Heights, umaapaw kapag malakas ang ulan.', 'Main entrance gate', 141, 'resolved', 90, 'southern'],
        ['Jun Ramos', 'Lawaan Street', 'St. Joseph Village 10 - Phase 2', 'Clogged Drainage', 'Nalunop ang dalan sa Lawaan kay barado ang kanal, abot tuhod ang tubig.', null, 133, 'confirmed', 110, 'lawaan'],
        ['Liza Bautista', 'Cedar Street', 'St. Joseph Village 10 - Phase 3', 'Road Damage', 'Big pothole in the middle of Cedar Street. Tricycles keep swerving into the other lane to avoid it.', null, 126, 'resolved', 140, 'cedar'],
        ['Carlo Mendoza', 'Gumamela Street', 'St. Joseph Village 9', 'Public Safety', 'May nakalaylay na kable ng kuryente sa may puno, delikado sa mga batang dumadaan.', 'Malapit sa malaking puno ng mangga', 118, 'confirmed', 20, null],
        ['Anna Reyes', 'Area-wide', 'Southern Heights', 'Clogged Drainage', 'Floodwater again near the basketball court because the drainage grate is clogged with leaves and plastic.', 'Basketball court', 104, 'resolved', 100, 'southern'],
        ['Ramon Castillo', 'Lawaan Street', 'St. Joseph Village 10 - Phase 2', 'Clogged Drainage', 'Umaapaw na naman ang kanal sa Lawaan, pumapasok na ang tubig sa mga bakuran.', null, 96, 'confirmed', 125, 'lawaan'],
        ['Grace Tolentino', 'Molave Street', 'St. Joseph Village 10 - Phase 3', 'Sanitation', 'Someone keeps dumping construction debris and garbage on the vacant lot.', 'Vacant lot beside the chapel', 88, 'resolved', 60, null],
        ['Noel Aquino', 'Rose Street', 'St. Joseph Village 9', 'Others', 'Malakas na videoke tuwing gabi hanggang madaling-araw, hindi makatulog ang mga bata.', null, 81, 'rejected', 0, null],
        ['Teresita Garcia', 'Mango Street', 'St. Joseph Village 10 - Phase 1', 'Streetlights', 'The streetlight in front of the waiting shed on Mango Street is flickering and goes off after 10 pm.', 'Waiting shed', 74, 'confirmed', 80, 'mango'],
        ['Benjie Santiago', 'Area-wide', 'St. Joseph Ridgeview - Phase 1', 'Road Damage', 'Cracked and sunken road near the Ridgeview gate after the heavy rains.', 'Ridgeview gate', 66, 'resolved', 160, null],
        ['Maricel Flores', 'Kamagong Street', 'St. Joseph Village 10 - Phase 2', 'Public Safety', 'Stray dogs are chasing children near the school gate every morning.', 'Elementary school gate', 58, 'resolved', 30, null],
        ['Arnel Domingo', 'Sunflower Street', 'St. Joseph Village 9', 'Sanitation', 'Daghan basura sa kilid sa kalsada, wala nakuha sa trak sulod sa usa ka semana.', null, 49, 'confirmed', 40, null],
        ['Jennifer Ocampo', 'Area-wide', 'Southern Heights', 'Clogged Drainage', 'Clogged canal behind the chapel. Stagnant water and lots of mosquitoes now.', 'Behind the chapel', 41, 'resolved', 85, 'southern'],
        ['Ronaldo Pascual', 'Navidad Avenue', 'St. Joseph Village 9', 'Road Damage', 'Malaking lubak sa Navidad Avenue malapit sa tindahan, delikado sa mga nagmomotor sa gabi.', 'Tapat ng tindahan', 35, 'confirmed', 120, null],
        ['Cristina Lim', 'Mango Street', 'St. Joseph Village 10 - Phase 1', 'Streetlights', 'The lamp post near the corner of Mango and Coconut Street has been out for a week.', 'Corner of Mango and Coconut', 27, 'resolved', 75, 'mango'],
        ['Danilo Navarro', 'Almond Street', 'St. Joseph Village 10 - Phase 3', 'Sanitation', 'Overflowing garbage bins at the corner, the smell reaches the houses.', null, 22, 'withdrawn', 0, null],
        ['Victor Salazar', 'Cedar Street', 'St. Joseph Village 10 - Phase 3', 'Road Damage', 'The pothole on Cedar Street is back and getting bigger after the rains.', null, 19, 'dispatched', 0, 'cedar'],
        ['Michelle Torres', 'Lawaan Street', 'St. Joseph Village 10 - Phase 2', 'Clogged Drainage', 'Barado na naman ang kanal sa Lawaan Street, bumabaha tuwing umuulan.', null, 12, 'dispatched', 0, 'lawaan'],
        ['Patrick Soriano', 'Kamagong Street', 'St. Joseph Village 10 - Phase 2', 'Others', 'Request to repaint the pedestrian lane in front of the elementary school.', 'Elementary school', 9, 'validated', 0, null],
        ['Ferdinand Cruz', 'Lawaan Street', 'St. Joseph Village 10 - Phase 2', 'Clogged Drainage', 'Tubig-baha sa Lawaan, hindi makadaan ang mga sasakyan at tricycle.', null, 6, 'validated', 0, 'lawaan'],
        ['Josephine Mercado', 'Lawaan Street', 'St. Joseph Village 10 - Phase 2', 'Clogged Drainage', 'The canal near the Lawaan Street corner is overflowing again and water is entering the houses.', 'Lawaan corner', 2, 'submitted', 0, 'lawaan'],
        ['Leah Fernandez', 'Gumamela Street', 'St. Joseph Village 9', 'Public Safety', 'Open manhole without a cover beside the waiting shed, very dangerous at night.', 'Waiting shed', 1, 'submitted', 0, null],
    ];

    private const REMARKS = [
        'Validated' => ['Report checked and confirmed.', 'Validated. Forwarding to the team.', 'Confirmed by the purok leader.'],
        'Dispatched' => ['Team assigned and on the way.', 'Scheduled for inspection.', 'Crew dispatched to the site.'],
        'Resolved' => ['Fixed and checked on site.', 'Cleared. Thank you for reporting.', 'Work completed by the team.'],
    ];

    public function handle(): int
    {
        if ($this->option('remove')) {
            return $this->remove();
        }

        if (DB::table('USERS')->where('role', 'resident')->where('email', 'like', '%@'.self::DEMO_DOMAIN)->exists()) {
            $this->error('Demo residents already exist. Run with --remove first to recreate them.');

            return self::FAILURE;
        }

        $admin = DB::table('USERS')->where('role', 'admin')->orderByRaw("admin_level = 'super' DESC")->orderBy('user_id')->value('user_id');
        $locations = DB::table('LOCATION')->get(['location_id', 'name', 'area_name'])
            ->keyBy(fn ($row): string => $row->name.'|'.$row->area_name);
        $categories = DB::table('CATEGORY')->pluck('category_id', 'name');
        $teams = DB::table('TEAM')->pluck('team_id', 'name');
        $teamByCategory = config('dss.team_by_category', []);

        foreach (self::REPORTS as $row) {
            if (! isset($locations[$row[1].'|'.$row[2]], $categories[$row[3]])) {
                $this->error("Missing location or category: {$row[1]}, {$row[2]} / {$row[3]}. Seed the database first.");

                return self::FAILURE;
            }
        }
        if ($admin === null) {
            $this->error('No administrator account found to record status changes.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(sprintf('Add %d demo residents with one report each to the "%s" database? No emails are sent.', count(self::REPORTS), DB::getDatabaseName()))) {
            return self::SUCCESS;
        }

        mt_srand(2026); // same pins and times every run
        $boundary = $this->boundary();
        $clusterCenters = [];
        $now = CarbonImmutable::now();

        DB::transaction(function () use ($admin, $locations, $categories, $teams, $teamByCategory, $boundary, &$clusterCenters, $now): void {
            foreach (self::REPORTS as $index => [$name, $street, $area, $category, $description, $landmark, $daysAgo, $outcome, $hours, $cluster]) {
                $location = $locations[$street.'|'.$area];
                $submitted = $now->subDays($daysAgo)->setTime(mt_rand(6, 20), mt_rand(0, 59), mt_rand(0, 59));
                if ($submitted->greaterThan($now)) {
                    $submitted = $now->subHours(mt_rand(2, 10));
                }
                $joined = $submitted->subDays(mt_rand(3, 40));

                $userId = DB::table('USERS')->insertGetId([
                    'username' => Str::slug($name, '.').'.'.($index + 1),
                    'full_name' => $name,
                    'email' => Str::slug($name, '.').'@'.self::DEMO_DOMAIN,
                    'password_hash' => Hash::make(Str::random(40)),
                    'role' => 'resident',
                    'contact_number' => null,
                    'address_location_id' => $location->location_id,
                    'address_details' => 'Blk '.mt_rand(1, 12).' Lot '.mt_rand(1, 30),
                    'email_notifications' => 0,
                    'created_at' => $joined,
                    'account_status' => 'approved',
                    'privacy_consent_at' => $joined,
                    'privacy_notice_version' => '2026-11-16',
                    'email_verified_at' => $joined->addMinutes(mt_rand(2, 30)),
                ]);

                // About two thirds of residents pin the spot on the map; reports in a cluster sit close together.
                [$latitude, $longitude] = [null, null];
                if ($cluster !== null || mt_rand(1, 3) > 1) {
                    if ($cluster !== null) {
                        $clusterCenters[$cluster] ??= $this->randomPointInside($boundary);
                        [$latitude, $longitude] = $this->near($clusterCenters[$cluster], 60);
                    } else {
                        [$latitude, $longitude] = $this->randomPointInside($boundary);
                    }
                }

                $teamId = $teams[$teamByCategory[$category] ?? ''] ?? null;
                $steps = $this->steps($outcome, $submitted, (int) $hours, $now);
                $final = end($steps);
                $resolvedAt = $final['status'] === 'Resolved' ? $final['at'] : null;

                $reportId = DB::table('ISSUE_REPORT')->insertGetId([
                    'tracking_code' => 'BR-'.$submitted->utc()->format('ymd-His').'-'.strtoupper(bin2hex(random_bytes(3))),
                    'user_id' => $userId,
                    'category_id' => $categories[$category],
                    'team_id' => in_array($final['status'], ['Dispatched', 'Resolved'], true) ? $teamId : null,
                    'description' => $description,
                    'location_id' => $location->location_id,
                    'landmark' => $landmark,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'status' => $final['status'],
                    'date_submitted' => $submitted,
                    'date_resolved' => $resolvedAt,
                    'resident_confirmed_at' => $outcome === 'confirmed' && $resolvedAt && $resolvedAt->addDay()->lessThan($now)
                        ? $resolvedAt->addHours(mt_rand(6, 48)) : null,
                    'reopen_count' => 0,
                    'archived_at' => null,
                    'archived_by' => null,
                ]);

                $previous = null;
                foreach ($steps as $step) {
                    DB::table('STATUS_LOG')->insert([
                        'report_id' => $reportId,
                        'changed_by' => in_array($step['status'], ['Submitted', 'Withdrawn'], true) ? $userId : $admin,
                        'old_status' => $previous,
                        'new_status' => $step['status'],
                        'remarks' => $step['remarks'],
                        'changed_at' => $step['at'],
                    ]);
                    $previous = $step['status'];
                }
            }
        });

        $this->info(sprintf('Added %d demo residents and reports (no emails sent). Remove them with: php artisan demo:residents --remove', count(self::REPORTS)));

        return self::SUCCESS;
    }

    /**
     * The status history for an outcome, with realistic gaps between steps.
     *
     * @return list<array{status: string, at: CarbonImmutable, remarks: string}>
     */
    private function steps(string $outcome, CarbonImmutable $submitted, int $hoursToResolve, CarbonImmutable $now): array
    {
        $remark = fn (string $status): string => self::REMARKS[$status][mt_rand(0, count(self::REMARKS[$status]) - 1)];
        $steps = [['status' => 'Submitted', 'at' => $submitted, 'remarks' => 'Report submitted.']];

        if ($outcome === 'rejected') {
            $steps[] = ['status' => 'Rejected', 'at' => $submitted->addHours(mt_rand(2, 8)),
                'remarks' => 'Noise complaints are handled through the barangay tanod and blotter. Please visit the barangay hall.'];

            return $steps;
        }
        if ($outcome === 'withdrawn') {
            $steps[] = ['status' => 'Withdrawn', 'at' => $submitted->addHours(mt_rand(3, 10)),
                'remarks' => 'Withdrawn by the resident: already reported by a neighbor.'];

            return $steps;
        }
        if ($outcome === 'submitted') {
            return $steps;
        }

        $validated = $submitted->addMinutes(mt_rand(40, 360));
        $steps[] = ['status' => 'Validated', 'at' => min($validated, $now), 'remarks' => $remark('Validated')];
        if ($outcome === 'validated') {
            return $steps;
        }

        $dispatched = $validated->addMinutes(mt_rand(30, 600));
        $steps[] = ['status' => 'Dispatched', 'at' => min($dispatched, $now), 'remarks' => $remark('Dispatched')];
        if ($outcome === 'dispatched') {
            return $steps;
        }

        // Resolved: total time is about $hoursToResolve, never before dispatch or in the future.
        $resolved = $submitted->addMinutes((int) round($hoursToResolve * 60 * (0.85 + mt_rand(0, 30) / 100)));
        $resolved = max($resolved, $dispatched->addMinutes(45));
        $steps[] = ['status' => 'Resolved', 'at' => min($resolved, $now->subHour()), 'remarks' => $remark('Resolved')];

        return $steps;
    }

    private function remove(): int
    {
        $userIds = DB::table('USERS')->where('email', 'like', '%@'.self::DEMO_DOMAIN)->where('role', 'resident')->pluck('user_id');
        if ($userIds->isEmpty()) {
            $this->info('No demo residents found.');

            return self::SUCCESS;
        }
        $reportIds = DB::table('ISSUE_REPORT')->whereIn('user_id', $userIds)->pluck('report_id');

        if (! $this->option('force') && ! $this->confirm(sprintf('Delete %d demo residents and %d reports from the "%s" database?', $userIds->count(), $reportIds->count(), DB::getDatabaseName()))) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($userIds, $reportIds): void {
            DB::table('STATUS_LOG')->whereIn('report_id', $reportIds)->delete();
            DB::table('NOTIFICATION')->whereIn('report_id', $reportIds)->orWhereIn('user_id', $userIds)->delete();
            DB::table('ATTACHMENT')->whereIn('report_id', $reportIds)->delete();
            DB::table('ISSUE_REPORT')->whereIn('report_id', $reportIds)->delete();
            DB::table('USERS')->whereIn('user_id', $userIds)->delete();
        });

        $this->info(sprintf('Removed %d demo residents and %d reports.', $userIds->count(), $reportIds->count()));

        return self::SUCCESS;
    }

    /**
     * @return list<array{0: float, 1: float}> [longitude, latitude] points of the barangay outline
     */
    private function boundary(): array
    {
        $path = public_path((string) config('barangay.boundary_file'));
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $geometry = $data['geometry'] ?? ($data['features'][0]['geometry'] ?? null);
        $ring = match ($geometry['type'] ?? null) {
            'Polygon' => $geometry['coordinates'][0],
            'MultiPolygon' => $geometry['coordinates'][0][0],
            default => [],
        };

        return array_map(fn (array $point): array => [(float) $point[0], (float) $point[1]], $ring);
    }

    /**
     * @param  list<array{0: float, 1: float}>  $ring
     * @return array{0: float, 1: float} [latitude, longitude]
     */
    private function randomPointInside(array $ring): array
    {
        if ($ring === []) {
            return [14.3297 + mt_rand(-40, 40) / 10000, 121.0153 + mt_rand(-40, 40) / 10000];
        }
        $lngs = array_column($ring, 0);
        $lats = array_column($ring, 1);
        for ($try = 0; $try < 500; $try++) {
            $lng = min($lngs) + (max($lngs) - min($lngs)) * mt_rand() / mt_getrandmax();
            $lat = min($lats) + (max($lats) - min($lats)) * mt_rand() / mt_getrandmax();
            if ($this->inside($lng, $lat, $ring)) {
                return [round($lat, 7), round($lng, 7)];
            }
        }

        return [14.3297, 121.0153];
    }

    /**
     * A point within $meters of $center.
     *
     * @param  array{0: float, 1: float}  $center  [latitude, longitude]
     * @return array{0: float, 1: float}
     */
    private function near(array $center, int $meters): array
    {
        $offset = $meters / 111_000;

        return [
            round($center[0] + (mt_rand(-100, 100) / 100) * $offset, 7),
            round($center[1] + (mt_rand(-100, 100) / 100) * $offset, 7),
        ];
    }

    /**
     * @param  list<array{0: float, 1: float}>  $ring
     */
    private function inside(float $lng, float $lat, array $ring): bool
    {
        $inside = false;
        for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi)) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
