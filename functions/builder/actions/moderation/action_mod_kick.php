<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/moderation/action_mod_kick.php

return array (
  'type' => 'action.mod.kick',
  'category' => 'action',
  'title' => 'Kick Member',
  'description' => 'Kicke ein Mitglied vom Server.',
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
      'key' => 'reason',
      'type' => 'textarea',
      'label' => 'Grund',
      'placeholder' => '',
      'required' => false,
    ),
  ),
);
