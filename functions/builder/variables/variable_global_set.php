<?php
declare(strict_types=1);
# PFAD: /functions/builder/variables/variable_global_set.php

return [
    'type'        => 'variable.global.set',
    'category'    => 'variable',
    'title'       => 'Set Global Variable',
    'description' => 'Setzt eine globale Variable, die von allen Commands dieses Bots genutzt werden kann.',
    'icon'        => 'variable',
    'color'       => 'teal',
    'ports'       => [
        'inputs' => [
            [
                'key'             => 'in',
                'label'           => 'Input',
                'kind'            => 'flow',
                'max_connections' => 1,
            ],
        ],
        'outputs' => [
            [
                'key'             => 'next',
                'label'           => 'Next',
                'kind'            => 'flow',
                'max_connections' => 1,
            ],
        ],
    ],
    'defaults' => [
        'var_key'   => '',
        'var_value' => '',
    ],
    'properties' => [
        [
            'key'         => 'var_key',
            'type'        => 'text',
            'label'       => 'Variable Name',
            'placeholder' => 'z.B. welcome_count',
            'required'    => true,
        ],
        [
            'key'         => 'var_value',
            'type'        => 'textarea',
            'label'       => 'Value',
            'placeholder' => 'Wert oder {option.name} …',
            'required'    => false,
        ],
    ],
];
