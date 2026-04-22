<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/voice/action_vc_join.php

return [
    'type' => 'action.vc.join',
    'category' => 'action',
    'title' => 'Join a Voice Channel',
    'description' => 'Lasse den Bot einem Sprachkanal beitreten.',
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
            'key' => 'channel_id',
            'type' => 'text',
            'label' => 'Channel ID (leer = Channel des Nutzers)',
            'placeholder' => '',
            'required' => false,
            'vars' => true,
        ],
    ],
];
