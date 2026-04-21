<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/message/action_button.php

return [
    'type'        => 'action.button',
    'category'    => 'action',
    'title'       => 'Button',
    'subtitle'    => 'Button',
    'description' => 'Fügt einen klickbaren Button zu einer Nachricht hinzu.',
    'icon'        => 'cursor-click',
    'color'       => 'blue',
    'ports'       => [
        'inputs' => [
            [
                'key'             => 'in',
                'label'           => 'Input',
                'kind'            => 'component',
                'max_connections' => 1,
            ],
        ],
        'outputs' => [
            [
                'key'             => 'next',
                'label'           => 'Clicked',
                'kind'            => 'flow',
                'max_connections' => 1,
            ],
        ],
    ],
    'defaults' => [
        'label'     => 'Button 1',
        'style'     => 'primary',
        'emoji'     => '',
        'custom_id' => '',
    ],
    'properties' => [
        [
            'key'         => 'label',
            'type'        => 'text',
            'label'       => 'Button Label',
            'required'    => true,
            'max_length'  => 80,
            'placeholder' => 'z.B. Klick mich!',
        ],
        [
            'key'     => 'style',
            'type'    => 'select',
            'label'   => 'Button Style',
            'help'    => 'The style and color of this button.',
            'options' => [
                ['value' => 'primary',   'label' => 'Blue'],
                ['value' => 'secondary', 'label' => 'Gray'],
                ['value' => 'success',   'label' => 'Green'],
                ['value' => 'danger',    'label' => 'Red'],
                ['value' => 'link',      'label' => 'Link'],
            ],
        ],
        [
            'key'        => 'emoji',
            'type'       => 'text',
            'label'      => 'Emoji (optional)',
            'required'   => false,
            'max_length' => 32,
        ],
        [
            'key'        => 'custom_id',
            'type'       => 'text',
            'label'      => 'Custom ID (optional)',
            'required'   => false,
            'max_length' => 100,
            'help'       => 'Wird automatisch generiert wenn leer.',
        ],
    ],
];
