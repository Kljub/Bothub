<?php
declare(strict_types=1);
# PFAD: /functions/builder/conditions/condition_channel.php

return array (
  'type' => 'condition.channel',
  'category' => 'condition',
  'title' => 'Channel Condition',
  'description' => 'Prüfe ob der Command in einem bestimmten Kanal genutzt wird.',
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
      'key' => 'channel_id',
      'type' => 'text',
      'label' => 'Channel-ID',
      'placeholder' => '',
      'required' => true,
    ),
  ),
);
