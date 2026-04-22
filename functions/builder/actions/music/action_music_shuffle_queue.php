<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_shuffle_queue.php

return [
    'type' => 'action.music.shuffle_queue',
    'category' => 'action',
    'title' => 'Shuffle Queue',
    'description' => 'Mische die Warteschlange zufällig.',
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
