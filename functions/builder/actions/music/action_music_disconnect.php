<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_disconnect.php

return [
    'type' => 'action.music.disconnect',
    'category' => 'action',
    'title' => 'Disconnect from VC',
    'description' => 'Trenne den Bot vom Sprachkanal.',
    'icon' => 'action',
    'color' => 'pink',
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
    'properties' => [],
];
