<?php

// The barangay this installation serves. To use the system for another barangay, change
// these values and replace public/frontend/data/barangay-boundary.json with its outline.
return [
    'name' => 'Barangay Langgam',
    'place' => 'San Pedro, Laguna',

    // Outline of the area the barangay serves, drawn on both maps. Report pins must fall
    // inside it (its "pinArea" allows about 50 m of leeway at the edges).
    'boundary_file' => 'frontend/data/barangay-boundary.json',

    // Data Protection Officer named in the privacy notice (RA 10173 requires residents to
    // know whom to contact). Fill in the officer the barangay designated; while the name is
    // empty the notice says "the Barangay Data Protection Officer".
    'privacy_officer' => [
        'name' => null,
        'position' => null,           // for example 'Barangay Secretary'
        'email' => 'appbarangay@gmail.com',
        'phone' => null,
        'office' => null,             // for example 'Barangay Hall, Langgam, San Pedro, Laguna'
    ],
];
