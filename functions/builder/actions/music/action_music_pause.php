<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_pause.php

return [
    'type' => 'action.music.pause',
    'category' => 'action',
    'title' => 'Pause Music',
    'description' => 'Pausiere die aktuelle Wiedergabe.',
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
