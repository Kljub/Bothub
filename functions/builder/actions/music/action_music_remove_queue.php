<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_remove_queue.php

return array (
  'type' => 'action.music.remove_queue',
  'category' => 'action',
  'title' => 'Remove Queue',
  'description' => 'Entferne einen Track aus der Warteschlange.',
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
      'key' => 'index',
      'type' => 'text',
      'label' => 'Position (1-basiert)',
      'placeholder' => '1',
      'required' => false,
    ),
  ),
);
