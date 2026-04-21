<?php
declare(strict_types=1);
//
// Zentrale Hilfsfunktionen für Bot-Token-Verschlüsselung (AES-256-CBC).
//
// Speicherformat (bot_instances):
//   bot_token_encrypted  → 'enc:<base64(iv16 + ciphertext)>'
//   bot_token_enc_meta   → '{"alg":"aes-256-cbc","v":1}'
//
// Legacy-Tokens (bot_token_enc_meta IS NULL):
//   bot_token_encrypted  → plain text, direkt verwendbar.

if (!function_exists('bh_bot_token_get_key')) {
    function bh_bot_token_get_key(): string
    {
        static $cachedKey = null;

        if ($cachedKey !== null) {
            return $cachedKey;
        }

        $cfgPath = dirname(__DIR__) . '/db/config/secret.php';
        if (!is_file($cfgPath) || !is_readable($cfgPath)) {
            throw new RuntimeException('Bot-Token: Secret-Config nicht lesbar (' . $cfgPath . ')');
        }

        $cfg = require $cfgPath;

        foreach (['APP_KEY', 'app_key'] as $k) {
            $val = trim((string)($cfg[$k] ?? ''));
            if ($val !== '') {
                $cachedKey = hash('sha256', $val, true); // 32-Byte AES-Key
                return $cachedKey;
            }
        }

        throw new RuntimeException('Bot-Token: APP_KEY fehlt in secret.php');
    }
}

if (!function_exists('bh_bot_token_encrypt')) {
    /**
     * Verschlüsselt einen Bot-Token mit AES-256-CBC.
     *
     * @return array{encrypted: string, meta: string}
     */
    function bh_bot_token_encrypt(string $plain): array
    {
        if ($plain === '') {
            throw new InvalidArgumentException('Bot-Token darf nicht leer sein.');
        }

        $key = bh_bot_token_get_key();
        $iv  = random_bytes(16);

        $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($cipher === false) {
            throw new RuntimeException('Bot-Token: Verschlüsselung fehlgeschlagen (openssl_encrypt).');
        }

        return [
            'encrypted' => 'enc:' . base64_encode($iv . $cipher),
            'meta'      => json_encode(['alg' => 'aes-256-cbc', 'v' => 1], JSON_UNESCAPED_SLASHES),
        ];
    }
}

if (!function_exists('bh_bot_token_decrypt')) {
    /**
     * Entschlüsselt einen Bot-Token.
     *
     * Unterstützt:
     *  - Legacy (meta = null/leer): `bot_token_encrypted` enthält den Klartext direkt.
     *  - Verschlüsselt (meta gesetzt): `bot_token_encrypted` = 'enc:<base64>'.
     *
     * @throws RuntimeException wenn Entschlüsselung fehlschlägt.
     */
    function bh_bot_token_decrypt(string $stored, mixed $meta): string
    {
        $stored = trim($stored);
        if ($stored === '') {
            return '';
        }

        $metaIsEmpty = ($meta === null || $meta === '' || $meta === 'null');

        // Legacy: plain text gespeichert
        if ($metaIsEmpty) {
            return $stored;
        }

        // Verschlüsselt: muss mit 'enc:' beginnen
        if (!str_starts_with($stored, 'enc:')) {
            // Meta vorhanden aber kein enc:-Präfix → trotzdem als Klartext behandeln
            // (z. B. Migrationspfad, Fallback)
            return $stored;
        }

        $raw = base64_decode(substr($stored, 4), strict: true);

        if ($raw === false || strlen($raw) <= 16) {
            throw new RuntimeException('Bot-Token: Entschlüsselung fehlgeschlagen (ungültiges Base64 oder zu kurz).');
        }

        $iv     = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $key    = bh_bot_token_get_key();

        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($plain === false) {
            throw new RuntimeException('Bot-Token: Entschlüsselung fehlgeschlagen (openssl_decrypt).');
        }

        return $plain;
    }
}

if (!function_exists('bh_bot_token_resolve')) {
    /**
     * Liest und entschlüsselt den Bot-Token aus einer DB-Zeile (bot_instances).
     *
     * @param array $row  Ergebnis-Row mit 'bot_token_encrypted' und 'bot_token_enc_meta'.
     * @return array{ok: bool, token: string|null, error: string|null}
     */
    function bh_bot_token_resolve(array $row): array
    {
        $stored = trim((string)($row['bot_token_encrypted'] ?? ''));
        $meta   = $row['bot_token_enc_meta'] ?? null;

        if ($stored === '') {
            return ['ok' => false, 'token' => null, 'error' => 'Kein Bot-Token gesetzt.'];
        }

        try {
            $plain = bh_bot_token_decrypt($stored, $meta);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'token' => null, 'error' => $e->getMessage()];
        }

        if ($plain === '') {
            return ['ok' => false, 'token' => null, 'error' => 'Token nach Entschlüsselung leer.'];
        }

        return ['ok' => true, 'token' => $plain, 'error' => null];
    }
}
