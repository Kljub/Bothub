<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/action_send_message.php

return [
    'type' => 'action.send_message',
    'category' => 'action',
    'title' => 'Send Message',
    'description' => 'Sendet eine Nachricht im aktuellen Kontext.',
    'icon' => 'message',
    'color' => 'blue',
    'ports' => [
        'inputs' => [
            'input',
        ],
        'outputs' => [
            ' ',
        ],
    ],
    'defaults' => [],
    'properties' => [
        [
            'key' => 'content',
            'type' => 'textarea',
            'label' => 'Message Content',
            'placeholder' => 'Hello, {user}!',
            'required' => false,
        ],
        [
            'key' => 'tts',
            'type' => 'select',
            'label' => 'Text-to-Speech',
            'help' => 'Send this message as a Text-to-Speech message.',
            'options' => [
                [
                    'value' => '',
                    'label' => 'No',
                ],
                [
                    'value' => '1',
                    'label' => 'Yes',
                ],
            ],
        ],
        [
            'key' => 'ephemeral',
            'type' => 'select',
            'label' => 'Ephemeral',
            'help' => 'Only show this message to the user who triggered it.',
            'options' => [
                [
                    'value' => '',
                    'label' => 'No',
                ],
                [
                    'value' => '1',
                    'label' => 'Yes',
                ],
            ],
        ],
    ],
];
