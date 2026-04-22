<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_previous.php

return [
    'type' => 'action.music.previous',
    'category' => 'action',
    'title' => 'Play Previous Track',
    'description' => 'Spiele den vorherigen Track.',
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
