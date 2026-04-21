<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_disconnect.php

return array (
  'type' => 'action.music.disconnect',
  'category' => 'action',
  'title' => 'Disconnect from VC',
  'description' => 'Trenne den Bot vom Sprachkanal.',
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
