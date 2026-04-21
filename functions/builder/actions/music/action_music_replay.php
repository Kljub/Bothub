<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_replay.php

return array (
  'type' => 'action.music.replay',
  'category' => 'action',
  'title' => 'Replay Track',
  'description' => 'Spiele den aktuellen Track erneut ab.',
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
