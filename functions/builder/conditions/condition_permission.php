<?php
declare(strict_types=1);
# PFAD: /functions/builder/conditions/condition_permission.php

return [
    'type' => 'condition.permission',
    'category' => 'condition',
    'title' => 'Permission Condition',
    'description' => 'Prüfe ob der Nutzer bestimmte Berechtigungen hat.',
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
            'key' => 'permission',
            'type' => 'select',
            'label' => 'Berechtigung',
            'options' => [
                'Administrator',
                'ManageGuild',
                'ManageMessages',
                'KickMembers',
                'BanMembers',
                'MuteMembers',
                'ManageRoles',
                'ManageChannels',
            ],
            'required' => true,
        ],
    ],
];
