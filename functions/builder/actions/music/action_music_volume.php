<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_volume.php

return [
    'type' => 'action.music.volume',
    'category' => 'action',
    'title' => 'Set Volume',
    'description' => 'Stelle die Lautstärke ein (0–200).',
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
    'properties' => [
        [
            'key' => 'volume',
            'type' => 'text',
            'label' => 'Lautstärke (0–200)',
            'placeholder' => '100',
            'required' => true,
        ],
    ],
];
