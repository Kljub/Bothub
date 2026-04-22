<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/moderation/action_mod_nickname.php

return [
    'type' => 'action.mod.nickname',
    'category' => 'action',
    'title' => 'Change Member\'s Nickname',
    'description' => 'Ändere den Nicknamen eines Mitglieds.',
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
            'key' => 'nickname',
            'type' => 'text',
            'label' => 'Neuer Nickname (leer = zurücksetzen)',
            'placeholder' => '',
            'required' => false,
        ],
    ],
];
