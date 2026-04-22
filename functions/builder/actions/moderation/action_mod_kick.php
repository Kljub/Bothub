<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/moderation/action_mod_kick.php

return [
    'type' => 'action.mod.kick',
    'category' => 'action',
    'title' => 'Kick Member',
    'description' => 'Kicke ein Mitglied vom Server.',
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
            'key' => 'reason',
            'type' => 'textarea',
            'label' => 'Grund',
            'placeholder' => '',
            'required' => false,
        ],
    ],
];
