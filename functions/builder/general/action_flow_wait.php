<?php
declare(strict_types=1);
# PFAD: /functions/builder/general/action_flow_wait.php

return [
    'type' => 'action.flow.wait',
    'category' => 'action',
    'title' => 'Wait',
    'description' => 'Wartet eine bestimmte Zeit, bevor die naechste Aktion ausgefuehrt wird.',
    'icon' => 'clock',
    'color' => 'gray',
    'ports' => [
        'inputs' => [
            'in',
        ],
        'outputs' => [
            ' ',
        ],
    ],
    'defaults' => [],
    'properties' => [
        [
            'key' => 'duration',
            'type' => 'number',
            'label' => 'Duration (ms)',
            'placeholder' => '1000',
            'help' => 'How many milliseconds to wait before continuing (max 14000).',
            'min' => 100,
            'max' => 14000,
            'required' => true,
        ],
    ],
];
