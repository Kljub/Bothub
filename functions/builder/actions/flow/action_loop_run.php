<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/flow/action_loop_run.php

return [
    'type' => 'action.flow.loop.run',
    'category' => 'action',
    'title' => 'Run Loop',
    'description' => 'Führe eine Schleife aus. Variablen: {varname.index}, {varname.count}, {varname.item}',
    'icon' => 'action',
    'color' => 'orange',
    'ports' => [
        'inputs' => [
            'in',
        ],
        'outputs' => [
            'body',
            ' ',
        ],
    ],
    'defaults' => [],
    'properties' => [
        [
            'key' => 'var_name',
            'type' => 'text',
            'label' => 'Loop Variable Name',
            'placeholder' => 'loop',
            'help' => 'The variable name to use for this loop. Access with {varname.index}, {varname.count}, {varname.item}.',
            'required' => false,
        ],
        [
            'key' => 'mode',
            'type' => 'select',
            'label' => 'Loop Mode',
            'help' => 'Run the loop a fixed number of times, or iterate over a list.',
            'options' => [
                [
                    'value' => 'count',
                    'label' => 'Count – repeat N times',
                ],
                [
                    'value' => 'foreach',
                    'label' => 'For Each – iterate over a list',
                ],
            ],
        ],
        [
            'key' => 'count',
            'type' => 'number',
            'label' => 'Count',
            'placeholder' => '3',
            'help' => 'Number of times to repeat the loop body.',
            'min' => 1,
            'max' => 50,
        ],
        [
            'key' => 'list_var',
            'type' => 'text',
            'label' => 'List Variable',
            'placeholder' => 'my_list',
            'help' => 'Variable name holding a JSON array or comma-separated values to iterate over.',
        ],
    ],
];
