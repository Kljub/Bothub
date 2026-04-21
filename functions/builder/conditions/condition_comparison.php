<?php
declare(strict_types=1);
# PFAD: /functions/builder/conditions/condition_comparison.php

return [
    'type'        => 'condition.comparison',
    'category'    => 'condition',
    'title'       => 'Comparison Condition',
    'description' => 'Run actions based on the difference between two values.',
    'icon'        => 'condition',
    'color'       => 'green',
    'ports'       => [
        'inputs' => [
            [
                'key'             => 'in',
                'label'           => 'Input',
                'kind'            => 'flow',
                'max_connections' => 1,
            ],
        ],
        'outputs' => [
            // Static fallback port; condition ports (cond_0, cond_1 …) are
            // generated dynamically in JS from node.config.conditions at render time.
            [
                'key'             => 'else',
                'label'           => 'Else',
                'kind'            => 'flow',
                'max_connections' => 1,
            ],
        ],
    ],
    'defaults' => [
        'run_mode'   => 'first_match',
        'conditions' => [
            ['base_value' => '', 'operator' => '==', 'comparison_value' => ''],
        ],
    ],
    // Valid operator values (for reference / server-side validation)
    'operator_values' => [
        '<', '<=', '>', '>=', '==', '!=',
        'contains', 'not_contains',
        'starts_with', 'ends_with',
        'not_starts_with', 'not_ends_with',
        'collection_contains', 'collection_not_contains',
    ],
    'properties' => [
        [
            'key'  => 'conditions',
            'type' => 'comparison_conditions',
        ],
    ],
];
