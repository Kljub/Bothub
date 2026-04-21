<?php
declare(strict_types=1);
# PFAD: /functions/builder/conditions/condition_role.php

return array (
  'type' => 'condition.role',
  'category' => 'condition',
  'title' => 'Role Condition',
  'description' => 'Prüfe ob der Nutzer eine bestimmte Rolle hat.',
  'icon' => 'condition',
  'color' => 'green',
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
        'key' => 'true',
        'label' => 'True',
        'kind' => 'flow',
        'max_connections' => 1,
      ),
      1 => 
      array (
        'key' => 'false',
        'label' => 'False',
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
