<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_filter.php

return [
    'type' => 'action.music.filter',
    'category' => 'action',
    'title' => 'Apply Audio Filter',
    'description' => 'Wende einen Audio-Filter an.',
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
            'key' => 'filter',
            'type' => 'select',
            'label' => 'Filter',
            'options' => [
                'bassboost',
                'nightcore',
                'vaporwave',
                '8d',
                'karaoke',
                'treble',
                'flanger',
            ],
            'required' => true,
        ],
    ],
];
