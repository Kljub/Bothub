<?php
declare(strict_types=1);
# PFAD: /functions/builder/variables/variable_local_set.php

return [
    'type' => 'variable.local.set',
    'category' => 'variable',
    'title' => 'Set Local Variable',
    'description' => 'Setzt eine lokale Variable, die nur in diesem Command genutzt werden kann.',
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
