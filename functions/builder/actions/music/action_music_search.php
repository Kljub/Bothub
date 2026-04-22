<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_search.php

return [
    'type' => 'action.music.search',
    'category' => 'action',
    'title' => 'Search Tracks',
    'description' => 'Suche nach Tracks und zeige Ergebnisse.',
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
            'key' => 'query',
            'type' => 'text',
            'label' => 'Suchbegriff',
            'placeholder' => 'Titel oder URL',
            'required' => true,
        ],
        [
            'key' => 'limit',
            'type' => 'text',
            'label' => 'Max. Ergebnisse',
            'placeholder' => '5',
            'required' => false,
        ],
        [
            'key' => 'result_var',
            'type' => 'text',
            'label' => 'Ergebnisse als Variable speichern',
            'placeholder' => 'search_results',
            'required' => false,
        ],
    ],
];
