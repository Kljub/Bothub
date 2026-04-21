<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/message/action_pin_message.php

return [
    'type'        => 'action.message.pin',
    'category'    => 'action',
    'title'       => 'Pin a Message',
    'subtitle'    => 'Pin',
    'description' => 'Pin a message in a channel.',
    'icon'        => 'pin',
    'color'       => 'blue',
    'ports'       => [
        'inputs'  => [['key' => 'in',   'label' => 'Input', 'kind' => 'flow', 'max_connections' => 1]],
        'outputs' => [['key' => 'next', 'label' => 'Next',  'kind' => 'flow', 'max_connections' => 1]],
    ],
    'defaults' => [
        'pin_mode'   => 'by_var',
        'var_name'   => '',
        'message_id' => '',
        'channel_id' => '',
    ],
    'properties' => [
        [
            'key'     => 'pin_mode',
            'type'    => 'select',
            'label'   => 'Message Mode',
            'help'    => 'Choose how to identify the message to pin.',
            'options' => [
                ['value' => 'by_var', 'label' => 'By Variable (stored message)'],
                ['value' => 'by_id',  'label' => 'By Message ID'],
            ],
        ],
        [
            'key'         => 'var_name',
            'type'        => 'text',
            'label'       => 'Message Variable',
            'help'        => 'The variable name used when the message was sent (e.g. my-msg).',
            'placeholder' => 'my-msg',
            'show_if'     => ['pin_mode', ['by_var']],
        ],
        [
            'key'         => 'message_id',
            'type'        => 'text',
            'label'       => 'Message ID',
            'help'        => 'The ID of the message to pin. Supports variables.',
            'placeholder' => '123456789012345678',
            'show_if'     => ['pin_mode', ['by_id']],
        ],
        [
            'key'         => 'channel_id',
            'type'        => 'text',
            'label'       => 'Channel ID (optional)',
            'help'        => 'The channel the message is in. Leave empty to use the current channel. Supports variables.',
            'placeholder' => 'Leave empty for current channel',
            'show_if'     => ['pin_mode', ['by_id']],
        ],
    ],
];
