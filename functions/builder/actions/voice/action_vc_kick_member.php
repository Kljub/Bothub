<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/voice/action_vc_kick_member.php

return [
    'type' => 'action.vc.kick_member',
    'category' => 'action',
    'title' => 'Kick a VC Member',
    'description' => 'Kicke ein Mitglied aus dem Sprachkanal.',
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
    ],
];
