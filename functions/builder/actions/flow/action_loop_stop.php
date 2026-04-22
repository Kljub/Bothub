<?php
declare(strict_types=1);
# PFAD: /functions/builder/actions/flow/action_loop_stop.php

return [
    'type'        => 'action.flow.loop.stop',
    'category'    => 'action',
    'title'       => 'Stop Loop',
    'description' => 'Bricht die aktuelle Schleife sofort ab.',
    'icon'        => 'action',
    'color'       => 'orange',
    'ports'       => [
        'inputs'  => [
            'in',
        ],
        'outputs' => [],
    ],
    'defaults'    => [],
    'properties'  => [],
];
