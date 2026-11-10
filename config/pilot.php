<?php

return [
    'admin' => [
        'username' => env('PILOT_ADMIN_USERNAME'),
        'email' => env('PILOT_ADMIN_EMAIL'),
        'password' => env('PILOT_ADMIN_PASSWORD'),
        'name' => env('PILOT_ADMIN_NAME', 'Pilot Admin'),
    ],
    'resident' => [
        'username' => env('PILOT_RESIDENT_USERNAME'),
        'email' => env('PILOT_RESIDENT_EMAIL'),
        'password' => env('PILOT_RESIDENT_PASSWORD'),
        'name' => env('PILOT_RESIDENT_NAME', 'Pilot Resident'),
    ],
];
