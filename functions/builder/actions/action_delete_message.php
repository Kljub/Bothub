<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/action_delete_message.php

return [
    'type' => 'action.delete_message',
    'category' => 'action',
    'title' => 'Delete Message',
    'subtitle' => 'Delete',
    'description' => 'Deletes a message by variable name or message ID.',
    'icon' => 'trash',
    'color' => 'red',
    'ports' => [
        'inputs' => [
            'in',
        ],
        'outputs' => [
            ' ',
        ],
    ],
    'defaults' => [],
    'properties' => [
        [
            'key' => 'delete_mode',
            'type' => 'select',
            'label' => 'Delete Mode',
            'help' => 'Choose how to identify the message to delete.',
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
