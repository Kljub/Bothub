<?php
declare(strict_types=1);
# PFAD: /functions/builder/general/trigger_timed.php

return [
    'type' => 'trigger.timed',
    'category' => 'trigger',
    'title' => 'Timed Event Trigger',
    'subtitle' => 'Schedule / Interval',
    'description' => 'Startet einen Flow nach einem Zeitplan oder in regelmäßigen Abständen.',
    'icon' => 'clock',
    'color' => 'green',
    'ports' => [
        'inputs' => [],
        'outputs' => [
            ' ',
        ],
    ],
    'defaults' => [
        'event_name' => '',
        'event_type' => 'interval',
        'interval_seconds' => 0,
        'interval_minutes' => 0,
        'interval_hours' => 0,
        'interval_days' => 0,
        'week_days' => [
            'Mon',
            'Tue',
            'Wed',
            'Thu',
            'Fri',
            'Sat',
            'Sun',
        ],
        'schedule_time' => '00:00',
        'schedule_days' => [
            'Mon',
            'Tue',
            'Wed',
            'Thu',
            'Fri',
            'Sat',
            'Sun',
        ],
    ],
    'properties' => [],
];
