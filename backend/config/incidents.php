<?php

return [
    // Default SLA (days to resolve) by priority code, used by DueDatePolicy when due_at is not sent.
    'sla_days' => [
        'critical' => (int) env('INCIDENTS_SLA_CRITICAL', 1),
        'high' => (int) env('INCIDENTS_SLA_HIGH', 3),
        'medium' => (int) env('INCIDENTS_SLA_MEDIUM', 5),
        'low' => (int) env('INCIDENTS_SLA_LOW', 10),
    ],
];
