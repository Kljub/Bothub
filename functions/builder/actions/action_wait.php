<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/action_wait.php

return [
    'type' => 'action.wait',
    'category' => 'action',
    'title' => 'Wait',
    'description' => 'Wartet eine bestimmte Zeit bevor der Flow weiterlaeuft.',
    'icon' => 'clock',
    'color' => 'purple',
    'ports' => [
        'inputs' => [
            'input',
        ],
        'outputs' => [
            ' ',
        ],
    ],
    'defaults' => [
        'duration_ms' => 1000,
    ],
    'properties' => [
        'duration_ms',
    ],
];
