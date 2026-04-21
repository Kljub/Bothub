<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/action_http_request.php

return [
    'type'        => 'action.http.request',
    'category'    => 'action',
    'title'       => 'Send API Request',
    'subtitle'    => 'HTTP',
    'description' => 'Make an HTTP request to an external API and use the response.',
    'icon'        => 'globe',
    'color'       => 'orange',
    'ports'       => [
        'inputs'  => [['key' => 'in',   'label' => 'Input', 'kind' => 'flow', 'max_connections' => 1]],
        'outputs' => [['key' => 'next', 'label' => 'Next',  'kind' => 'flow', 'max_connections' => 1]],
    ],
    'defaults' => [
        'var_name'          => '',
        'method'            => 'GET',
        'url'               => '',
        'params'            => [],
        'headers'           => [],
        'body'              => '',
        'body_type'         => 'json',
        'opt_exclude_empty' => true,
        'opt_vars_url'      => true,
        'opt_vars_params'   => true,
        'opt_vars_headers'  => true,
        'opt_vars_body'     => true,
        'opt_sanitize'      => false,
    ],
    'properties' => [
        [
            'key'         => 'var_name',
            'type'        => 'text',
            'label'       => 'Name',
            'help'        => 'A name for this request. Used as a variable to access the response data.',
            'placeholder' => 'varname',
        ],
        [
            'key'   => '__open_request_builder',
            'type'  => 'request_builder_btn',
            'label' => 'Request Builder',
            'help'  => 'Click the button below to open the HTTP Request builder.',
        ],
    ],
];
