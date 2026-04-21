<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/role/action_role_remove_member.php

return array (
  'type' => 'action.role.remove_from_member',
  'category' => 'action',
  'title' => 'Remove Roles from a Member',
  'description' => 'Entferne Rollen von einem Mitglied.',
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
      'key' => 'user_id',
      'type' => 'text',
      'label' => 'User ID oder {option.user}',
      'placeholder' => '',
      'required' => true,
    ),
    1 => 
    array (
      'key' => 'role_ids',
      'type' => 'text',
      'label' => 'Rollen-IDs (kommagetrennt)',
      'placeholder' => '123,456',
      'required' => true,
    ),
  ),
);
