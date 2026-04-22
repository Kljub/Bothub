<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/moderation/action_mod_timeout.php

return [
    'type' => 'action.mod.timeout',
    'category' => 'action',
    'title' => 'Timeout a Member',
    'description' => 'Setze ein Mitglied in den Timeout.',
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
            'key' => 'user_id',
            'type' => 'text',
            'label' => 'User ID oder {option.user}',
            'placeholder' => '',
            'required' => true,
        ],
        [
            'key' => 'duration',
            'type' => 'text',
            'label' => 'Dauer (Sekunden)',
            'placeholder' => '300',
            'required' => true,
        ],
        [
            'key' => 'reason',
            'type' => 'textarea',
            'label' => 'Grund',
            'placeholder' => '',
            'required' => false,
        ],
    ],
];
