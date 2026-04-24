<?php
declare(strict_types=1);
# PFAD: /functions/translate.php

/**
 * Lookup-Tabelle: String-Shorthand → vollständiges Feldobjekt.
 *
 * Jeder Eintrag ist ein assoziatives Array das mindestens 'key' und 'type'
 * enthält – dieselbe Struktur die auch direkt in properties geschrieben werden kann.
 * Shorthands ohne 'key' (z. B. __open_*) rendern nur UI-Elemente, kein Config-Feld.
 */
function bh_named_fields(): array
{
    return [

        // ── Trigger ────────────────────────────────────────────────────────
        'display_name' => [
            'key'         => 'display_name',
            'type'        => 'text',
            'label'       => 'Anzeigename',
            'placeholder' => 'Mein Command',
        ],
        'name' => [
            'key'         => 'name',
            'type'        => 'text',
            'label'       => 'Command Name',
            'placeholder' => 'command',
            'help'        => 'Nur Kleinbuchstaben, Zahlen, – und _ erlaubt.',
            'max_length'  => 32,
            'pattern'     => '^[a-z0-9_-]{1,32}$',
        ],
        'cooldown_seconds' => [
            'key'     => 'cooldown_seconds',
            'type'    => 'number',
            'label'   => 'Cooldown (Sekunden)',
            'min'     => 1,
            'max'     => 86400,
            'show_if' => ['cooldown_type', ['user', 'server']],
        ],
        'required_permissions' => [
            'key'   => 'required_permissions',
            'type'  => 'permissions_select',
            'label' => 'Benötigte Berechtigungen',
            'help'  => 'Nur Nutzer mit diesen Berechtigungen können den Command ausführen.',
        ],

        // ── Allgemein ──────────────────────────────────────────────────────
        'description' => [
            'key'         => 'description',
            'type'        => 'text',
            'label'       => 'Beschreibung',
            'placeholder' => 'Was macht dieser Block?',
        ],
        'ephemeral' => [
            'key'     => 'ephemeral',
            'type'    => 'select',
            'label'   => 'Ephemeral',
            'help'    => 'Nur für den Ausführenden sichtbar.',
            'options' => [
                ['value' => '',  'label' => 'Nein'],
                ['value' => '1', 'label' => 'Ja'],
            ],
        ],
        'var_name' => [
            'key'         => 'var_name',
            'type'        => 'text',
            'label'       => 'Als Variable speichern',
            'placeholder' => 'my_message',
            'help'        => 'Optionale Variable um später auf diese Nachricht zu verweisen.',
        ],
        'var_key' => [
            'key'         => 'var_key',
            'type'        => 'text',
            'label'       => 'Variable Key',
            'placeholder' => 'my_variable',
        ],

        // ── IDs ────────────────────────────────────────────────────────────
        'message_id' => [
            'key'         => 'message_id',
            'type'        => 'text',
            'label'       => 'Message-ID',
            'placeholder' => '{message.id}',
        ],
        'channel_id' => [
            'key'         => 'channel_id',
            'type'        => 'text',
            'label'       => 'Channel-ID',
            'placeholder' => '{channel.id}',
        ],
        'custom_id' => [
            'key'         => 'custom_id',
            'type'        => 'text',
            'label'       => 'Custom ID',
            'placeholder' => 'my_component_id',
            'help'        => 'Eindeutige ID für diesen Component.',
        ],

        // ── Send/Edit Message Targets ──────────────────────────────────────
        'target_message_id' => [
            'key'         => 'target_message_id',
            'type'        => 'text',
            'label'       => 'Ziel Message-ID',
            'placeholder' => '{message.id}',
            'show_if'     => ['response_type', ['reply_message', 'edit_action']],
        ],
        'target_channel_id' => [
            'key'         => 'target_channel_id',
            'type'        => 'text',
            'label'       => 'Ziel Channel-ID',
            'placeholder' => '{channel.id}',
            'show_if'     => ['response_type', ['specific_channel']],
        ],
        'target_option_name' => [
            'key'         => 'target_option_name',
            'type'        => 'text',
            'label'       => 'Channel Option Name',
            'placeholder' => 'channel_option',
            'show_if'     => ['response_type', ['channel_option']],
        ],
        'target_dm_option_name' => [
            'key'         => 'target_dm_option_name',
            'type'        => 'text',
            'label'       => 'DM User Option Name',
            'placeholder' => 'user_option',
            'show_if'     => ['response_type', ['dm_user_option']],
        ],
        'target_user_id' => [
            'key'         => 'target_user_id',
            'type'        => 'text',
            'label'       => 'Ziel User-ID',
            'placeholder' => '{user.id}',
            'show_if'     => ['response_type', ['dm_specific_user']],
        ],
        'edit_target_var' => [
            'key'         => 'edit_target_var',
            'type'        => 'text',
            'label'       => 'Variable der zu bearbeitenden Nachricht',
            'placeholder' => 'my_message',
            'help'        => 'Variable aus einem vorherigen „Send or Edit"-Block.',
            'show_if'     => ['response_type', ['edit_action']],
        ],

        // ── Actions ────────────────────────────────────────────────────────
        'duration_ms' => [
            'key'         => 'duration_ms',
            'type'        => 'number',
            'label'       => 'Dauer (ms)',
            'placeholder' => '1000',
            'min'         => 0,
        ],
        'emoji' => [
            'key'         => 'emoji',
            'type'        => 'text',
            'label'       => 'Emoji',
            'placeholder' => '😀 oder <:name:id>',
        ],
        'emojis' => [
            'key'   => 'emojis',
            'type'  => 'emoji_picker',
            'label' => 'Emojis',
        ],
        'placeholder' => [
            'key'         => 'placeholder',
            'type'        => 'text',
            'label'       => 'Platzhalter',
            'placeholder' => 'Wähle eine Option…',
        ],
        'menu_type' => [
            'key'     => 'menu_type',
            'type'    => 'select',
            'label'   => 'Menü-Typ',
            'options' => [
                ['value' => '',             'label' => 'String Select'],
                ['value' => 'user',         'label' => 'User Select'],
                ['value' => 'role',         'label' => 'Role Select'],
                ['value' => 'mentionable',  'label' => 'Mentionable Select'],
                ['value' => 'channel',      'label' => 'Channel Select'],
            ],
        ],
        'options' => [
            'key'       => 'options',
            'type'      => 'options_list',
            'label'     => 'Optionen',
            'max_items' => 25,
            'show_if'   => ['menu_type', ['']],
        ],
        'component_order' => [
            'key'   => 'component_order',
            'type'  => 'switch',
            'label' => 'Component Ordering aktivieren',
            'help'  => 'Reihenfolge unter anderen Message-Komponenten festlegen.',
        ],
        'mode' => [
            'key'     => 'mode',
            'type'    => 'select',
            'label'   => 'Loop Modus',
            'options' => [
                ['value' => 'off',   'label' => 'Off'],
                ['value' => 'track', 'label' => 'Track'],
                ['value' => 'queue', 'label' => 'Queue'],
            ],
        ],

        // ── Form ───────────────────────────────────────────────────────────
        'form_name' => [
            'key'         => 'form_name',
            'type'        => 'text',
            'label'       => 'Form Name',
            'placeholder' => 'my-form',
        ],
        'form_title' => [
            'key'         => 'form_title',
            'type'        => 'text',
            'label'       => 'Form Titel',
            'placeholder' => 'Mein Formular',
        ],
        'block_label' => [
            'key'         => 'block_label',
            'type'        => 'text',
            'label'       => 'Block Label',
            'placeholder' => 'Send a Form',
        ],

        // ── Options (Slash Command Options) ────────────────────────────────
        'required' => [
            'key'     => 'required',
            'type'    => 'select',
            'label'   => 'Pflichtfeld',
            'options' => [
                ['value' => 'false', 'label' => 'Optional'],
                ['value' => 'true',  'label' => 'Pflichtfeld'],
            ],
        ],
        'choices' => [
            'key'       => 'choices',
            'type'      => 'options_list',
            'label'     => 'Auswahl-Optionen',
            'max_items' => 25,
        ],
        'choice' => [
            'key'     => 'choice',
            'type'    => 'select',
            'label'   => 'Auswahl',
            'options' => [
                ['value' => 'true',  'label' => 'True'],
                ['value' => 'false', 'label' => 'False'],
            ],
        ],

        // ── Conditions ─────────────────────────────────────────────────────
        'conditions' => [
            'type' => 'comparison_conditions',
        ],

        // ── Spezial-Buttons (öffnen eigene Builder-Fenster) ────────────────
        '__open_message_builder' => [
            'type' => 'message_builder_btn',
        ],
        '__open_request_builder' => [
            'type' => 'request_builder_btn',
        ],
        '__open_form_builder' => [
            'type'  => 'form_builder',
            'label' => 'Form Felder',
        ],
    ];
}

