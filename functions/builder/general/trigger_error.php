<?php
declare(strict_types=1);
# PFAD: /functions/builder/general/trigger_error.php

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
            ' ',
        ],
    ],
    'defaults' => [],
    'properties' => [
        [
            'key' => 'display_name',
            'type' => 'text',
            'label' => 'Display Name',
            'placeholder' => 'Error Handler',
            'required' => false,
        ],
        [
            'key' => 'enabled',
            'type' => 'select',
            'label' => 'Enabled',
            'help' => 'Enable or disable this error handler.',
            'options' => [
                [
                    'value' => '1',
                    'label' => 'Enabled',
                ],
                [
                    'value' => '0',
                    'label' => 'Disabled',
                ],
            ],
        ],
    ],
];
