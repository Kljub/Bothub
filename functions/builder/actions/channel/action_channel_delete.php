<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/channel/action_channel_delete.php

return [
    'type' => 'action.channel.delete',
    'category' => 'action',
    'title' => 'Delete a Channel',
    'description' => 'Lösche einen Kanal.',
    'icon' => 'action',
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
            'key' => 'channel_id',
            'type' => 'text',
            'label' => 'Channel ID',
            'placeholder' => '',
            'required' => true,
        ],
    ],
];
