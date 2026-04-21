<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/music/action_music_autoleave.php

return array (
  'type' => 'action.music.autoleave',
  'category' => 'action',
  'title' => 'Set Autoleave',
  'description' => 'Aktiviere oder deaktiviere Auto-Disconnect bei Inaktivität.',
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
      'key' => 'enabled',
      'type' => 'switch',
      'label' => 'Autoleave aktivieren',
      'required' => false,
    ),
  ),
);
