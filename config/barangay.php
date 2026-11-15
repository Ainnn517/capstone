<?php

// The barangay this installation serves. To use the system for another barangay, change
// these values and replace public/frontend/data/barangay-boundary.json with its outline.
return [
    'name' => 'Barangay Langgam',
    'place' => 'San Pedro, Laguna',

    // Outline of the area the barangay serves, drawn on both maps. Report pins must fall
    // inside it (its "pinArea" allows about 50 m of leeway at the edges).
    'boundary_file' => 'frontend/data/barangay-boundary.json',
];
