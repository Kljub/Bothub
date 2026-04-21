<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_loop_mode.php

return [
    'type'        => 'action.music.loop_mode',
    'category'    => 'action',
    'title'       => 'Set Loop Mode',
    'description' => 'Setzt den Loop-Modus des Music Players (off / track / queue).',
    'icon'        => 'action',
    'color'       => 'pink',
    'ports'       => [
        'inputs'  => [
            ['key' => 'in',   'label' => 'Input', 'kind' => 'flow', 'max_connections' => 1],
        ],
        'outputs' => [
            ['key' => 'next', 'label' => 'Next',  'kind' => 'flow', 'max_connections' => 1],
        ],
    ],
    'defaults'    => [
        'mode' => 'off',
    ],
    'properties'  => [
        [
            'key'      => 'mode',
            'type'     => 'select',
            'label'    => 'Loop Modus',
            'options'  => ['off', 'track', 'queue'],
            'required' => true,
        ],
    ],
];
