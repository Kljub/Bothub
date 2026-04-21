<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/moderation/action_mod_nickname.php

return array (
  'type' => 'action.mod.nickname',
  'category' => 'action',
  'title' => 'Change Member\'s Nickname',
  'description' => 'Ändere den Nicknamen eines Mitglieds.',
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
      'key' => 'user_id',
      'type' => 'text',
      'label' => 'User ID oder {option.user}',
      'placeholder' => '',
      'required' => true,
    ),
    1 => 
    array (
      'key' => 'nickname',
      'type' => 'text',
      'label' => 'Neuer Nickname (leer = zurücksetzen)',
      'placeholder' => '',
      'required' => false,
    ),
  ),
);
