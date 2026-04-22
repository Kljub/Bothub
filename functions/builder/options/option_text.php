<?php
declare(strict_types=1);
# PFAD: /functions/builder/options/option_text.php

return [
    'type' => 'option.text',
    'category' => 'option',
    'title' => 'Text Option',
    'subtitle' => 'A text option',
    'description' => 'Fuegt eine Option fuer den Slash Command hinzu.',
    'icon' => 'T',
    'color' => 'purple',
    'ports' => [
        'inputs' => [],
        'outputs' => [
            ' ',
        ],
    ],
    'defaults' => [],
    'ui' => 'next',
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
        'description',
        'required',
        'choices',
    ],
];
