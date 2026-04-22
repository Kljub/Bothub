<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/voice/action_vc_move_member.php

return [
    'type' => 'action.vc.move_member',
    'category' => 'action',
    'title' => 'Move a VC Member',
    'description' => 'Verschiebe ein Mitglied in einen anderen VC.',
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
            'key' => 'channel_id',
            'type' => 'text',
            'label' => 'Ziel-Channel ID',
            'placeholder' => '',
            'required' => true,
        ],
    ],
];
