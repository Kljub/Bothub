<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/channel/action_thread_create.php

return array (
  'type' => 'action.thread.create',
  'category' => 'action',
  'title' => 'Create a Thread',
  'description' => 'Erstelle einen neuen Thread.',
  'icon' => 'action',
  'color' => 'blue',
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
      'label' => 'Thread-Name',
      'placeholder' => 'Diskussion',
      'required' => true,
    ),
    1 => 
    array (
      'key' => 'auto_archive',
      'type' => 'select',
      'label' => 'Auto-Archive',
      'options' => 
      array (
        0 => '60',
        1 => '1440',
        2 => '4320',
        3 => '10080',
      ),
      'required' => false,
    ),
    2 => 
    array (
      'key' => 'result_var',
      'type' => 'text',
      'label' => 'Thread-ID als Variable speichern',
      'placeholder' => 'new_thread_id',
      'required' => false,
    ),
  ),
);
