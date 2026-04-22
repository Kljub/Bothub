<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_play_queue.php

return [
    'type' => 'action.music.play_queue',
    'category' => 'action',
    'title' => 'Play Queue',
    'description' => 'Starte die Wiedergabe der Warteschlange.',
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
