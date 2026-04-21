<?php
declare(strict_types=1);
# PFAD: /functions/builder/condition_if.php

return [
    'type' => 'condition.if',
    'category' => 'condition',
    'title' => 'If',
    'description' => 'Prueft eine einfache Bedingung und verzweigt den Flow.',
    'icon' => 'branch',
    'color' => 'green',
    'ports' => [
        'inputs' => [
            [
                'key' => 'input',
                'label' => 'Input',
                'kind' => 'flow',
                'max_connections' => 1,
            ],
        ],
        'outputs' => [
            [
                'key' => 'true',
                'label' => 'True',
                'kind' => 'flow',
                'max_connections' => 1,
            ],
            [
                'key' => 'false',
                'label' => 'False',
                'kind' => 'flow',
                'max_connections' => 1,
            ],
        ],
    ],
    'defaults' => [
        'left_value' => '',
        'operator' => 'equals',
        'right_value' => '',
    ],
    'properties' => [
        [
            'key' => 'left_value',
            'type' => 'text',
            'label' => 'Linker Wert',
            'required' => true,
            'max_length' => 255,
        ],
        [
            'key' => 'operator',
            'type' => 'select',
            'label' => 'Operator',
            'required' => true,
            'options' => [
                ['value' => 'equals', 'label' => 'Ist gleich'],
                ['value' => 'not_equals', 'label' => 'Ist ungleich'],
                ['value' => 'contains', 'label' => 'Enthaelt'],
                ['value' => 'greater_than', 'label' => 'Groesser als'],
                ['value' => 'less_than', 'label' => 'Kleiner als'],
            ],
        ],
        [
            'key' => 'right_value',
            'type' => 'text',
            'label' => 'Rechter Wert',
            'required' => true,
            'max_length' => 255,
        ],
    ],
];
