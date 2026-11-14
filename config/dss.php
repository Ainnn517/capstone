<?php

return [
    'weights' => [
        'report_count' => 3,
        'average_age_days' => 1,
        'severity_weight' => 1,
    ],
    'high_urgency_threshold' => 8,

    // Target days to resolve an open report, by priority; older open reports are overdue.
    'resolution_target_days' => ['Critical' => 2, 'High' => 5, 'Normal' => 10],

    // Team suggestions learn from resolved reports in this window, once a team has at
    // least this many resolved reports in the category.
    'history_window_days' => 180,
    'history_min_resolved' => 2,
    'hotspot_report_threshold' => 3,
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
