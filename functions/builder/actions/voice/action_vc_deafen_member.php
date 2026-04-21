<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/voice/action_vc_deafen_member.php

return array (
  'type' => 'action.vc.deafen_member',
  'category' => 'action',
  'title' => 'Deafen / Undeafen a VC Member',
  'description' => 'Taubschalte oder hebe Taubschaltung auf.',
  'icon' => 'action',
  'color' => 'green',
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
      'key' => 'deafen',
      'type' => 'switch',
      'label' => 'Taubschalten',
      'required' => false,
    ),
  ),
);
