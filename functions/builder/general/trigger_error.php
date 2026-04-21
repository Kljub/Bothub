<?php
declare(strict_types=1);
# PFAD: /functions/builder/trigger_error.php

return [
    'type' => 'utility.error_handler',
    'category' => 'utility',
    'title' => 'Error Handler',
    'subtitle' => 'utility.error_handler',
    'description' => 'Wird ausgefuehrt, wenn ein Fehler im Flow auftritt.',
    'icon' => '?',
    'color' => 'rot',
    'ports' => [
        'inputs' => [],
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
        'display_name' => 'Error Handler',
        'enabled' => true,
    ],
    'properties' => [
        [
            'key' => 'display_name',
            'type' => 'text',
            'label' => 'Anzeigename',
            'required' => true,
            'max_length' => 120,
        ],
        [
            'key' => 'enabled',
            'type' => 'switch',
            'label' => 'Aktiv',
            'required' => false,
        ],
    ],
];