<?php
declare(strict_types=1);
# PFAD: /functions/builder/options/option_channel.php

return [
    'type' => 'option.channel',
    'category' => 'option',
    'title' => 'Channel Option',
    'subtitle' => 'Select a channel from the server',
    'description' => 'Fuegt eine Option fuer den Slash Command hinzu.',
    'icon' => '#',
    'color' => 'purple',
    'ports' => [
        'inputs' => [],
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
        'option_name' => 'channel',
        'description' => '',
        'required' => false,
    ],
    'ui' => [
        'properties_layout' => 'option',
        'properties_title' => 'Channel Option',
        'properties_description' => 'A channel option. Use the variable {option} in your responses to reference this option.',
    ],
    'properties' => [
        [
            'key' => 'option_name',
            'type' => 'text',
            'label' => 'Option Name',
            'help' => 'A descriptive name of the option.',
            'placeholder' => 'Name',
            'render_if_empty' => true,
            'required' => true,
            'max_length' => 32,
            'pattern' => '^[a-z0-9_-]{1,32}$',
        ],
        [
            'key' => 'description',
            'type' => 'textarea',
            'label' => 'Option Description',
            'help' => 'A short description of what the option is.',
            'placeholder' => 'Description',
            'render_if_empty' => true,
            'required' => false,
            'max_length' => 255,
        ],
        [
            'key' => 'required',
            'type' => 'switch',
            'label' => 'Set Required',
            'help' => 'Whether or not a response is required.',
            'render_if_empty' => true,
        ],
    ],
];
