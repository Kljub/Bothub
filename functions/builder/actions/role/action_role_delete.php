<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/role/action_role_delete.php

return array (
  'type' => 'action.role.delete',
  'category' => 'action',
  'title' => 'Delete a Role',
  'description' => 'Lösche eine Rolle.',
  'icon' => 'action',
  'color' => 'purple',
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
    0 => 
    array (
      'key' => 'role_id',
      'type' => 'text',
      'label' => 'Rollen-ID',
      'placeholder' => '',
      'required' => true,
    ),
  ),
);
