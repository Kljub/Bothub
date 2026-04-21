<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/channel/action_channel_edit.php

return array (
  'type' => 'action.channel.edit',
  'category' => 'action',
  'title' => 'Edit a Channel',
  'description' => 'Bearbeite einen bestehenden Kanal.',
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
      'key' => 'channel_id',
      'type' => 'text',
      'label' => 'Channel ID',
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
      'key' => 'topic',
      'type' => 'text',
      'label' => 'Neues Thema',
      'placeholder' => '',
      'required' => false,
    ),
  ),
);
