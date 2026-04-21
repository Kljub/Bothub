<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/bot/action_bot_status.php

return array (
  'type' => 'action.bot.set_status',
  'category' => 'action',
  'title' => 'Change the Bot Status',
  'description' => 'Ändere den Status / die Aktivität des Bots.',
  'icon' => 'action',
  'color' => 'orange',
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
      'key' => 'status',
      'type' => 'select',
      'label' => 'Status',
      'options' => 
      array (
        0 => 'online',
        1 => 'idle',
        2 => 'dnd',
        3 => 'invisible',
      ),
      'required' => true,
    ),
    1 => 
    array (
      'key' => 'activity_type',
      'type' => 'select',
      'label' => 'Activity Type',
      'options' => 
      array (
        0 => 'Playing',
        1 => 'Streaming',
        2 => 'Listening',
        3 => 'Watching',
        4 => 'Competing',
      ),
      'required' => false,
    ),
    2 => 
    array (
      'key' => 'activity_text',
      'type' => 'text',
      'label' => 'Activity Text',
      'placeholder' => 'z.B. Minecraft',
      'required' => false,
    ),
  ),
);
