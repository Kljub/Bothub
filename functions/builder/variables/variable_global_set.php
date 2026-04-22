<?php
declare(strict_types=1);
# PFAD: /functions/builder/variables/variable_global_set.php

return [
    'type' => 'variable.global.set',
    'category' => 'variable',
    'title' => 'Set Global Variable',
    'description' => 'Setzt eine globale Variable, die von allen Commands dieses Bots genutzt werden kann.',
    'icon' => 'variable',
    'color' => 'teal',
    'ports' => [
        'inputs' => [
            'in',
        ],
        'outputs' => [
            ' ',
        ],
    ],
    'defaults' => [],
    'properties' => [
        [
            'key' => 'var_key',
            'type' => 'text',
            'label' => 'Variable Key',
            'placeholder' => 'my_variable',
            'required' => true,
        ],
        [
            'key' => 'var_value',
            'type' => 'text',
            'label' => 'Value',
            'placeholder' => '{user.id}',
            'required' => false,
        ],
    ],
];
