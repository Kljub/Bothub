<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/channel/action_thread_edit.php

return [
    'type' => 'action.thread.edit',
    'category' => 'action',
    'title' => 'Edit a Thread',
    'description' => 'Bearbeite einen bestehenden Thread.',
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
            'key' => 'thread_id',
            'type' => 'text',
            'label' => 'Thread ID',
            'placeholder' => '',
            'required' => true,
        ],
        [
            'key' => 'name',
            'type' => 'text',
            'label' => 'Neuer Name',
            'placeholder' => '',
            'required' => false,
        ],
    ],
];
