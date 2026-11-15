<?php

// The barangay this installation serves. To use the system for another barangay, change
// these values and replace public/frontend/data/barangay-boundary.json with its outline.
return [
    'name' => 'Barangay Langgam',
    'place' => 'San Pedro, Laguna',

    // Map pins on reports must fall inside this box: the barangay's outline plus about 1 km,
    // so residents near the border can still pin a spot just outside it.
    'pin_bounds' => [
        'south' => 14.3115,
        'north' => 14.3458,
        'west' => 120.9975,
        'east' => 121.0335,
    ],
];
