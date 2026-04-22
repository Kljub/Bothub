<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_stop.php

return [
    'type' => 'action.music.stop',
    'category' => 'action',
    'title' => 'Stop Music',
    'description' => 'Stoppe die Wiedergabe und leere die Warteschlange.',
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
