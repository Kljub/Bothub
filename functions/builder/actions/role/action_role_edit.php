<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/role/action_role_edit.php

return array (
  'type' => 'action.role.edit',
  'category' => 'action',
  'title' => 'Edit a Role',
  'description' => 'Bearbeite eine bestehende Rolle.',
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
    1 => 
    array (
      'key' => 'name',
      'type' => 'text',
      'label' => 'Neuer Name',
      'placeholder' => '',
      'required' => false,
    ),
    2 => 
    array (
      'key' => 'color',
      'type' => 'text',
      'label' => 'Neue Farbe',
      'placeholder' => '#5865F2',
      'required' => false,
    ),
  ),
);
