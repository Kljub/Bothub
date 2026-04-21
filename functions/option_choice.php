<?php
declare(strict_types=1);
# PFAD: /functions/option_choice.php

/**
 * Hilfsfunktionen fuer Choice-Optionen im Custom Command Builder.
 *
 * Diese Datei enthaelt bewusst nur Fachlogik / Normalisierung / Validierung
 * und keine Builder-Definition. Die Builder-Definition bleibt in:
 * /functions/builder/options/option_choice.php
 */

if (!function_exists('bh_option_choice_normalize_list')) {
    /**
     * Normalisiert eine rohe Choice-Liste in ein einheitliches Array-Format.
     *
     * Erwartetes Ergebnisformat:
     * [
     *   ['name' => 'Label', 'value' => 'value'],
     *   ...
     * ]
     *
     * @param mixed $choices
     * @param int $maxItems
     * @return array<int, array{name:string,value:string}>
     */
    function bh_option_choice_normalize_list(mixed $choices, int $maxItems = 25): array
    {
        if ($maxItems < 1) {
            $maxItems = 1;
        }

        $result = [];

        if (!is_array($choices)) {
            return $result;
        }

        foreach ($choices as $entry) {
            if (count($result) >= $maxItems) {
                break;
            }

            if (is_array($entry)) {
                $name = isset($entry['name']) ? trim((string)$entry['name']) : '';
                $value = isset($entry['value']) ? trim((string)$entry['value']) : '';

                if ($name === '' && isset($entry['label'])) {
                    $name = trim((string)$entry['label']);
                }

                if ($value === '' && isset($entry['id'])) {
                    $value = trim((string)$entry['id']);
                }

                if ($name === '' && $value !== '') {
                    $name = $value;
                }

                if ($value === '' && $name !== '') {
                    $value = $name;
                }

                if ($name === '' || $value === '') {
                    continue;
                }

                $result[] = [
                    'name' => mb_substr($name, 0, 100),
                    'value' => mb_substr($value, 0, 100),
                ];
                continue;
            }

            if (is_string($entry)) {
                $value = trim($entry);
                if ($value === '') {
                    continue;
                }

                $result[] = [
                    'name' => mb_substr($value, 0, 100),
                    'value' => mb_substr($value, 0, 100),
                ];
            }
        }

        return $result;
    }
}

if (!function_exists('bh_option_choice_cleanup_duplicates')) {
    /**
     * Entfernt doppelte Choice-Values, behaelt den ersten Eintrag.
     *
     * @param array<int, array{name:string,value:string}> $choices
     * @return array<int, array{name:string,value:string}>
     */
    function bh_option_choice_cleanup_duplicates(array $choices): array
    {
        $result = [];
        $seen = [];

        foreach ($choices as $choice) {
            $value = isset($choice['value']) ? trim((string)$choice['value']) : '';
            $name = isset($choice['name']) ? trim((string)$choice['name']) : '';

            if ($name === '' || $value === '') {
                continue;
            }

            $key = mb_strtolower($value);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = [
                'name' => $name,
                'value' => $value,
            ];
        }

        return $result;
    }
}

if (!function_exists('bh_option_choice_prepare')) {
    /**
     * Vollstaendige Aufbereitung einer Choice-Liste:
     * - normalisieren
     * - max. Anzahl beachten
     * - Duplikate entfernen
     *
     * @param mixed $choices
     * @param int $maxItems
     * @return array<int, array{name:string,value:string}>
     */
    function bh_option_choice_prepare(mixed $choices, int $maxItems = 25): array
    {
        $normalized = bh_option_choice_normalize_list($choices, $maxItems);
        return bh_option_choice_cleanup_duplicates($normalized);
    }
}

if (!function_exists('bh_option_choice_validate')) {
    /**
     * Validiert eine Choice-Liste.
     *
     * Rueckgabeformat:
     * [
     *   'valid' => bool,
     *   'errors' => string[],
     *   'choices' => array<int, array{name:string,value:string}>
     * ]
     *
     * @param mixed $choices
     * @param int $maxItems
     * @return array{
     *   valid: bool,
     *   errors: array<int, string>,
     *   choices: array<int, array{name:string,value:string}>
     * }
     */
    function bh_option_choice_validate(mixed $choices, int $maxItems = 25): array
    {
        $errors = [];
        $prepared = bh_option_choice_prepare($choices, $maxItems);

        if (!is_array($choices)) {
            $errors[] = 'Choices muessen als Array uebergeben werden.';
        }

        if (count($prepared) > $maxItems) {
            $errors[] = 'Es sind maximal ' . $maxItems . ' Choices erlaubt.';
            $prepared = array_slice($prepared, 0, $maxItems);
        }

        if (count($prepared) === 0) {
            $errors[] = 'Mindestens eine gueltige Choice ist erforderlich.';
        }

        $seenValues = [];

        foreach ($prepared as $index => $choice) {
            $name = trim((string)($choice['name'] ?? ''));
            $value = trim((string)($choice['value'] ?? ''));

            if ($name === '') {
                $errors[] = 'Choice #' . ($index + 1) . ' hat keinen Namen.';
            }

            if ($value === '') {
                $errors[] = 'Choice #' . ($index + 1) . ' hat keinen Wert.';
            }

            if (mb_strlen($name) > 100) {
                $errors[] = 'Choice #' . ($index + 1) . ' Name ist zu lang.';
            }

            if (mb_strlen($value) > 100) {
                $errors[] = 'Choice #' . ($index + 1) . ' Value ist zu lang.';
            }

            $dedupeKey = mb_strtolower($value);
            if ($value !== '' && isset($seenValues[$dedupeKey])) {
                $errors[] = 'Choice-Value "' . $value . '" ist mehrfach vorhanden.';
            }
            $seenValues[$dedupeKey] = true;
        }

        return [
            'valid' => count($errors) === 0,
            'errors' => $errors,
            'choices' => $prepared,
        ];
    }
}

if (!function_exists('bh_option_choice_encode_json')) {
    /**
     * Wandelt eine Choice-Liste in JSON fuer Speicherung um.
     *
     * @param mixed $choices
     * @param int $maxItems
     * @return string
     */
    function bh_option_choice_encode_json(mixed $choices, int $maxItems = 25): string
    {
        $prepared = bh_option_choice_prepare($choices, $maxItems);

        $json = json_encode(
            $prepared,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return is_string($json) ? $json : '[]';
    }
}

if (!function_exists('bh_option_choice_decode_json')) {
    /**
     * Liest Choices aus JSON und normalisiert sie.
     *
     * @param string|null $json
     * @param int $maxItems
     * @return array<int, array{name:string,value:string}>
     */
    function bh_option_choice_decode_json(?string $json, int $maxItems = 25): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return bh_option_choice_prepare($decoded, $maxItems);
    }
}