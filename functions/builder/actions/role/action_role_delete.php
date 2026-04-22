<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/role/action_role_delete.php

return [
    'type' => 'action.role.delete',
    'category' => 'action',
    'title' => 'Delete a Role',
    'description' => 'Lösche eine Rolle.',
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
            'key' => 'role_id',
            'type' => 'text',
            'label' => 'Rollen-ID',
            'placeholder' => '',
            'required' => true,
        ],
    ],
];
