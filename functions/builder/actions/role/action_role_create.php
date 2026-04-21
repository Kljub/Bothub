<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/role/action_role_create.php

return array (
  'type' => 'action.role.create',
  'category' => 'action',
  'title' => 'Create a Role',
  'description' => 'Erstelle eine neue Rolle.',
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
      'key' => 'name',
      'type' => 'text',
      'label' => 'Rollenname',
      'placeholder' => 'Neue Rolle',
      'required' => true,
    ),
    1 => 
    array (
      'key' => 'color',
      'type' => 'text',
      'label' => 'Farbe (Hex)',
      'placeholder' => '#5865F2',
      'required' => false,
    ),
    2 => 
    array (
      'key' => 'hoist',
      'type' => 'switch',
      'label' => 'Separat anzeigen',
      'required' => false,
    ),
    3 => 
    array (
      'key' => 'result_var',
      'type' => 'text',
      'label' => 'Rollen-ID als Variable speichern',
      'placeholder' => 'new_role_id',
      'required' => false,
    ),
  ),
);
