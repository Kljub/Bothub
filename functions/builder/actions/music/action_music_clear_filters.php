<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_clear_filters.php

return [
    'type' => 'action.music.clear_filters',
    'category' => 'action',
    'title' => 'Clear Filters',
    'description' => 'Entferne alle aktiven Audio-Filter.',
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
