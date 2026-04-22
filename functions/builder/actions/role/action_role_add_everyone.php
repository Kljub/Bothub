<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/role/action_role_add_everyone.php

return [
    'type' => 'action.role.add_to_everyone',
    'category' => 'action',
    'title' => 'Add Roles to Everyone',
    'description' => 'Füge allen Mitgliedern eine Rolle hinzu.',
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
            'key' => 'role_ids',
            'type' => 'text',
            'label' => 'Rollen-IDs (kommagetrennt)',
            'placeholder' => '123,456',
            'required' => true,
        ],
    ],
];
