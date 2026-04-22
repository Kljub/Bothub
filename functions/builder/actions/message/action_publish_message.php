<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/message/action_publish_message.php

return [
    'type' => 'action.message.publish',
    'category' => 'action',
    'title' => 'Publish a Message',
    'description' => 'Veröffentliche eine Nachricht in einem Ankündigungskanal.',
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
    'properties' => [],
];
