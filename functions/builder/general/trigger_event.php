<?php
declare(strict_types=1);
# PFAD: /functions/builder/general/trigger_event.php

return [
    'type' => 'trigger.event',
    'category' => 'trigger',
    'title' => 'Event Trigger',
    'subtitle' => 'Discord Event',
    'description' => 'Startet einen Custom Event wenn ein Discord-Event eintritt.',
    'icon' => 'bolt',
    'color' => 'blue',
    'ports' => [
        'inputs' => [],
        'outputs' => [
            ' ',
        ],
    ],
    'defaults' => [
        'event_type' => '',
        'filter_bot_events' => '1',
        'ephemeral' => '0',
        'cooldown_type' => 'none',
        'cooldown_seconds' => 10,
        'required_permissions' => [],
        'banned_roles' => [],
        'banned_channels' => [],
    ],
    'properties' => [
        [
            'key' => 'event_type',
            'type' => 'event_select',
            'label' => 'Event Typ',
            'required' => true,
            'help' => 'Welches Discord-Event soll diesen Flow starten.',
        ],
        [
            'key' => 'filter_bot_events',
            'type' => 'select',
            'label' => 'Bot-Events ignorieren',
            'help' => 'Events die von Bots ausgelöst wurden überspringen.',
            'options' => [
                [
                    'value' => '1',
                    'label' => 'Ja – Bot-Events ignorieren',
                ],
                [
                    'value' => '0',
                    'label' => 'Nein – auch Bot-Events ausführen',
                ],
            ],
        ],
        [
            'key' => 'ephemeral',
            'type' => 'select',
            'label' => 'Antworten verbergen',
            'help' => 'Verbirgt Bot-Antworten vor allen außer dem Auslöser. Gilt nur für Events mit Interaction-Kontext.',
            'options' => [
                [
                    'value' => '0',
                    'label' => 'Antworten für alle anzeigen',
                ],
                [
                    'value' => '1',
                    'label' => 'Nur für den Auslöser sichtbar (Ephemeral)',
                ],
            ],
        ],
        [
            'key' => 'cooldown_type',
            'type' => 'select',
            'label' => 'Cooldown',
            'help' => 'Verhindert mehrfaches Auslösen des Events in kurzer Zeit.',
            'options' => [
                [
                    'value' => 'none',
                    'label' => 'Kein Cooldown',
                ],
                [
                    'value' => 'user',
                    'label' => 'Nutzer-Cooldown',
                ],
                [
                    'value' => 'server',
                    'label' => 'Server-Cooldown',
                ],
            ],
        ],
        [
            'key' => 'cooldown_seconds',
            'type' => 'number',
            'label' => 'Cooldown (Sekunden)',
            'min' => 1,
            'max' => 86400,
            'help' => 'Dauer des Cooldowns in Sekunden.',
            'show_if' => [
                'cooldown_type',
                [
                    'user',
                    'server',
                ],
            ],
        ],
        [
            'key' => 'required_permissions',
            'type' => 'permissions_select',
            'label' => 'Benötigte Berechtigungen',
            'help' => 'Event wird nur ausgeführt wenn der Auslöser mindestens eine dieser Berechtigungen hat. Leer = immer ausführen.',
        ],
    ],
];
