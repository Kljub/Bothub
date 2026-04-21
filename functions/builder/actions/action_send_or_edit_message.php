<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/action_send_or_edit_message.php

return [
    'type'        => 'action.message.send_or_edit',
    'category'    => 'action',
    'title'       => 'Send or Edit a Message',
    'description' => 'Sendet oder bearbeitet eine Nachricht mit optionalen Embeds.',
    'icon'        => 'message',
    'color'       => 'blue',
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
                'key'             => 'button',
                'label'           => 'Button',
                'kind'            => 'component',
                'spawn_type'      => 'action.button',
                'max_connections' => 5,
            ],
            [
                'key'             => 'next',
                'label'           => 'Next',
                'kind'            => 'flow',
                'max_connections' => 1,
            ],
            [
                'key'             => 'menu',
                'label'           => 'Menu',
                'kind'            => 'component',
                'spawn_type'      => 'action.select_menu',
                'max_connections' => 1,
            ],
        ],
    ],
    'defaults' => [
        'response_type'           => 'reply',
        'target_message_id'       => '',
        'target_channel_id'       => '',
        'target_option_name'      => '',
        'target_dm_option_name'   => '',
        'target_user_id'          => '',
        'edit_target_var'         => '',
        'var_name'                => '',
        'message_content'         => '',
        'embeds'                  => [],
        'ephemeral'               => false,
    ],
    'properties' => [
        [
            'key'   => '__open_message_builder',
            'type'  => 'message_builder_btn',
            'label' => 'Nachricht bearbeiten',
        ],
        [
            'key'     => 'response_type',
            'type'    => 'select',
            'label'   => 'Response Type',
            'options' => [
                ['value' => 'reply',            'label' => 'Reply — Reply to the command'],
                ['value' => 'reply_message',    'label' => 'Reply — Reply to a specific message'],
                ['value' => 'channel',          'label' => 'Reply — Send to the channel the command was used in'],
                ['value' => 'specific_channel', 'label' => 'Specific — Send to a specific channel'],
                ['value' => 'channel_option',   'label' => 'Specific — Send to a channel option'],
                ['value' => 'dm_user',          'label' => 'Direct Message — DM the user who used the command'],
                ['value' => 'dm_user_option',   'label' => 'Direct Message — DM a user option'],
                ['value' => 'dm_specific_user', 'label' => 'Direct Message — DM a specific user'],
                ['value' => 'edit_action',      'label' => 'Edit — Edit a message sent by another action'],
            ],
        ],
        // reply_message: needs a target message ID/var
        [
            'key'         => 'target_message_id',
            'type'        => 'text',
            'label'       => 'Message ID or Variable',
            'placeholder' => 'z.B. 123456789 or {my_message}',
            'help'        => 'ID or {variable} of the message to reply to.',
            'show_if'     => ['response_type', ['reply_message']],
        ],
        // specific_channel: needs a channel ID/var
        [
            'key'         => 'target_channel_id',
            'type'        => 'text',
            'label'       => 'Channel ID or Variable',
            'placeholder' => 'z.B. 1234567890 or {channel_var}',
            'help'        => 'ID or {variable} of the target channel.',
            'show_if'     => ['response_type', ['specific_channel']],
        ],
        // channel_option: needs the option name
        [
            'key'         => 'target_option_name',
            'type'        => 'text',
            'label'       => 'Channel Option Name',
            'placeholder' => 'z.B. channel',
            'help'        => 'Name of the channel slash command option.',
            'show_if'     => ['response_type', ['channel_option']],
        ],
        // dm_user_option: needs the option name
        [
            'key'         => 'target_dm_option_name',
            'type'        => 'text',
            'label'       => 'User Option Name',
            'placeholder' => 'z.B. user',
            'help'        => 'Name of the user slash command option.',
            'show_if'     => ['response_type', ['dm_user_option']],
        ],
        // dm_specific_user: needs a user ID/var
        [
            'key'         => 'target_user_id',
            'type'        => 'text',
            'label'       => 'User ID or Variable',
            'placeholder' => 'z.B. 987654321 or {user_var}',
            'help'        => 'ID or {variable} of the user to DM.',
            'show_if'     => ['response_type', ['dm_specific_user']],
        ],
        // edit_action: needs the var name of the target message
        [
            'key'         => 'edit_target_var',
            'type'        => 'text',
            'label'       => 'Message Variable to Edit',
            'placeholder' => 'z.B. my_message',
            'help'        => 'Variable name from a previous Send Message action.',
            'show_if'     => ['response_type', ['edit_action']],
        ],
        [
            'key'         => 'var_name',
            'type'        => 'text',
            'label'       => 'Save Message as Variable',
            'placeholder' => 'z.B. my_message',
            'required'    => false,
            'help'        => 'Optional — store the sent message for later edits.',
        ],
        [
            'key'      => 'ephemeral',
            'type'     => 'switch',
            'label'    => 'Ephemeral (nur für den Nutzer sichtbar)',
            'required' => false,
            'show_if'  => ['response_type', ['reply', 'reply_message']],
        ],
    ],
];
