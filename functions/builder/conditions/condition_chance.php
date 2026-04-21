<?php
declare(strict_types=1);
# PFAD: /functions/builder/conditions/condition_chance.php

return array (
  'type' => 'condition.chance',
  'category' => 'condition',
  'title' => 'Chance Condition',
  'description' => 'Tritt mit einer bestimmten Wahrscheinlichkeit ein.',
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
      'key' => 'percent',
      'type' => 'text',
      'label' => 'Wahrscheinlichkeit (%)',
      'placeholder' => '50',
      'required' => true,
    ),
  ),
);
