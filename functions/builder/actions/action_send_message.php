<?php
declare(strict_types=1);
# PFAD: /functions/builder/action_send_message.php

return [
    'type' => 'action.send_message',
    'category' => 'action',
    'title' => 'Send Message',
    'description' => 'Sendet eine Nachricht im aktuellen Kontext.',
    'icon' => 'message',
    'color' => 'blue',
    'ports' => [
        'inputs' => [
            [
                'key' => 'input',
                'label' => 'Input',
                'kind' => 'flow',
                'max_connections' => 1,
            ],
        ],
        'outputs' => [
            [
                'key' => 'next',
                'label' => 'Next',
                'kind' => 'flow',
                'max_connections' => 1,
            ],
        ],
    ],
    'defaults' => [
        'content' => '',
        'tts' => false,
        'ephemeral' => false,
    ],
    'properties' => [
        [
            'key' => 'content',
            'type' => 'textarea',
            'label' => 'Nachricht',
            'required' => true,
            'max_length' => 2000,
        ],
        [
            'key' => 'tts',
            'type' => 'switch',
            'label' => 'TTS',
            'required' => false,
        ],
        [
            'key' => 'ephemeral',
            'type' => 'switch',
            'label' => 'Ephemeral',
            'required' => false,
        ],
    ],
];
