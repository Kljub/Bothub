<?php
declare(strict_types=1);
# PFAD: /functions/builder/action_wait.php

return [
    'type' => 'action.wait',
    'category' => 'action',
    'title' => 'Wait',
    'description' => 'Wartet eine bestimmte Zeit bevor der Flow weiterlaeuft.',
    'icon' => 'clock',
    'color' => 'purple',
    'ports' => [
        'inputs' => [
            [
                'key' => 'input',
                'label' => 'Input',
                'kind' => 'flow',
                'max_connections' => 1,
            ],
        ],
        'outputs' => [
            [
                'key' => 'next',
                'label' => 'Next',
                'kind' => 'flow',
                'max_connections' => 1,
            ],
        ],
    ],
    'defaults' => [
        'duration_ms' => 1000,
    ],
    'properties' => [
        [
            'key' => 'duration_ms',
            'type' => 'number',
            'label' => 'Wartezeit (ms)',
            'required' => true,
            'min' => 0,
            'max' => 600000,
            'step' => 100,
        ],
    ],
];
