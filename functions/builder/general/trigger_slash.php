<?php
declare(strict_types=1);
# PFAD: /functions/builder/trigger_slash.php

return [
    'type' => 'trigger.slash',
    'category' => 'trigger',
    'title' => 'Slash Command',
    'subtitle' => '/command',
    'description' => 'Startet einen Custom Command ueber einen Slash Command.',
    'icon' => 'exclamation-triangle',
    'color' => 'yellow',
    'ports' => [
        'inputs' => [
            'options',
        ],
        'outputs' => [
            ' ',
        ],
    ],
    'defaults' => [
        'display_name'          => 'Slash Command',
        'name'                  => 'command',
        'description'           => 'Custom command',
        'allowed_roles'         => [['id' => 'everyone', 'name' => '@everyone']],
        'banned_roles'          => [],
        'required_permissions'  => [],
        'banned_permissions'    => [],
        'banned_channels'       => [],
        'ephemeral'             => '0',
        'cooldown_type'         => 'none',
        'cooldown_seconds'      => 10,
    ],
    'properties' => [
        'display_name',
        'name',
        'description',
        [
            'key'     => 'ephemeral',
            'type'    => 'select',
            'label'   => 'Antworten verbergen',
            'help'    => 'Verbirgt die Bot-Antworten vor allen außer dem Ausführenden. Funktioniert nicht bei gezielten Antworten und DM-Aktionen.',
            'options' => [
                ['value' => '0', 'label' => 'Antworten für alle anzeigen'],
                ['value' => '1', 'label' => 'Nur für den Ausführenden sichtbar (Ephemeral)'],
            ],
        ],
        [
            'key'     => 'cooldown_type',
            'type'    => 'select',
            'label'   => 'Command Cooldown',
            'help'    => 'Legt einen Cooldown für diesen Command fest. Gilt nur für den Command selbst, nicht für Buttons oder Select Menus.',
            'options' => [
                ['value' => 'none',   'label' => 'Kein Cooldown'],
                ['value' => 'user',   'label' => 'Nutzer-Cooldown'],
                ['value' => 'server', 'label' => 'Server-Cooldown'],
            ],
        ],
        'cooldown_seconds',
        'required_permissions',
    ],
];