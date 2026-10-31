<?php

return [
    // Default SLA (days to resolve) by priority code, used by DueDatePolicy when due_at is not sent.
    'sla_days' => [
        'critical' => (int) env('INCIDENTS_SLA_CRITICAL', 1),
        'high' => (int) env('INCIDENTS_SLA_HIGH', 3),
        'medium' => (int) env('INCIDENTS_SLA_MEDIUM', 5),
        'low' => (int) env('INCIDENTS_SLA_LOW', 10),
    ],

    // S-08: storage and API are UTC; day-based filters are read in this zone.
    'timezone' => 'America/Bogota',

    // S-09: incidents created by DatabaseSeeder.
    'seed_count' => (int) env('SEED_INCIDENTS', 5000),
];
