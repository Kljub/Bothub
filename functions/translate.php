<?php
declare(strict_types=1);
# PFAD: /functions/translate.php

function bh_builder_translate_definition(array $def): array
{
    $type = isset($def['type']) && is_string($def['type']) ? $def['type'] : '';
    $category = isset($def['category']) && is_string($def['category']) && $def['category'] !== ''
        ? $def['category']
        : 'other';

    $title = isset($def['title']) && is_string($def['title']) && $def['title'] !== ''
        ? $def['title']
        : $type;

    $subtitle = isset($def['subtitle']) && is_string($def['subtitle'])
        ? $def['subtitle']
        : '';

    $description = isset($def['description']) && is_string($def['description'])
        ? $def['description']
        : '';

    $icon = isset($def['icon']) && is_string($def['icon'])
        ? $def['icon']
        : '';

    $color = isset($def['color']) && is_string($def['color']) && $def['color'] !== ''
        ? $def['color']
        : 'gray';

    $badge = isset($def['badge']) && is_string($def['badge'])
        ? $def['badge']
        : '';

    $ui = isset($def['ui']) && is_array($def['ui'])
        ? $def['ui']
        : [];

    $ports = isset($def['ports']) && is_array($def['ports']) ? $def['ports'] : [];
    $inputs = isset($ports['inputs']) && is_array($ports['inputs']) ? $ports['inputs'] : [];
    $outputs = isset($ports['outputs']) && is_array($ports['outputs']) ? $ports['outputs'] : [];

    $translatedOutputs = [];
    foreach ($outputs as $output) {
        if (is_array($output)) {
            $key = isset($output['key']) && is_string($output['key']) && $output['key'] !== ''
                ? $output['key']
                : null;

            if ($key !== null) {
                $translatedOutputs[] = $key;
            }
            continue;
        }

        if (is_string($output)) {
            // ' ' (space) is the canonical "no-name" flow port — maps to 'next' internally
            $key = trim($output) === '' ? ' ' : $output;
            if ($key !== '') {
                $translatedOutputs[] = $key;
            }
        }
    }

    $defaults = isset($def['defaults']) && is_array($def['defaults'])
        ? $def['defaults']
        : [];

    $properties = isset($def['properties']) && is_array($def['properties'])
        ? $def['properties']
        : [];

    return [
        'type' => $type,
        'category' => $category,
        'label' => $title,
        'title' => $title,
        'subtitle' => $subtitle,
        'description' => $description,
        'icon' => $icon,
        'color' => $color,
        'badge' => $badge,
        'ui' => $ui,
        'input' => !empty($inputs),
        'inputs' => $inputs,
        'outputs' => $translatedOutputs,
        'output_ports' => $outputs,
        'defaults' => $defaults,
        'fields' => $properties,
        'raw' => $def,
    ];
}

function bh_builder_translate_definitions(array $definitions): array
{
    $translated = [];

    foreach ($definitions as $type => $definition) {
        if (!is_array($definition)) {
            continue;
        }

        $translatedDefinition = bh_builder_translate_definition($definition);
        if (!isset($translatedDefinition['type']) || !is_string($translatedDefinition['type']) || $translatedDefinition['type'] === '') {
            continue;
        }

        $translated[$translatedDefinition['type']] = $translatedDefinition;
    }

    ksort($translated);

    return $translated;
}
