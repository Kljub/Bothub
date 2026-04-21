<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/action_delete_message.php

return [
    'type'        => 'action.delete_message',
    'category'    => 'action',
    'title'       => 'Delete Message',
    'subtitle'    => 'Delete',
    'description' => 'Deletes a message by variable name or message ID.',
    'icon'        => 'trash',
    'color'       => 'red',
    'ports'       => [
        'inputs' => [
            [
                'key'             => 'in',
                'label'           => 'Input',
                'kind'            => 'flow',
                'max_connections' => 1,
            ],
        ],
        'outputs' => [
            [
                'key'             => 'next',
                'label'           => 'Next',
                'kind'            => 'flow',
                'max_connections' => 1,
            ],
        ],
    ],
    'defaults' => [
        'delete_mode' => 'by_var',
        'var_name'    => '',
        'message_id'  => '',
        'channel_id'  => '',
    ],
    'properties' => [
        [
            'key'     => 'delete_mode',
            'type'    => 'select',
            'label'   => 'Delete Mode',
            'help'    => 'Choose how to identify the message to delete.',
            'options' => [
                ['value' => 'by_var', 'label' => 'By Variable (stored message)'],
                ['value' => 'by_id',  'label' => 'By Message ID'],
            ],
        ],
        [
            'key'         => 'var_name',
            'type'        => 'text',
            'label'       => 'Message Variable',
            'help'        => 'The variable name used when the message was sent (e.g. my-msg). Set via "Save to variable" on a Send Message node.',
            'placeholder' => 'my-msg',
            'show_if'     => ['delete_mode', ['by_var']],
        ],
        [
            'key'         => 'message_id',
            'type'        => 'text',
            'label'       => 'Message ID',
            'help'        => 'The ID of the message to delete. Supports variables.',
            'placeholder' => '123456789012345678',
            'show_if'     => ['delete_mode', ['by_id']],
        ],
        [
            'key'         => 'channel_id',
            'type'        => 'text',
            'label'       => 'Channel ID (optional)',
            'help'        => 'The channel the message is in. Leave empty to use the current channel. Supports variables.',
            'placeholder' => 'Leave empty for current channel',
            'show_if'     => ['delete_mode', ['by_id']],
        ],
    ],
];
