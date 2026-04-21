<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_add_queue.php

return array (
  'type' => 'action.music.add_queue',
  'category' => 'action',
  'title' => 'Add to Queue',
  'description' => 'Füge einen Track der Warteschlange hinzu.',
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
      'key' => 'query',
      'type' => 'text',
      'label' => 'URL / Suche',
      'placeholder' => 'https://youtu.be/... oder Titel',
      'required' => true,
    ),
  ),
);
