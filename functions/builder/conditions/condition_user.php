<?php
declare(strict_types=1);
# PFAD: /functions/builder/conditions/condition_user.php

return [
    'type' => 'condition.user',
    'category' => 'condition',
    'title' => 'User Condition',
    'description' => 'Prüfe ob es sich um einen bestimmten Nutzer handelt.',
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
            'key' => 'user_id',
            'type' => 'text',
            'label' => 'User-ID',
            'placeholder' => '',
            'required' => true,
        ],
    ],
];
