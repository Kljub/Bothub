<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/role/action_role_remove_everyone.php

return array (
  'type' => 'action.role.remove_from_everyone',
  'category' => 'action',
  'title' => 'Remove Roles from Everyone',
  'description' => 'Entferne Rollen von allen Mitgliedern.',
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
      'key' => 'role_ids',
      'type' => 'text',
      'label' => 'Rollen-IDs (kommagetrennt)',
      'placeholder' => '123,456',
      'required' => true,
    ),
  ),
);
