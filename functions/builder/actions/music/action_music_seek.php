<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_seek.php

return array (
  'type' => 'action.music.seek',
  'category' => 'action',
  'title' => 'Set Track Position',
  'description' => 'Springe zu einer bestimmten Stelle im Track.',
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
      'key' => 'position',
      'type' => 'text',
      'label' => 'Position (Sekunden)',
      'placeholder' => '60',
      'required' => true,
    ),
  ),
);
