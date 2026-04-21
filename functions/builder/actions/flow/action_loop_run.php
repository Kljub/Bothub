<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/flow/action_loop_run.php

return [
    'type'        => 'action.flow.loop.run',
    'category'    => 'action',
    'title'       => 'Run Loop',
    'description' => 'Führe eine Schleife aus. Variablen: {varname.index}, {varname.count}, {varname.item}',
    'icon'        => 'action',
    'color'       => 'orange',
    'ports'       => [
        'inputs'  => [
            ['key' => 'in',   'label' => 'Input',     'kind' => 'flow', 'max_connections' => 1],
        ],
        'outputs' => [
            ['key' => 'body', 'label' => 'Loop Body', 'kind' => 'flow', 'max_connections' => 1],
            ['key' => 'next', 'label' => 'Nach Loop', 'kind' => 'flow', 'max_connections' => 1],
        ],
    ],
    'defaults'    => [
        'var_name' => 'loop',
        'mode'     => 'count',
        'count'    => '3',
        'list_var' => '',
    ],
    'properties'  => [
        [
            'key'         => 'var_name',
            'type'        => 'text',
            'label'       => 'Variablenname (Prefix)',
            'placeholder' => 'loop',
            'required'    => true,
        ],
        [
            'key'      => 'mode',
            'type'     => 'select',
            'label'    => 'Modus',
            'options'  => ['count', 'foreach'],
            'required' => true,
        ],
        [
            'key'         => 'count',
            'type'        => 'text',
            'label'       => 'Anzahl (count-Modus, max. 50)',
            'placeholder' => '3',
            'required'    => false,
        ],
        [
            'key'         => 'list_var',
            'type'        => 'text',
            'label'       => 'Listen-Variable (foreach) – JSON-Array oder kommagetrennt',
            'placeholder' => 'meineVariable',
            'required'    => false,
        ],
    ],
];
