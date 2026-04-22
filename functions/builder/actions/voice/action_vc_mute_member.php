<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/voice/action_vc_mute_member.php

return [
    'type' => 'action.vc.mute_member',
    'category' => 'action',
    'title' => 'Mute / Unmute a VC Member',
    'description' => 'Stummschalte oder hebe Stummschaltung auf.',
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
            'key' => 'user_id',
            'type' => 'text',
            'label' => 'User ID oder {option.user}',
            'placeholder' => '',
            'required' => true,
        ],
        [
            'key' => 'mute',
            'type' => 'switch',
            'label' => 'Stummschalten',
            'required' => false,
        ],
    ],
];
