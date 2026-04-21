<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_create_player.php

return array (
  'type' => 'action.music.create_player',
  'category' => 'action',
  'title' => 'Create Music Player',
  'description' => 'Erstelle einen Musik-Player (YouTube, Spotify, Plex).',
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
