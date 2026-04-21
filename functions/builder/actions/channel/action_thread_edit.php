<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/channel/action_thread_edit.php

return array (
  'type' => 'action.thread.edit',
  'category' => 'action',
  'title' => 'Edit a Thread',
  'description' => 'Bearbeite einen bestehenden Thread.',
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
    1 => 
    array (
      'key' => 'name',
      'type' => 'text',
      'label' => 'Neuer Name',
      'placeholder' => '',
      'required' => false,
    ),
  ),
);
