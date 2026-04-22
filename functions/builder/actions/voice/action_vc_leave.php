<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/voice/action_vc_leave.php

return [
    'type' => 'action.vc.leave',
    'category' => 'action',
    'title' => 'Leave VC',
    'description' => 'Verlasse den aktuellen Sprachkanal.',
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
    'properties' => [],
];
