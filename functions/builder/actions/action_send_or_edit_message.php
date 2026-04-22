<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/action_send_or_edit_message.php

return [
    'type' => 'action.message.send_or_edit',
    'category' => 'action',
    'title' => 'Send or Edit a Message',
    'description' => 'Sendet oder bearbeitet eine Nachricht mit optionalen Embeds.',
    'icon' => 'message',
    'color' => 'blue',
    'ports' => [
        'inputs' => [
            'in',
        ],
        'outputs' => [
            [
                'key' => 'button',
                'kind' => 'component',
                'spawn_type' => 'action.button',
                'max_connections' => 5,
            ],
            ' ',
            [
                'key' => 'menu',
                'kind' => 'component',
                'spawn_type' => 'action.select_menu',
                'max_connections' => 1,
            ],
        ],
    ],
    'defaults' => [
        'response_type' => 'reply',
        'target_message_id' => '',
        'target_channel_id' => '',
        'target_option_name' => '',
        'target_dm_option_name' => '',
        'target_user_id' => '',
        'edit_target_var' => '',
        'var_name' => '',
        'message_content' => '',
        'embeds' => [],
        'ephemeral' => false,
    ],
    'properties' => [
        '__open_message_builder',
        [
            'key' => 'response_type',
            'type' => 'select',
            'label' => 'Response Type',
            'options' => [
                [
                    'value' => 'reply',
                    'label' => 'Reply — Reply to the command',
                ],
                [
                    'value' => 'reply_message',
                    'label' => 'Reply — Reply to a specific message',
                ],
                [
                    'value' => 'channel',
                    'label' => 'Reply — Send to the channel the command was used in',
                ],
                [
                    'value' => 'specific_channel',
                    'label' => 'Specific — Send to a specific channel',
                ],
                [
                    'value' => 'channel_option',
                    'label' => 'Specific — Send to a channel option',
                ],
                [
                    'value' => 'dm_user',
                    'label' => 'Direct Message — DM the user who used the command',
                ],
                [
                    'value' => 'dm_user_option',
                    'label' => 'Direct Message — DM a user option',
                ],
                [
                    'value' => 'dm_specific_user',
                    'label' => 'Direct Message — DM a specific user',
                ],
                [
                    'value' => 'edit_action',
                    'label' => 'Edit — Edit a message sent by another action',
                ],
            ],
        ],
        'target_message_id',
        'target_channel_id',
        'target_option_name',
        'target_dm_option_name',
        'target_user_id',
        'edit_target_var',
        'var_name',
        'ephemeral',
    ],
];
