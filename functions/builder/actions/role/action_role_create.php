<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/role/action_role_create.php

return [
    'type' => 'action.role.create',
    'category' => 'action',
    'title' => 'Create a Role',
    'description' => 'Erstelle eine neue Rolle.',
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
            'key' => 'name',
            'type' => 'text',
            'label' => 'Rollenname',
            'placeholder' => 'Neue Rolle',
            'required' => true,
        ],
        [
            'key' => 'color',
            'type' => 'text',
            'label' => 'Farbe (Hex)',
            'placeholder' => '#5865F2',
            'required' => false,
        ],
        [
            'key' => 'hoist',
            'type' => 'switch',
            'label' => 'Separat anzeigen',
            'required' => false,
        ],
        [
            'key' => 'result_var',
            'type' => 'text',
            'label' => 'Rollen-ID als Variable speichern',
            'placeholder' => 'new_role_id',
            'required' => false,
        ],
    ],
];
