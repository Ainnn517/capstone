<?php

return [
    'weights' => [
        'report_count' => 3,
        'average_age_days' => 1,
        'severity_weight' => 1,
        'critical_report' => 5,
    ],
    'high_urgency_threshold' => 8,
    'team_by_category' => [
        'Sanitation' => 'Purok Leader',
        'Clogged Drainage' => 'Maintenance & Engineering Crew',
        'Streetlights' => 'Maintenance & Engineering Crew',
        'Road Damage' => 'Maintenance & Engineering Crew',
        'Public Safety' => 'Barangay Safety Team',
        'Others' => 'Field Team A',
    ],
    'action_by_category' => [
        'Sanitation' => 'Coordinate a sanitation inspection and cleanup.',
        'Clogged Drainage' => 'Inspect the drainage and schedule clearing.',
        'Streetlights' => 'Inspect the affected lighting infrastructure.',
        'Road Damage' => 'Assess road safety and schedule repairs.',
        'Public Safety' => 'Coordinate a safety assessment with the barangay team.',
        'Others' => 'Review the reports and identify the appropriate response.',
    ],
];
