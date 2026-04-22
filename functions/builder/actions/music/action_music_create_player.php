<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_create_player.php

return [
    'type' => 'action.music.create_player',
    'category' => 'action',
    'title' => 'Create Music Player',
    'description' => 'Erstelle einen Musik-Player (YouTube, Spotify, Plex).',
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
