<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/server/action_server_invite.php

return [
    'type' => 'action.server.create_invite',
    'category' => 'action',
    'title' => 'Create Server Invite',
    'description' => 'Erstelle einen Server-Einladungslink.',
    'icon' => 'action',
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
            ' ',
        ],
    ],
    'defaults' => [],
    'properties' => [
        [
            'key' => 'max_age',
            'type' => 'text',
            'label' => 'Gültigkeitsdauer (Sek., 0=unbegrenzt)',
            'placeholder' => '86400',
            'required' => false,
        ],
        [
            'key' => 'max_uses',
            'type' => 'text',
            'label' => 'Max. Nutzungen (0=unbegrenzt)',
            'placeholder' => '0',
            'required' => false,
        ],
        [
            'key' => 'result_var',
            'type' => 'text',
            'label' => 'Link als Variable speichern',
            'placeholder' => 'invite_url',
            'required' => false,
        ],
    ],
];
