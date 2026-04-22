<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/action_http_request.php

return [
    'type' => 'action.http.request',
    'category' => 'action',
    'title' => 'Send API Request',
    'subtitle' => 'HTTP',
    'description' => 'Make an HTTP request to an external API and use the response.',
    'icon' => 'globe',
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
    'defaults' => [
        'var_name' => '',
        'method' => 'GET',
        'url' => '',
        'params' => [],
        'headers' => [],
        'body' => '',
        'body_type' => 'json',
        'opt_exclude_empty' => true,
        'opt_vars_url' => true,
        'opt_vars_params' => true,
        'opt_vars_headers' => true,
        'opt_vars_body' => true,
        'opt_sanitize' => false,
    ],
    'properties' => [
        'var_name',
        '__open_request_builder',
    ],
];
