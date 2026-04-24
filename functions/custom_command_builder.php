<?php
declare(strict_types=1);
# PFAD: /functions/custom_command_builder.php

require_once __DIR__ . '/translate.php';

/**
 * Lädt alle Builder-Blockdefinitionen rekursiv aus /functions/builder/
 * Unterstützte Struktur z. B.:
 * - /functions/builder/actions/
 * - /functions/builder/conditions/
 * - /functions/builder/options/
 * - /functions/builder/general/
 *
 * Jede Datei muss ein JSON-Objekt mit einem 'type'-Feld sein.
 */
function custom_command_builder_raw_definitions(): array
{
    $dir = __DIR__ . '/builder';
    $defs = [];

    if (!is_dir($dir)) {
        return [];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo) {
            continue;
        }

        if (!$file->isFile()) {
            continue;
        }

        if (strtolower($file->getExtension()) !== 'json') {
            continue;
        }

        $raw = file_get_contents($file->getPathname());
        if ($raw === false) {
            continue;
        }

        $def = json_decode($raw, true);
        if (!is_array($def)) {
            continue;
        }

        // New format: type lives inside loadout.type
        $type = isset($def['loadout']['type']) && is_string($def['loadout']['type']) && $def['loadout']['type'] !== ''
            ? $def['loadout']['type']
            : (isset($def['type']) && is_string($def['type']) && $def['type'] !== '' ? $def['type'] : '');

        if ($type === '') {
            continue;
        }

        $defs[$type] = $def;
    }

    ksort($defs);

    return $defs;
}

function custom_command_builder_definitions(): array
{
    return bh_builder_translate_definitions(custom_command_builder_raw_definitions());
}

function custom_command_builder_palette(): array
{
    $defs = custom_command_builder_definitions();
    $palette = [];

    foreach ($defs as $def) {
        $cat = isset($def['category']) && is_string($def['category']) && $def['category'] !== ''
            ? $def['category']
            : 'other';

        if (!isset($palette[$cat]) || !is_array($palette[$cat])) {
            $palette[$cat] = [];
        }

        $palette[$cat][] = $def;
    }

    ksort($palette);

    return $palette;
}

function custom_command_builder_node_payload(string $type): array
{
    $defs = custom_command_builder_raw_definitions();

    if (!isset($defs[$type]) || !is_array($defs[$type])) {
        return [];
    }

    $def = $defs[$type];

    $defType = isset($def['loadout']['type']) ? $def['loadout']['type'] : ($def['type'] ?? '');
    $defData = isset($def['default']) && is_array($def['default']) ? $def['default']
        : (isset($def['defaults']) && is_array($def['defaults']) ? $def['defaults'] : []);

    return [
        'type' => $defType,
        'data' => $defData,
    ];
}
