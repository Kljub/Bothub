<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/server/action_server_leave.php

return [
    'type' => 'action.server.leave',
    'category' => 'action',
    'title' => 'Leave Server',
    'description' => 'Verlasse den aktuellen Server.',
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
    'properties' => [],
];
