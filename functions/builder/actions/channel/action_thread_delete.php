<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/channel/action_thread_delete.php

return [
    'type' => 'action.thread.delete',
    'category' => 'action',
    'title' => 'Delete a Thread',
    'description' => 'Lösche einen Thread.',
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
    ],
];
