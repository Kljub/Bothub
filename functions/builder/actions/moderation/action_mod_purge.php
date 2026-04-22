<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/moderation/action_mod_purge.php

return [
    'type' => 'action.mod.purge',
    'category' => 'action',
    'title' => 'Purge Messages',
    'description' => 'Lösche mehrere Nachrichten auf einmal.',
    'icon' => 'action',
    'color' => 'red',
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
            'key' => 'amount',
            'type' => 'text',
            'label' => 'Anzahl (max. 100)',
            'placeholder' => '10',
            'required' => true,
        ],
        [
            'key' => 'channel_id',
            'type' => 'text',
            'label' => 'Channel ID (leer = aktueller Kanal)',
            'placeholder' => '',
            'required' => false,
        ],
    ],
];
