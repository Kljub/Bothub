<?php
declare(strict_types=1);
# PFAD: /functions/builder/conditions/condition_chance.php

return [
    'type' => 'condition.chance',
    'category' => 'condition',
    'title' => 'Chance Condition',
    'description' => 'Tritt mit einer bestimmten Wahrscheinlichkeit ein.',
    'icon' => 'condition',
    'color' => 'green',
    'ports' => [
        'inputs' => [
            [
                'key' => 'in',
                'label' => 'Input',
                'kind' => 'flow',
                'max_connections' => 1,
            ],
        ],
        'outputs' => [
            'true',
            'false',
        ],
    ],
    'defaults' => [],
    'properties' => [
        [
            'key' => 'percent',
            'type' => 'text',
            'label' => 'Wahrscheinlichkeit (%)',
            'placeholder' => '50',
            'required' => true,
        ],
    ],
];
