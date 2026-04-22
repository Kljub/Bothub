<?php
declare(strict_types=1);
# PFAD: /functions/builder/variables/variable_global_delete.php

return [
    'type' => 'variable.global.delete',
    'category' => 'variable',
    'title' => 'Delete Global Variable',
    'description' => 'Löscht eine globale Variable aus der Datenbank.',
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
    'defaults' => [
        'var_key' => '',
    ],
    'properties' => [
        'var_key',
    ],
];
