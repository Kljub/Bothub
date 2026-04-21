<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/server/action_server_invite.php

return array (
  'type' => 'action.server.create_invite',
  'category' => 'action',
  'title' => 'Create Server Invite',
  'description' => 'Erstelle einen Server-Einladungslink.',
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
      'key' => 'max_age',
      'type' => 'text',
      'label' => 'Gültigkeitsdauer (Sek., 0=unbegrenzt)',
      'placeholder' => '86400',
      'required' => false,
    ),
    1 => 
    array (
      'key' => 'max_uses',
      'type' => 'text',
      'label' => 'Max. Nutzungen (0=unbegrenzt)',
      'placeholder' => '0',
      'required' => false,
    ),
    2 => 
    array (
      'key' => 'result_var',
      'type' => 'text',
      'label' => 'Link als Variable speichern',
      'placeholder' => 'invite_url',
      'required' => false,
    ),
  ),
);
