<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/server/action_server_leave.php

return array (
  'type' => 'action.server.leave',
  'category' => 'action',
  'title' => 'Leave Server',
  'description' => 'Verlasse den aktuellen Server.',
  'icon' => 'action',
  'color' => 'red',
  'ports' => 
  array (
    'inputs' => 
    array (
      0 => 
      array (
        'key' => 'in',
        'label' => 'Input',
        'kind' => 'flow',
        'max_connections' => 1,
      ),
    ),
    'outputs' => 
    array (
      0 => 
      array (
        'key' => 'next',
        'label' => 'Next',
        'kind' => 'flow',
        'max_connections' => 1,
      ),
    ),
  ),
  'defaults' => 
  array (
  ),
  'properties' => 
  array (
  ),
);
