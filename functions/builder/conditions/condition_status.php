<?php
declare(strict_types=1);
# PFAD: /functions/builder/conditions/condition_status.php

return [
    'type' => 'condition.status',
    'category' => 'condition',
    'title' => 'Status Condition',
    'description' => 'Prüfe den aktuellen Status eines Nutzers (falls verfügbar).',
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
            'key' => 'status',
            'type' => 'select',
            'label' => 'Status',
            'options' => [
                'online',
                'idle',
                'dnd',
                'offline',
            ],
            'required' => true,
        ],
    ],
];
