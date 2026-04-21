<?php
declare(strict_types=1);

return [
    'type'        => 'action.flow.wait',
    'category'    => 'action',
    'title'       => 'Wait',
    'description' => 'Wartet eine bestimmte Zeit, bevor die naechste Aktion ausgefuehrt wird.',
    'icon'        => 'clock',
    'color'       => 'gray',
    'ports'       => [
        'inputs' => [
            [
                'key'             => 'in',
                'label'           => 'In',
                'kind'            => 'flow',
                'max_connections' => 1,
            ],
        ],
        'outputs' => [
            [
                'key'             => 'next',
                'label'           => 'Next',
                'kind'            => 'flow',
                'max_connections' => 1,
            ],
        ],
    ],
    'properties' => [
        [
            'key'     => 'duration',
            'type'    => 'number',
            'label'   => 'Wartezeit (Sekunden)',
            'help'    => 'Wie viele Sekunden soll gewartet werden?',
            'default' => 5,
            'min'     => 1,
            'max'     => 600,
        ],
    ],
];