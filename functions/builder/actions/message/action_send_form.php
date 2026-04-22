<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/message/action_send_form.php

return [
    'type' => 'action.message.send_form',
    'category' => 'action',
    'title' => 'Send a Form',
    'subtitle' => 'Form',
    'description' => 'Send a form or modal and wait for the user to fill it out.',
    'icon' => 'document-text',
    'color' => 'blue',
    'ports' => [
        'inputs' => [
            'in',
        ],
        'outputs' => [
            ' ',
        ],
    ],
    'defaults' => [
        'form_name' => 'my-form',
        'form_title' => 'Form Title',
        'block_label' => 'Send a Form',
        'fields' => [],
    ],
    'properties' => [
        'form_name',
        'form_title',
        'block_label',
    ],
];
