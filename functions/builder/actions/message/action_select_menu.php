<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/message/action_select_menu.php

return [
    'type' => 'action.select_menu',
    'category' => 'action',
    'title' => 'Select Menu',
    'subtitle' => 'Menu',
    'description' => 'Fügt ein Auswahlmenü zu einer Nachricht hinzu.',
    'icon' => 'selector',
    'color' => 'blue',
    'ports' => [
        'inputs' => [
            [
                'key' => 'in',
                'kind' => 'component',
                'max_connections' => 1,
            ],
        ],
        'outputs' => [
            ' ',
        ],
    ],
    'defaults' => [
        'placeholder' => 'Select an option...',
        'menu_type' => '',
        'options' => [
            [
                'label' => 'Option 1',
                'value' => 'option_1',
                'description' => '',
            ],
        ],
        'min_values' => 1,
        'max_values' => 1,
        'var_name' => '',
        'disabled' => 'false',
        'show_replies' => 'show',
        'component_order' => false,
        'custom_id' => '',
    ],
    'properties' => [
        'placeholder',
        'menu_type',
        'options',
        [
            'key' => 'max_values',
            'type' => 'select',
            'label' => 'Enable Multiselect',
            'help' => 'Allow users to select more than one option.',
            'options' => [
                [
                    'value' => '1',
                    'label' => 'Single Select',
                ],
                [
                    'value' => '25',
                    'label' => 'Multi Select',
                ],
            ],
        ],
        'var_name',
        [
            'key' => 'disabled',
            'type' => 'select',
            'label' => 'Disable Select Menu by Default',
            'help' => 'Set to \'true\' to disable this select menu by default.',
            'options' => [
                [
                    'value' => 'false',
                    'label' => 'false',
                ],
                [
                    'value' => 'true',
                    'label' => 'true',
                ],
            ],
        ],
        [
            'key' => 'show_replies',
            'type' => 'select',
            'label' => 'Show Select Menu Replies',
            'help' => 'Choose whether to show or hide the select menu replies.',
            'options' => [
                [
                    'value' => 'show',
                    'label' => 'Show Replies',
                ],
                [
                    'value' => 'hide',
                    'label' => 'Hide Replies',
                ],
            ],
        ],
        'component_order',
        'custom_id',
    ],
];
