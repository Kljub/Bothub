<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_volume.php

return array (
  'type' => 'action.music.volume',
  'category' => 'action',
  'title' => 'Set Volume',
  'description' => 'Stelle die Lautstärke ein (0–200).',
  'icon' => 'action',
  'color' => 'pink',
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
      'key' => 'volume',
      'type' => 'text',
      'label' => 'Lautstärke (0–200)',
      'placeholder' => '100',
      'required' => true,
    ),
  ),
);
