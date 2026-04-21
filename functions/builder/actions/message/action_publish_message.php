<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/message/action_publish_message.php

return array (
  'type' => 'action.message.publish',
  'category' => 'action',
  'title' => 'Publish a Message',
  'description' => 'Veröffentliche eine Nachricht in einem Ankündigungskanal.',
  'icon' => 'action',
  'color' => 'blue',
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
  ),
);
