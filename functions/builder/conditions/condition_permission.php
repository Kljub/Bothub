<?php
declare(strict_types=1);
# PFAD: /functions/builder/conditions/condition_permission.php

return array (
  'type' => 'condition.permission',
  'category' => 'condition',
  'title' => 'Permission Condition',
  'description' => 'Prüfe ob der Nutzer bestimmte Berechtigungen hat.',
  'icon' => 'condition',
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
        'key' => 'true',
        'label' => 'True',
        'kind' => 'flow',
        'max_connections' => 1,
      ),
      1 => 
      array (
        'key' => 'false',
        'label' => 'False',
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
      'key' => 'permission',
      'type' => 'select',
      'label' => 'Berechtigung',
      'options' => 
      array (
        0 => 'Administrator',
        1 => 'ManageGuild',
        2 => 'ManageMessages',
        3 => 'KickMembers',
        4 => 'BanMembers',
        5 => 'MuteMembers',
        6 => 'ManageRoles',
        7 => 'ManageChannels',
      ),
      'required' => true,
    ),
  ),
);
