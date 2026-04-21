<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/channel/action_channel_create.php

return array (
  'type' => 'action.channel.create',
  'category' => 'action',
  'title' => 'Create a Channel',
  'description' => 'Erstelle einen neuen Kanal.',
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
      'label' => 'Name',
      'placeholder' => 'neuer-kanal',
      'required' => true,
    ),
    1 => 
    array (
      'key' => 'type',
      'type' => 'select',
      'label' => 'Typ',
      'options' => 
      array (
        0 => 'text',
        1 => 'voice',
        2 => 'category',
        3 => 'announcement',
      ),
      'required' => true,
    ),
    2 => 
    array (
      'key' => 'result_var',
      'type' => 'text',
      'label' => 'Channel-ID als Variable speichern',
      'placeholder' => 'new_channel_id',
      'required' => false,
    ),
  ),
);
