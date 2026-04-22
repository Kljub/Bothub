<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/voice/action_vc_deafen_member.php

return [
    'type' => 'action.vc.deafen_member',
    'category' => 'action',
    'title' => 'Deafen / Undeafen a VC Member',
    'description' => 'Taubschalte oder hebe Taubschaltung auf.',
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
            'key' => 'deafen',
            'type' => 'switch',
            'label' => 'Taubschalten',
            'required' => false,
        ],
    ],
];
