<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_pause.php

return array (
  'type' => 'action.music.pause',
  'category' => 'action',
  'title' => 'Pause Music',
  'description' => 'Pausiere die aktuelle Wiedergabe.',
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
  ),
);
