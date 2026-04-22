<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/channel/action_channel_edit.php

return [
    'type' => 'action.channel.edit',
    'category' => 'action',
    'title' => 'Edit a Channel',
    'description' => 'Bearbeite einen bestehenden Kanal.',
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
        [
            'key' => 'name',
            'type' => 'text',
            'label' => 'Neuer Name',
            'placeholder' => '',
            'required' => false,
        ],
        [
            'key' => 'topic',
            'type' => 'text',
            'label' => 'Neues Thema',
            'placeholder' => '',
            'required' => false,
        ],
    ],
];
