<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_create_plex.php

return [
    'type' => 'action.music.create_plex',
    'category' => 'action',
    'title' => 'Create Plex Player',
    'description' => 'Erstelle einen Plex-spezifischen Music Player.',
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
