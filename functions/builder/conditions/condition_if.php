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
            'input',
        ],
        'outputs' => [
            'true',
            'false',
        ],
    ],
    'defaults' => [],
    'properties' => [
        [
            'key'         => 'left_value',
            'type'        => 'text',
            'label'       => 'Left Value',
            'placeholder' => '{user.id}',
            'required'    => true,
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
            'key'         => 'right_value',
            'type'        => 'text',
            'label'       => 'Right Value',
            'placeholder' => '1234567890',
            'required'    => true,
        ],
    ],
];
