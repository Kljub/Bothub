<?php
declare(strict_types=1);
# PFAD: /functions/builder/conditions/condition_status.php

return array (
  'type' => 'condition.status',
  'category' => 'condition',
  'title' => 'Status Condition',
  'description' => 'Prüfe den aktuellen Status eines Nutzers (falls verfügbar).',
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
      'key' => 'status',
      'type' => 'select',
      'label' => 'Status',
      'options' => 
      array (
        0 => 'online',
        1 => 'idle',
        2 => 'dnd',
        3 => 'offline',
      ),
      'required' => true,
    ),
  ),
);
