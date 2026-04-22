<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_remove_queue.php

return [
    'type' => 'action.music.remove_queue',
    'category' => 'action',
    'title' => 'Remove Queue',
    'description' => 'Entferne einen Track aus der Warteschlange.',
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
            'key' => 'index',
            'type' => 'text',
            'label' => 'Position (1-basiert)',
            'placeholder' => '1',
            'required' => false,
        ],
    ],
];
