<?php

namespace App\Support;

// The area the barangay serves, read from the same outline file the maps draw.
class BarangayArea
{
    /** @var list<array{0: float, 1: float}>|null  [longitude, latitude] pairs */
    private static ?array $pinRing = null;

    // Is this spot inside the barangay (with about 50 m of leeway at the edges)?
    // If the outline file is missing, every spot is accepted rather than blocking reports.
    public static function acceptsPin(float $latitude, float $longitude): bool
    {
        $ring = self::pinRing();
        if ($ring === []) {
            return true;
        }

        $inside = false;
        $count = count($ring);
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$x1, $y1] = $ring[$i];
            [$x2, $y2] = $ring[$j];
            if (($y1 > $latitude) !== ($y2 > $latitude)
                && $longitude < ($x2 - $x1) * ($latitude - $y1) / ($y2 - $y1) + $x1) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /** @return list<array{0: float, 1: float}> */
    private static function pinRing(): array
    {
        if (self::$pinRing === null) {
            $file = public_path(config('barangay.boundary_file'));
            $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            $ring = $data['properties']['pinArea'][0] ?? $data['geometry']['coordinates'][0] ?? [];
            self::$pinRing = is_array($ring) ? array_values($ring) : [];
        }

        return self::$pinRing;
    }
}
