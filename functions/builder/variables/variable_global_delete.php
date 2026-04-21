<?php
declare(strict_types=1);
# PFAD: /functions/builder/variables/variable_global_delete.php

return [
    'type'        => 'variable.global.delete',
    'category'    => 'variable',
    'title'       => 'Delete Global Variable',
    'description' => 'Löscht eine globale Variable aus der Datenbank.',
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
        'var_key' => '',
    ],
    'properties' => [
        [
            'key'         => 'var_key',
            'type'        => 'text',
            'label'       => 'Variable Name',
            'placeholder' => 'z.B. welcome_count',
            'required'    => true,
        ],
    ],
];
