<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/message/action_pin_message.php

return [
    'type' => 'action.message.pin',
    'category' => 'action',
    'title' => 'Pin a Message',
    'subtitle' => 'Pin',
    'description' => 'Pin a message in a channel.',
    'icon' => 'pin',
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
            'key' => 'pin_mode',
            'type' => 'select',
            'label' => 'Message Mode',
            'help' => 'Choose how to identify the message to pin.',
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
    ],
];
