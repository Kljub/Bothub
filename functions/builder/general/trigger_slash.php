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
            [
                'key' => 'options',
                'label' => 'Options',
                'kind' => 'option',
                'max_connections' => 25,
            ],
        ],
        'outputs' => [
            [
                'key' => 'next',
                'label' => 'Next',
                'kind' => 'flow',
                'max_connections' => 1,
            ],
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
        [
            'key' => 'display_name',
            'type' => 'text',
            'label' => 'Anzeigename',
            'required' => true,
            'max_length' => 120,
        ],
        [
            'key' => 'name',
            'type' => 'text',
            'label' => 'Slash Command',
            'required' => true,
            'max_length' => 32,
            'pattern' => '^[a-z0-9_-]{1,32}$',
            'help' => 'Nur Kleinbuchstaben, Zahlen, Unterstriche und Bindestriche.',
        ],
        [
            'key' => 'description',
            'type' => 'textarea',
            'label' => 'Beschreibung',
            'required' => false,
            'max_length' => 255,
        ],
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
        [
            'key'       => 'cooldown_seconds',
            'type'      => 'number',
            'label'     => 'Cooldown (Sekunden)',
            'min'       => 1,
            'max'       => 86400,
            'help'      => 'Dauer des Cooldowns in Sekunden.',
            'show_if'   => ['cooldown_type', ['user', 'server']],
        ],
        [
            'key'   => 'required_permissions',
            'type'  => 'permissions_select',
            'label' => 'Benötigte Berechtigungen',
            'help'  => 'Command wird in Discord nur für Mitglieder mit mindestens einer dieser Berechtigungen angezeigt. Admins können immer zugreifen. Leer = sichtbar für alle.',
        ],
    ],
];