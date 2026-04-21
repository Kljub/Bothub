<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_filter.php

return array (
  'type' => 'action.music.filter',
  'category' => 'action',
  'title' => 'Apply Audio Filter',
  'description' => 'Wende einen Audio-Filter an.',
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
      'key' => 'filter',
      'type' => 'select',
      'label' => 'Filter',
      'options' => 
      array (
        0 => 'bassboost',
        1 => 'nightcore',
        2 => 'vaporwave',
        3 => '8d',
        4 => 'karaoke',
        5 => 'treble',
        6 => 'flanger',
      ),
      'required' => true,
    ),
  ),
);
