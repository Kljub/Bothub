<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/channel/action_thread_create.php

return [
    'type' => 'action.thread.create',
    'category' => 'action',
    'title' => 'Create a Thread',
    'description' => 'Erstelle einen neuen Thread.',
    'icon' => 'action',
    'color' => 'blue',
    'ports' => [
        'inputs' => [
            [
                'key' => 'in',
                'label' => 'Input',
                'kind' => 'flow',
                'max_connections' => 1,
            ],
        ],
        'outputs' => [
            ' ',
        ],
    ],
    'defaults' => [],
    'properties' => [
        [
            'key' => 'name',
            'type' => 'text',
            'label' => 'Thread-Name',
            'placeholder' => 'Diskussion',
            'required' => true,
        ],
        [
            'key' => 'auto_archive',
            'type' => 'select',
            'label' => 'Auto-Archive',
            'options' => [
                '60',
                '1440',
                '4320',
                '10080',
            ],
            'required' => false,
        ],
        [
            'key' => 'result_var',
            'type' => 'text',
            'label' => 'Thread-ID als Variable speichern',
            'placeholder' => 'new_thread_id',
            'required' => false,
        ],
    ],
];
