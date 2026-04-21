<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/message/action_edit_component.php

return [
    'type'        => 'action.message.edit_component',
    'category'    => 'action',
    'title'       => 'Edit a Button or Select Menu',
    'description' => 'Bearbeite einen Button oder ein Select-Menü einer Nachricht.',
    'icon'        => 'action',
    'color'       => 'blue',
    'ports'       => [
        'inputs' => [
            [
                'key'             => 'in',
                'label'           => 'Input',
                'kind'            => 'flow',
                'max_connections' => 1,
            ],
        ],
        'outputs' => [
            [
                'key'             => 'next',
                'label'           => 'Next',
                'kind'            => 'flow',
                'max_connections' => 1,
            ],
        ],
    ],
    'defaults' => [
        'target_message_node_id' => '',
        'components'             => [],
    ],
    'properties' => [],
];
