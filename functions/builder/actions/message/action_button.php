<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/message/action_button.php

return [
    'type' => 'action.button',
    'category' => 'action',
    'title' => 'Button',
    'subtitle' => 'Button',
    'description' => 'Fügt einen klickbaren Button zu einer Nachricht hinzu.',
    'icon' => 'cursor-click',
    'color' => 'blue',
    'ports' => [
        'inputs' => [
            [
                'key' => 'in',
                'kind' => 'component',
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
            'key' => 'label',
            'type' => 'text',
            'label' => 'Button Label',
            'placeholder' => 'Click me',
            'required' => true,
        ],
        [
            'key' => 'style',
            'type' => 'select',
            'label' => 'Button Style',
            'help' => 'The style and color of this button.',
            'options' => [
                [
                    'value' => 'primary',
                    'label' => 'Blue',
                ],
                [
                    'value' => 'secondary',
                    'label' => 'Gray',
                ],
                [
                    'value' => 'success',
                    'label' => 'Green',
                ],
                [
                    'value' => 'danger',
                    'label' => 'Red',
                ],
                [
                    'value' => 'link',
                    'label' => 'Link',
                ],
            ],
        ],
        'emoji',
        'custom_id',
    ],
];
