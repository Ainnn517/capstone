<?php

/*
|--------------------------------------------------------------------------
| Updates page: "My Street"
|--------------------------------------------------------------------------
|
| When the barangay dispatches a team to, or resolves, a report, residents whose saved
| street is the same get a short update ("A team is working on a Clogged Drainage issue
| on Lawaan Street."). Only the category, street and status are shared: never who reported
| it, the report text, photos, landmark or map pin. Street updates are never emailed.
|
*/

return [
    'street_updates_enabled' => (bool) env('STREET_UPDATES_ENABLED', true),

    // Not shared with the street (for example theft, fights, suspicious persons).
    'excluded_categories' => array_filter(array_map('trim', explode(',', (string) env('STREET_UPDATES_EXCLUDED_CATEGORIES', 'Public Safety')))),

    // Safety limit on how many residents one update is sent to.
    'max_recipients' => 500,
];
