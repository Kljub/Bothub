<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_search.php

return array (
  'type' => 'action.music.search',
  'category' => 'action',
  'title' => 'Search Tracks',
  'description' => 'Suche nach Tracks und zeige Ergebnisse.',
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
      'label' => 'Suchbegriff',
      'placeholder' => 'Titel oder URL',
      'required' => true,
    ),
    1 => 
    array (
      'key' => 'limit',
      'type' => 'text',
      'label' => 'Max. Ergebnisse',
      'placeholder' => '5',
      'required' => false,
    ),
    2 => 
    array (
      'key' => 'result_var',
      'type' => 'text',
      'label' => 'Ergebnisse als Variable speichern',
      'placeholder' => 'search_results',
      'required' => false,
    ),
  ),
);
