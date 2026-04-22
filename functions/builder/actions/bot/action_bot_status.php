<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/bot/action_bot_status.php

return [
    'type' => 'action.bot.set_status',
    'category' => 'action',
    'title' => 'Change the Bot Status',
    'description' => 'Ändere den Status / die Aktivität des Bots.',
    'icon' => 'action',
    'color' => 'orange',
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
            'key' => 'status',
            'type' => 'select',
            'label' => 'Status',
            'options' => [
                'online',
                'idle',
                'dnd',
                'invisible',
            ],
            'required' => true,
        ],
        [
            'key' => 'activity_type',
            'type' => 'select',
            'label' => 'Activity Type',
            'options' => [
                'Playing',
                'Streaming',
                'Listening',
                'Watching',
                'Competing',
            ],
            'required' => false,
        ],
        [
            'key' => 'activity_text',
            'type' => 'text',
            'label' => 'Activity Text',
            'placeholder' => 'z.B. Minecraft',
            'required' => false,
        ],
    ],
];
