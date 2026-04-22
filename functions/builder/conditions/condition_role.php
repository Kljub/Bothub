<?php
declare(strict_types=1);
# PFAD: /functions/builder/conditions/condition_role.php

return [
    'type' => 'condition.role',
    'category' => 'condition',
    'title' => 'Role Condition',
    'description' => 'Prüfe ob der Nutzer eine bestimmte Rolle hat.',
    'icon' => 'condition',
    'color' => 'green',
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
            'true',
            'false',
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
