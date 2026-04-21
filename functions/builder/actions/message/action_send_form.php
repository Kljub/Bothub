<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/message/action_send_form.php

return [
    'type'        => 'action.message.send_form',
    'category'    => 'action',
    'title'       => 'Send a Form',
    'subtitle'    => 'Form',
    'description' => 'Send a form or modal and wait for the user to fill it out.',
    'icon'        => 'document-text',
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
                'label'           => 'Submitted',
                'kind'            => 'flow',
                'max_connections' => 1,
            ],
        ],
    ],
    'defaults' => [
        'form_name'   => 'my-form',
        'form_title'  => 'Form Title',
        'block_label' => 'Send a Form',
        'fields'      => [
            [
                'label'        => 'Input Label',
                'placeholder'  => '',
                'min_length'   => '',
                'max_length'   => '',
                'style'        => 'short',
                'required'     => 'true',
                'hidden'       => '',
                'default'      => '',
            ],
        ],
    ],
    'properties' => [
        [
            'key'         => 'form_name',
            'type'        => 'text',
            'label'       => 'Form Name',
            'help'        => 'A name for this form. This will be used as a variable to access the responses entered by the user after they submit the form.',
            'required'    => true,
            'max_length'  => 64,
            'placeholder' => 'my-form',
        ],
        [
            'key'  => 'form_title',
            'type' => 'form_builder',
            'label' => 'Form Builder',
            'help'  => 'Click the button below to open the Form builder.',
        ],
        [
            'key'         => 'block_label',
            'type'        => 'text',
            'label'       => 'Block Label',
            'help'        => 'Add an optional label to this block. This will change how the block appears in the builder.',
            'required'    => false,
            'max_length'  => 64,
            'placeholder' => 'Send a Form',
        ],
    ],
];
