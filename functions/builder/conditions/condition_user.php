<?php
declare(strict_types=1);
# PFAD: /functions/builder/conditions/condition_user.php

return array (
  'type' => 'condition.user',
  'category' => 'condition',
  'title' => 'User Condition',
  'description' => 'Prüfe ob es sich um einen bestimmten Nutzer handelt.',
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
      'key' => 'user_id',
      'type' => 'text',
      'label' => 'User-ID',
      'placeholder' => '',
      'required' => true,
    ),
  ),
);
