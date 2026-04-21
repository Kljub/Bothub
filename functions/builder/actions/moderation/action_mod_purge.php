<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/moderation/action_mod_purge.php

return array (
  'type' => 'action.mod.purge',
  'category' => 'action',
  'title' => 'Purge Messages',
  'description' => 'Lösche mehrere Nachrichten auf einmal.',
  'icon' => 'action',
  'color' => 'red',
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
      'key' => 'amount',
      'type' => 'text',
      'label' => 'Anzahl (max. 100)',
      'placeholder' => '10',
      'required' => true,
    ),
    1 => 
    array (
      'key' => 'channel_id',
      'type' => 'text',
      'label' => 'Channel ID (leer = aktueller Kanal)',
      'placeholder' => '',
      'required' => false,
    ),
  ),
);
