<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_replay.php

return [
    'type' => 'action.music.replay',
    'category' => 'action',
    'title' => 'Replay Track',
    'description' => 'Spiele den aktuellen Track erneut ab.',
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
