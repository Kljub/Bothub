<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/message/action_select_menu.php

return [
    'type'        => 'action.select_menu',
    'category'    => 'action',
    'title'       => 'Select Menu',
    'subtitle'    => 'Menu',
    'description' => 'Fügt ein Auswahlmenü zu einer Nachricht hinzu.',
    'icon'        => 'selector',
    'color'       => 'blue',
    'ports'       => [
        'inputs' => [
            [
                'key'             => 'in',
                'label'           => 'Input',
                'kind'            => 'component',
                'max_connections' => 1,
            ],
        ],
        'outputs' => [
            [
                'key'             => 'next',
                'label'           => 'Selected',
                'kind'            => 'flow',
                'max_connections' => 1,
            ],
        ],
    ],
    'defaults' => [
        'placeholder'      => 'Select an option...',
        'menu_type'        => '',
        'options'          => [
            ['label' => 'Option 1', 'value' => 'option_1', 'description' => ''],
        ],
        'min_values'       => 1,
        'max_values'       => 1,
        'var_name'         => '',
        'disabled'         => 'false',
        'show_replies'     => 'show',
        'component_order'  => false,
        'custom_id'        => '',
    ],
    'properties' => [
        [
            'key'  => 'placeholder',
            'type' => 'text',
            'label' => 'Placeholder',
            'help'  => 'The placeholder text displayed when no option is selected.',
            'required'    => false,
            'max_length'  => 150,
            'placeholder' => 'Select an option...',
        ],
        [
            'key'   => 'menu_type',
            'type'  => 'text',
            'label' => 'Menu Type',
            'help'  => 'The label of the menu option. All options and variables can be used.',
            'required'    => false,
            'max_length'  => 100,
            'placeholder' => '',
        ],
        [
            'key'   => 'options',
            'type'  => 'options_list',
            'label' => 'Options',
            'help'  => 'Add options for users to select from the menu. (Max 25 options)',
            'max_items' => 25,
        ],
        [
            'key'   => 'max_values',
            'type'  => 'select',
            'label' => 'Enable Multiselect',
            'help'  => 'Allow users to select more than one option.',
            'options' => [
                ['value' => '1',  'label' => 'Single Select'],
                ['value' => '25', 'label' => 'Multi Select'],
            ],
        ],
        [
            'key'   => 'var_name',
            'type'  => 'text',
            'label' => 'Custom Variable Name (Optional)',
            'help'  => 'Set a custom variable name to access the selected option(s). If left empty, you can use the default {selected_option} variable.',
            'required'    => false,
            'max_length'  => 64,
            'placeholder' => 'E.g., my_select_menu',
        ],
        [
            'key'   => 'disabled',
            'type'  => 'select',
            'label' => 'Disable Select Menu by Default',
            'help'  => 'Set to \'true\' to disable this select menu by default.',
            'options' => [
                ['value' => 'false', 'label' => 'false'],
                ['value' => 'true',  'label' => 'true'],
            ],
        ],
        [
            'key'   => 'show_replies',
            'type'  => 'select',
            'label' => 'Show Select Menu Replies',
            'help'  => 'Choose whether to show or hide the select menu replies.',
            'options' => [
                ['value' => 'show', 'label' => 'Show Replies'],
                ['value' => 'hide', 'label' => 'Hide Replies'],
            ],
        ],
        [
            'key'   => 'component_order',
            'type'  => 'switch',
            'label' => 'Enable Component Ordering',
            'help'  => 'Order this select menu amongst other message components.',
        ],
        [
            'key'        => 'custom_id',
            'type'       => 'text',
            'label'      => 'Custom ID (optional)',
            'required'   => false,
            'max_length' => 100,
            'help'       => 'Wird automatisch generiert wenn leer.',
        ],
    ],
];
