<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/channel/action_channel_create.php

return [
    'type' => 'action.channel.create',
    'category' => 'action',
    'title' => 'Create a Channel',
    'description' => 'Erstelle einen neuen Kanal.',
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
            'key' => 'name',
            'type' => 'text',
            'label' => 'Name',
            'placeholder' => 'neuer-kanal',
            'required' => true,
        ],
        [
            'key' => 'type',
            'type' => 'select',
            'label' => 'Typ',
            'options' => [
                'text',
                'voice',
                'category',
                'announcement',
            ],
            'required' => true,
        ],
        [
            'key' => 'result_var',
            'type' => 'text',
            'label' => 'Channel-ID als Variable speichern',
            'placeholder' => 'new_channel_id',
            'required' => false,
        ],
    ],
];
