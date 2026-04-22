<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/moderation/action_mod_ban.php

return [
    'type' => 'action.mod.ban',
    'category' => 'action',
    'title' => 'Ban Member',
    'description' => 'Banne ein Mitglied vom Server.',
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
        [
            'key' => 'delete_days',
            'type' => 'text',
            'label' => 'Nachrichten löschen (Tage)',
            'placeholder' => '0',
            'required' => false,
        ],
    ],
];
