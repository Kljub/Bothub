<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/message/action_react_message.php

return [
    'type' => 'action.message.react',
    'category' => 'action',
    'title' => 'React to a Message',
    'subtitle' => 'React',
    'description' => 'Add a reaction to a message.',
    'icon' => 'emoji',
    'color' => 'blue',
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
            'key' => 'react_mode',
            'type' => 'select',
            'label' => 'Message Mode',
            'help' => 'Choose how to identify the message to react to.',
            'options' => [
                [
                    'value' => 'by_var',
                    'label' => 'By Variable (stored message)',
                ],
                [
                    'value' => 'by_id',
                    'label' => 'By Message ID',
                ],
            ],
        ],
        'var_name',
        'message_id',
        'channel_id',
        'emojis',
    ],
];
