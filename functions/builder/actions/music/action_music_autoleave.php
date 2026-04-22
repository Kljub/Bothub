<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_autoleave.php

return [
    'type' => 'action.music.autoleave',
    'category' => 'action',
    'title' => 'Set Autoleave',
    'description' => 'Aktiviere oder deaktiviere Auto-Disconnect bei Inaktivität.',
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
            'key' => 'enabled',
            'type' => 'switch',
            'label' => 'Autoleave aktivieren',
            'required' => false,
        ],
    ],
];
