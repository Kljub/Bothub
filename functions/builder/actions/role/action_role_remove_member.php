<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/role/action_role_remove_member.php

return [
    'type' => 'action.role.remove_from_member',
    'category' => 'action',
    'title' => 'Remove Roles from a Member',
    'description' => 'Entferne Rollen von einem Mitglied.',
    'icon' => 'action',
    'color' => 'purple',
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
            'key' => 'role_ids',
            'type' => 'text',
            'label' => 'Rollen-IDs (kommagetrennt)',
            'placeholder' => '123,456',
            'required' => true,
        ],
    ],
];
