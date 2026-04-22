<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_skip.php

return [
    'type' => 'action.music.skip',
    'category' => 'action',
    'title' => 'Skip Track',
    'description' => 'Überspringe den aktuellen Track.',
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
