<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_add_queue.php

return [
    'type' => 'action.music.add_queue',
    'category' => 'action',
    'title' => 'Add to Queue',
    'description' => 'Füge einen Track der Warteschlange hinzu.',
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
            'label' => 'URL / Suche',
            'placeholder' => 'https://youtu.be/... oder Titel',
            'required' => true,
        ],
    ],
];