function bh_builder_translate_definition(array $def): array
{
    // ── Loadout (new format) with flat-key fallback ────────────────────────
    $loadout = isset($def['loadout']) && is_array($def['loadout']) ? $def['loadout'] : [];

    $type = isset($loadout['type']) && is_string($loadout['type']) && $loadout['type'] !== ''
        ? $loadout['type']
        : (isset($def['type']) && is_string($def['type']) ? $def['type'] : '');

    $category = isset($loadout['category']) && is_string($loadout['category']) && $loadout['category'] !== ''
        ? $loadout['category']
        : (isset($def['category']) && is_string($def['category']) && $def['category'] !== '' ? $def['category'] : 'other');

    $title = isset($loadout['title']) && is_string($loadout['title']) && $loadout['title'] !== ''
        ? $loadout['title']
        : (isset($def['title']) && is_string($def['title']) && $def['title'] !== '' ? $def['title'] : $type);

    $subtitle = isset($loadout['subtitle']) && is_string($loadout['subtitle'])
        ? $loadout['subtitle']
        : (isset($def['subtitle']) && is_string($def['subtitle']) ? $def['subtitle'] : '');

    $description = isset($loadout['description']) && is_string($loadout['description'])
        ? $loadout['description']
        : (isset($def['description']) && is_string($def['description']) ? $def['description'] : '');

    $icon = isset($loadout['icon']) && is_string($loadout['icon'])
        ? $loadout['icon']
        : (isset($def['icon']) && is_string($def['icon']) ? $def['icon'] : '');

    // Color: new format uses colors.primary, old format used a flat 'color' key
    $colors = isset($loadout['colors']) && is_array($loadout['colors']) ? $loadout['colors'] : [];
    $color = isset($colors['primary']) && is_string($colors['primary']) && $colors['primary'] !== ''
        ? $colors['primary']
        : (isset($def['color']) && is_string($def['color']) && $def['color'] !== '' ? $def['color'] : 'gray');

    $badge = isset($loadout['badge']) && is_string($loadout['badge'])
        ? $loadout['badge']
        : (isset($def['badge']) && is_string($def['badge']) ? $def['badge'] : '');

    $ui = $loadout['ui'] ?? $def['ui'] ?? [];

    // ── Ports ──────────────────────────────────────────────────────────────
    $ports   = isset($def['ports']) && is_array($def['ports']) ? $def['ports'] : [];
    $inputs  = isset($ports['inputs'])  && is_array($ports['inputs'])  ? $ports['inputs']  : [];
    $rawOutputs = isset($ports['outputs']) && is_array($ports['outputs']) ? $ports['outputs'] : [];

    // Build the two port arrays the JS expects:
    //   outputs      — flat array of port-name strings (for port button rendering)
    //   output_ports — full port objects with 'key' (= name) and 'kind' (= 'component' when type is component)
    $translatedOutputs = [];
    $outputPortDefs    = [];

    foreach ($rawOutputs as $port) {
        if (is_array($port)) {
            // New format: {name, type, description, ...}
            // Old format: {key, kind, label, spawn_type, ...}
            $name = isset($port['name']) && is_string($port['name']) && $port['name'] !== ''
                ? $port['name']
                : (isset($port['key']) && is_string($port['key']) && $port['key'] !== '' ? $port['key'] : null);

            if ($name === null) {
                continue;
            }

            $translatedOutputs[] = $name;

            // Normalise to the shape JS expects on output_ports entries:
            // { key, kind, label, spawn_type, max_connections, ... }
            $portDef = $port;
            $portDef['key'] = $name;

            // Map new-format 'type: component' → old-format 'kind: component'
            if (!isset($portDef['kind'])) {
                $portType = isset($port['type']) && is_string($port['type']) ? $port['type'] : '';
                if ($portType === 'component') {
                    $portDef['kind'] = 'component';
                }
            }

            // Carry label and spawn_type through if present
            if (!isset($portDef['label']) && isset($port['description'])) {
                $portDef['label'] = $port['description'];
            }

            $outputPortDefs[] = $portDef;
        } elseif (is_string($port) && $port !== '') {
            $translatedOutputs[] = $port;
            $outputPortDefs[]    = ['key' => $port];
        }
    }

    // ── Defaults ───────────────────────────────────────────────────────────
    // New format uses 'default', old format used 'defaults'
    $defaults = isset($def['default']) && is_array($def['default'])
        ? $def['default']
        : (isset($def['defaults']) && is_array($def['defaults']) ? $def['defaults'] : []);

    // Merge permissions object into defaults so the JS config initialises correctly
    if (isset($def['permissions']) && is_array($def['permissions'])) {
        $defaults = array_merge($def['permissions'], $defaults);
    }

    // ── Properties → Fields ────────────────────────────────────────────────
    $namedFields = bh_named_fields();
    $fields = [];

    foreach (isset($def['properties']) && is_array($def['properties']) ? $def['properties'] : [] as $prop) {
        if (is_array($prop)) {
            $fields[] = $prop;
        } elseif (is_string($prop) && $prop !== '') {
            if (isset($namedFields[$prop])) {
                $fields[] = $namedFields[$prop];
            }
        }
    }

    return [
        'type'         => $type,
        'category'     => $category,
        'label'        => $title,
        'title'        => $title,
        'subtitle'     => $subtitle,
        'description'  => $description,
        'icon'         => $icon,
        'color'        => $color,
        'badge'        => $badge,
        'ui'           => $ui,
        'input'        => !empty($inputs),
        'inputs'       => $inputs,
        'outputs'      => $translatedOutputs,
        'output_ports' => $outputPortDefs,
        'defaults'     => $defaults,
        'fields'       => $fields,
        'raw'          => $def,
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
