<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_loop_mode.php

return [
    'type' => 'action.music.loop_mode',
    'category' => 'action',
    'title' => 'Set Loop Mode',
    'description' => 'Setzt den Loop-Modus des Music Players (off / track / queue).',
    'icon' => 'action',
    'color' => 'pink',
    'ports' => [
        'inputs' => [
            'in',
        ],
        'outputs' => [
            ' ',
        ],
    ],
    'defaults' => [
        'mode' => 'off',
    ],
    'properties' => [
        'mode',
    ],
];
