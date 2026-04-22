<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_seek.php

return [
    'type' => 'action.music.seek',
    'category' => 'action',
    'title' => 'Set Track Position',
    'description' => 'Springe zu einer bestimmten Stelle im Track.',
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
            'key' => 'position',
            'type' => 'text',
            'label' => 'Position (Sekunden)',
            'placeholder' => '60',
            'required' => true,
        ],
    ],
];
