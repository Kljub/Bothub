<?php
declare(strict_types=1);
# PFAD: /functions/builder/conditions/condition_channel.php

return [
    'type' => 'condition.channel',
    'category' => 'condition',
    'title' => 'Channel Condition',
    'description' => 'Prüfe ob der Command in einem bestimmten Kanal genutzt wird.',
    'icon' => 'condition',
    'color' => 'green',
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
            'true',
            'false',
        ],
    ],
    'defaults' => [],
    'properties' => [
        [
            'key' => 'channel_id',
            'type' => 'text',
            'label' => 'Channel-ID',
            'placeholder' => '',
            'required' => true,
        ],
    ],
];
