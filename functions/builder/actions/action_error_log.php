<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/action_error_log.php

return [
    'type' => 'action.utility.error_log',
    'category' => 'action',
    'title' => 'Send an Error Log Message',
    'description' => 'Sende eine Fehlermeldung an den konfigurierten Log-Kanal.',
    'icon' => 'action',
    'color' => 'red',
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
            ' ',
        ],
    ],
    'defaults' => [],
    'properties' => [
        [
            'key' => 'message',
            'type' => 'textarea',
            'label' => 'Log Message',
            'placeholder' => 'Fehlerbeschreibung…',
            'required' => true,
        ],
    ],
];
