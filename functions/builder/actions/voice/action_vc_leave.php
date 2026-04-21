<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/voice/action_vc_leave.php

return array (
  'type' => 'action.vc.leave',
  'category' => 'action',
  'title' => 'Leave VC',
  'description' => 'Verlasse den aktuellen Sprachkanal.',
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
  ),
);
