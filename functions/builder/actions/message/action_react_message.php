<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/message/action_react_message.php

return [
    'type'        => 'action.message.react',
    'category'    => 'action',
    'title'       => 'React to a Message',
    'subtitle'    => 'React',
    'description' => 'Add a reaction to a message.',
    'icon'        => 'emoji',
    'color'       => 'blue',
    'ports'       => [
        'inputs'  => [['key' => 'in',   'label' => 'Input', 'kind' => 'flow', 'max_connections' => 1]],
        'outputs' => [['key' => 'next', 'label' => 'Next',  'kind' => 'flow', 'max_connections' => 1]],
    ],
    'defaults' => [
        'react_mode' => 'by_var',
        'var_name'   => '',
        'message_id' => '',
        'channel_id' => '',
        'emojis'     => [],
    ],
    'properties' => [
        [
            'key'     => 'react_mode',
            'type'    => 'select',
            'label'   => 'Message Mode',
            'help'    => 'Choose how to identify the message to react to.',
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
            'show_if'     => ['react_mode', ['by_var']],
        ],
        [
            'key'         => 'message_id',
            'type'        => 'text',
            'label'       => 'Message ID',
            'help'        => 'The ID of the message to react to. Supports variables.',
            'placeholder' => '123456789012345678',
            'show_if'     => ['react_mode', ['by_id']],
        ],
        [
            'key'         => 'channel_id',
            'type'        => 'text',
            'label'       => 'Channel ID (optional)',
            'help'        => 'The channel the message is in. Leave empty to use the current channel. Supports variables.',
            'placeholder' => 'Leave empty for current channel',
            'show_if'     => ['react_mode', ['by_id']],
        ],
        [
            'key'   => 'emojis',
            'type'  => 'emoji_picker',
            'label' => 'Reactions',
            'help'  => 'Pick one or more emojis to react with. Includes server custom emojis.',
        ],
    ],
];
