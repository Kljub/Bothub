<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/channel/action_thread_delete.php

return array (
  'type' => 'action.thread.delete',
  'category' => 'action',
  'title' => 'Delete a Thread',
  'description' => 'Lösche einen Thread.',
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
      'key' => 'thread_id',
      'type' => 'text',
      'label' => 'Thread ID',
      'placeholder' => '',
      'required' => true,
    ),
  ),
);
