<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_resume.php

return [
    'type' => 'action.music.resume',
    'category' => 'action',
    'title' => 'Resume Music',
    'description' => 'Setze die pausierte Wiedergabe fort.',
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
