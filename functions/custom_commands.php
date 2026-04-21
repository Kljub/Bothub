<?php
declare(strict_types=1);

require_once __DIR__ . '/html.php';

if (!defined('BH_CC_BOT_TABLE')) {
    define('BH_CC_BOT_TABLE', 'bot_instances');
}
if (!defined('BH_CC_COMMAND_TABLE')) {
    define('BH_CC_COMMAND_TABLE', 'bot_custom_commands');
}
if (!defined('BH_CC_BUILDER_TABLE')) {
    define('BH_CC_BUILDER_TABLE', 'bot_custom_command_builders');
}

if (!function_exists('bh_cc_get_pdo')) {
    function bh_cc_get_pdo(): PDO
    {
        static $pdo = null;

        if ($pdo instanceof PDO) {
            return $pdo;
        }

        $cfgPath = dirname(__DIR__) . '/db/config/app.php';
        if (!is_file($cfgPath) || !is_readable($cfgPath)) {
            throw new RuntimeException('DB Config nicht gefunden/lesbar: ' . $cfgPath);
        }

        $cfg = require $cfgPath;
        if (!is_array($cfg) || !isset($cfg['db']) || !is_array($cfg['db'])) {
            throw new RuntimeException('DB Config ungültig.');
        }

        $db = $cfg['db'];
        $host = trim((string)($db['host'] ?? ''));
        $port = trim((string)($db['port'] ?? '3306'));
        $name = trim((string)($db['name'] ?? ''));
        $user = trim((string)($db['user'] ?? ''));
        $pass = (string)($db['pass'] ?? '');

        if ($host === '' || $name === '' || $user === '') {
            throw new RuntimeException('DB Config unvollständig.');
        }

        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return $pdo;
    }
}

if (!function_exists('bh_cc_table_exists')) {
    function bh_cc_table_exists(string $tableName): bool
    {
        static $cache = [];

        if (isset($cache[$tableName])) {
            return $cache[$tableName];
        }

        $pdo = bh_cc_get_pdo();
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name LIMIT 1');
        $stmt->execute([':table_name' => $tableName]);

        $cache[$tableName] = (bool)$stmt->fetchColumn();
        return $cache[$tableName];
    }
}

if (!function_exists('bh_cc_commands_table_ready')) {
    function bh_cc_commands_table_ready(): bool
    {
        return bh_cc_table_exists(BH_CC_COMMAND_TABLE);
    }
}

if (!function_exists('bh_cc_builder_table_ready')) {
    function bh_cc_builder_table_ready(): bool
    {
        return bh_cc_table_exists(BH_CC_BUILDER_TABLE);
    }
}

if (!function_exists('bh_cc_get_user_bots')) {
    function bh_cc_get_user_bots(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        if (!bh_cc_table_exists(BH_CC_BOT_TABLE)) {
            return [];
        }

        $pdo = bh_cc_get_pdo();
        $sql = 'SELECT id, display_name FROM ' . BH_CC_BOT_TABLE . ' WHERE owner_user_id = :user_id ORDER BY id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);

        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $botId = (int)($row['id'] ?? 0);
            $name = trim((string)($row['display_name'] ?? ''));
            if ($botId <= 0) {
                continue;
            }

            $out[] = [
                'id' => $botId,
                'name' => $name !== '' ? $name : ('Bot #' . $botId),
            ];
        }

        return $out;
    }
}

if (!function_exists('bh_cc_user_owns_bot')) {
    function bh_cc_user_owns_bot(int $userId, int $botId): bool
    {
        if ($userId <= 0 || $botId <= 0) {
            return false;
        }

        if (!bh_cc_table_exists(BH_CC_BOT_TABLE)) {
            return false;
        }

        $pdo = bh_cc_get_pdo();
        $sql = 'SELECT id FROM ' . BH_CC_BOT_TABLE . ' WHERE id = :bot_id AND owner_user_id = :user_id LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':bot_id' => $botId,
            ':user_id' => $userId,
        ]);

        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('bh_cc_normalize_display_name')) {
    function bh_cc_normalize_display_name(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value);
        return is_string($value) ? mb_substr($value, 0, 100, 'UTF-8') : '';
    }
}

if (!function_exists('bh_cc_normalize_slash_name')) {
    function bh_cc_normalize_slash_name(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        $value = preg_replace('/[^a-z0-9_-]/', '', $value);
        return is_string($value) ? mb_substr($value, 0, 32, 'UTF-8') : '';
    }
}

if (!function_exists('bh_cc_validate_command_input')) {
    function bh_cc_validate_command_input(string $displayName, string $slashName, string $description): array
    {
        $errors = [];

        if ($displayName === '') {
            $errors[] = 'Bitte einen sichtbaren Command-Namen angeben.';
        }

        if ($slashName === '') {
            $errors[] = 'Bitte einen Slash-Namen angeben.';
        } elseif (!preg_match('/^[a-z0-9_-]{1,32}$/', $slashName)) {
            $errors[] = 'Der Slash-Name darf nur Kleinbuchstaben, Zahlen, Unterstriche und Bindestriche enthalten.';
        }

        if (mb_strlen($description, 'UTF-8') > 255) {
            $errors[] = 'Die Beschreibung darf maximal 255 Zeichen lang sein.';
        }

        return $errors;
    }
}

if (!function_exists('bh_cc_list_custom_commands')) {
    function bh_cc_list_custom_commands(int $userId, int $botId): array
    {
        if ($userId <= 0 || $botId <= 0) {
            return [];
        }

        if (!bh_cc_user_owns_bot($userId, $botId)) {
            return [];
        }

        if (!bh_cc_commands_table_ready()) {
            return [];
        }

        $pdo = bh_cc_get_pdo();

        // Try with group_name; fall back gracefully if migration hasn't run yet
        try {
            $sql = 'SELECT id, bot_id, name, slash_name, description, is_enabled,
                           group_name, created_at, updated_at
                    FROM ' . BH_CC_COMMAND_TABLE . '
                    WHERE bot_id = :bot_id
                    ORDER BY group_name IS NULL ASC, group_name ASC, name ASC, id ASC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':bot_id' => $botId]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'group_name')) {
                $sql = 'SELECT id, bot_id, name, slash_name, description, is_enabled,
                               NULL AS group_name, created_at, updated_at
                        FROM ' . BH_CC_COMMAND_TABLE . '
                        WHERE bot_id = :bot_id
                        ORDER BY name ASC, id ASC';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bot_id' => $botId]);
            } else {
                throw $e;
            }
        }

        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('bh_cc_get_custom_command')) {
    function bh_cc_get_custom_command(int $userId, int $commandId): ?array
    {
        if ($userId <= 0 || $commandId <= 0) {
            return null;
        }

        if (!bh_cc_commands_table_ready()) {
            return null;
        }

        $pdo = bh_cc_get_pdo();
        $sql = 'SELECT c.id, c.bot_id, c.name, c.slash_name, c.description, c.is_enabled, c.created_at, c.updated_at
                FROM ' . BH_CC_COMMAND_TABLE . ' c
                INNER JOIN ' . BH_CC_BOT_TABLE . ' b ON b.id = c.bot_id
                WHERE c.id = :command_id
                  AND b.owner_user_id = :user_id
                LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':command_id' => $commandId,
            ':user_id' => $userId,
        ]);

        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('bh_cc_create_custom_command')) {
    function bh_cc_create_custom_command(int $userId, int $botId, string $displayName, string $slashName, string $description): array
    {
        $displayName = bh_cc_normalize_display_name($displayName);
        $slashName = bh_cc_normalize_slash_name($slashName);
        $description = trim(mb_substr($description, 0, 255, 'UTF-8'));

        if ($userId <= 0) {
            return ['ok' => false, 'error' => 'Ungültiger Benutzer.'];
        }

        if ($botId <= 0 || !bh_cc_user_owns_bot($userId, $botId)) {
            return ['ok' => false, 'error' => 'Der Bot wurde nicht gefunden oder gehört dir nicht.'];
        }

        if (!bh_cc_commands_table_ready()) {
            return ['ok' => false, 'error' => 'Die Tabelle bot_custom_commands fehlt noch.'];
        }

        $errors = bh_cc_validate_command_input($displayName, $slashName, $description);
        if ($errors !== []) {
            return ['ok' => false, 'error' => implode(' ', $errors)];
        }

        $pdo = bh_cc_get_pdo();

        $checkSql = 'SELECT id FROM ' . BH_CC_COMMAND_TABLE . ' WHERE bot_id = :bot_id AND slash_name = :slash_name LIMIT 1';
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->execute([
            ':bot_id' => $botId,
            ':slash_name' => $slashName,
        ]);

        if ($checkStmt->fetchColumn()) {
            return ['ok' => false, 'error' => 'Für diesen Bot existiert bereits ein Command mit diesem Slash-Namen.'];
        }

        $sql = 'INSERT INTO ' . BH_CC_COMMAND_TABLE . ' (
                    bot_id, name, slash_name, description, is_enabled, created_by_user_id, updated_by_user_id, created_at, updated_at
                ) VALUES (
                    :bot_id, :name, :slash_name, :description, 1, :created_by_user_id, :updated_by_user_id, NOW(), NOW()
                )';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':bot_id' => $botId,
            ':name' => $displayName,
            ':slash_name' => $slashName,
            ':description' => $description,
            ':created_by_user_id' => $userId,
            ':updated_by_user_id' => $userId,
        ]);

        return [
            'ok' => true,
            'id' => (int)$pdo->lastInsertId(),
        ];
    }
}

if (!function_exists('bh_cc_update_custom_command_meta')) {
    function bh_cc_update_custom_command_meta(int $userId, int $commandId, string $displayName, string $slashName, string $description): array
    {
        $displayName = bh_cc_normalize_display_name($displayName);
        $slashName = bh_cc_normalize_slash_name($slashName);
        $description = trim(mb_substr($description, 0, 255, 'UTF-8'));

        $command = bh_cc_get_custom_command($userId, $commandId);
        if ($command === null) {
            return ['ok' => false, 'error' => 'Der Command wurde nicht gefunden.'];
        }

        $errors = bh_cc_validate_command_input($displayName, $slashName, $description);
        if ($errors !== []) {
            return ['ok' => false, 'error' => implode(' ', $errors)];
        }

        $pdo = bh_cc_get_pdo();
        $sql = 'SELECT id
                FROM ' . BH_CC_COMMAND_TABLE . '
                WHERE bot_id = :bot_id
                  AND slash_name = :slash_name
                  AND id <> :command_id
                LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':bot_id' => (int)$command['bot_id'],
            ':slash_name' => $slashName,
            ':command_id' => $commandId,
        ]);

        if ($stmt->fetchColumn()) {
            return ['ok' => false, 'error' => 'Der Slash-Name ist bereits vergeben.'];
        }

        $updateSql = 'UPDATE ' . BH_CC_COMMAND_TABLE . '
                      SET name = :name,
                          slash_name = :slash_name,
                          description = :description,
                          updated_by_user_id = :updated_by_user_id,
                          updated_at = NOW()
                      WHERE id = :command_id
                      LIMIT 1';

        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute([
            ':name' => $displayName,
            ':slash_name' => $slashName,
            ':description' => $description,
            ':updated_by_user_id' => $userId,
            ':command_id' => $commandId,
        ]);

        return ['ok' => true];
    }
}

if (!function_exists('bh_cc_delete_custom_command')) {
    function bh_cc_delete_custom_command(int $userId, int $commandId): array
    {
        $command = bh_cc_get_custom_command($userId, $commandId);
        if ($command === null) {
            return ['ok' => false, 'error' => 'Der Command wurde nicht gefunden.'];
        }

        $pdo = bh_cc_get_pdo();

        if (bh_cc_builder_table_ready()) {
            $builderSql = 'DELETE FROM ' . BH_CC_BUILDER_TABLE . ' WHERE custom_command_id = :command_id';
            $builderStmt = $pdo->prepare($builderSql);
            $builderStmt->execute([':command_id' => $commandId]);
        }

        $sql = 'DELETE FROM ' . BH_CC_COMMAND_TABLE . ' WHERE id = :command_id LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':command_id' => $commandId]);

        return ['ok' => true];
    }
}

if (!function_exists('bh_cc_builder_get')) {
    function bh_cc_builder_get(int $userId, int $commandId): ?array
    {
        if ($userId <= 0 || $commandId <= 0) {
            return null;
        }

        $command = bh_cc_get_custom_command($userId, $commandId);
        if ($command === null) {
            return null;
        }

        $builderData = null;

        if (bh_cc_builder_table_ready()) {
            $pdo = bh_cc_get_pdo();
            $stmt = $pdo->prepare(
                'SELECT builder_json FROM ' . BH_CC_BUILDER_TABLE . '
                 WHERE custom_command_id = :command_id LIMIT 1'
            );
            $stmt->execute([':command_id' => $commandId]);
            $row = $stmt->fetch();

            if (is_array($row)) {
                $decoded = json_decode((string)($row['builder_json'] ?? ''), true);
                if (is_array($decoded)) {
                    $builderData = $decoded;
                }
            }
        }

        return [
            'command' => $command,
            'builder' => $builderData,
        ];
    }
}

if (!function_exists('bh_cc_builder_save')) {
    function bh_cc_builder_save(int $userId, int $commandId, string $builderJson): array
    {
        if ($userId <= 0 || $commandId <= 0) {
            return ['ok' => false, 'error' => 'Ungültige Parameter.'];
        }

        $command = bh_cc_get_custom_command($userId, $commandId);
        if ($command === null) {
            return ['ok' => false, 'error' => 'Der Command wurde nicht gefunden.'];
        }

        $decoded = json_decode($builderJson, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'Ungültiges Builder-JSON.'];
        }

        if (!bh_cc_builder_table_ready()) {
            return ['ok' => false, 'error' => 'Tabelle ' . BH_CC_BUILDER_TABLE . ' existiert nicht.'];
        }

        $pdo = bh_cc_get_pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO ' . BH_CC_BUILDER_TABLE . '
                (custom_command_id, builder_json, builder_version, created_at, updated_at)
             VALUES
                (:command_id, :builder_json, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                builder_json    = :builder_json2,
                builder_version = builder_version + 1,
                updated_at      = NOW()'
        );
        $stmt->execute([
            ':command_id'   => $commandId,
            ':builder_json' => $builderJson,
            ':builder_json2' => $builderJson,
        ]);

        return ['ok' => true];
    }
}

if (!function_exists('bh_notify_bot_reload')) {
    function bh_notify_bot_reload(int $botId): void
    {
        if ($botId <= 0) {
            return;
        }

        try {
            $secretPath = dirname(__DIR__) . '/db/config/secret.php';
            if (!is_file($secretPath) || !is_readable($secretPath)) {
                return;
            }

            $secret = require $secretPath;
            $appKey = trim((string)($secret['APP_KEY'] ?? ''));
            if ($appKey === '') {
                return;
            }

            $pdo  = bh_cc_get_pdo();
            $stmt = $pdo->query("SELECT endpoint FROM core_runners WHERE endpoint != '' ORDER BY id ASC");
            $runners = $stmt->fetchAll();

            if (!is_array($runners) || count($runners) === 0) {
                return;
            }

            foreach ($runners as $runner) {
                $endpoint = rtrim(trim((string)($runner['endpoint'] ?? '')), '/');
                if ($endpoint === '') {
                    continue;
                }

                $ch = curl_init($endpoint . '/reload/bot/' . $botId);
                if ($ch === false) {
                    continue;
                }

                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => '',
                    CURLOPT_TIMEOUT        => 4,
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_HTTPHEADER     => [
                        'Authorization: Bearer ' . $appKey,
                        'Content-Type: application/json',
                    ],
                ]);

                curl_exec($ch);
            }
        } catch (Throwable) {
            // Reload-Benachrichtigung ist nicht kritisch
        }
    }
}
if (!function_exists('bh_notify_slash_sync')) {
    /**
     * Ask the running bot to re-sync its slash commands without a full restart.
     * Falls back to a full reload if the bot is not running.
     */
    function bh_notify_slash_sync(int $botId): void
    {
        if ($botId <= 0) {
            return;
        }

        try {
            $secretPath = dirname(__DIR__) . '/db/config/secret.php';
            if (!is_file($secretPath) || !is_readable($secretPath)) {
                return;
            }

            $secret = require $secretPath;
            $appKey = trim((string)($secret['APP_KEY'] ?? ''));
            if ($appKey === '') {
                return;
            }

            $pdo     = bh_cc_get_pdo();
            $stmt    = $pdo->query("SELECT endpoint FROM core_runners WHERE endpoint != '' ORDER BY id ASC");
            $runners = $stmt->fetchAll();

            if (!is_array($runners) || count($runners) === 0) {
                return;
            }

            foreach ($runners as $runner) {
                $endpoint = rtrim(trim((string)($runner['endpoint'] ?? '')), '/');
                if ($endpoint === '') {
                    continue;
                }

                $ch = curl_init($endpoint . '/slash-sync/bot/' . $botId);
                if ($ch === false) {
                    continue;
                }

                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => '',
                    CURLOPT_TIMEOUT        => 8,
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_HTTPHEADER     => [
                        'Authorization: Bearer ' . $appKey,
                        'Content-Type: application/json',
                    ],
                ]);

                $response = curl_exec($ch);
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

                // If bot not running (404), fall back to full reload
                if ($httpCode === 404) {
                    bh_notify_bot_reload($botId);
                }
            }
        } catch (Throwable) {
            // Non-critical
        }
    }
}

if (!function_exists('bh_core_build_zip')) {
    /**
     * Build a ZIP of core/installer/src/ + package.json, excluding node_modules.
     * Returns the path to the temp ZIP file, or throws on failure.
     */
    function bh_core_build_zip(): string
    {
        $coreBase = dirname(__DIR__) . '/core/installer';
        $srcDir   = $coreBase . '/src';
        $pkgFile  = $coreBase . '/package.json';

        if (!is_dir($srcDir)) {
            throw new RuntimeException("Core-Quellverzeichnis nicht gefunden: $srcDir");
        }

        $tmpZip = sys_get_temp_dir() . '/bothub_core_update_' . time() . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("ZIP-Datei konnte nicht erstellt werden: $tmpZip");
        }

        // Add package.json
        if (is_file($pkgFile)) {
            $zip->addFile($pkgFile, 'package.json');
        }

        // Recursively add src/ (skip node_modules)
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $realPath  = $file->getRealPath();
            $localPath = 'src/' . ltrim(str_replace($srcDir, '', $realPath), '/\\');

            // Skip node_modules anywhere in the tree
            if (strpos($localPath, '/node_modules/') !== false || $localPath === 'src/node_modules') {
                continue;
            }

            if ($file->isDir()) {
                $zip->addEmptyDir($localPath);
            } else {
                $zip->addFile($realPath, $localPath);
            }
        }

        $zip->close();
        return $tmpZip;
    }
}

if (!function_exists('bh_core_update_runners')) {
    /**
     * Build a ZIP of the core source and send it to all registered runners.
     * Each runner will extract the ZIP, run npm install, then restart.
     * Optionally pass $version (e.g. "1.2.3") to write to version.json on the runner.
     * Returns an array of ['endpoint' => ..., 'ok' => bool, 'message' => ...].
     */
    function bh_core_update_runners(string $version = ''): array
    {
        $results = [];
        $tmpZip  = null;

        try {
            $secretPath = dirname(__DIR__) . '/db/config/secret.php';
            if (!is_file($secretPath) || !is_readable($secretPath)) {
                return [['ok' => false, 'message' => 'Secret-Datei nicht lesbar.']];
            }

            $secret = require $secretPath;
            $appKey = trim((string)($secret['APP_KEY'] ?? ''));
            if ($appKey === '') {
                return [['ok' => false, 'message' => 'APP_KEY fehlt.']];
            }

            $pdo     = bh_cc_get_pdo();
            $stmt    = $pdo->query("SELECT endpoint FROM core_runners WHERE endpoint != '' ORDER BY id ASC");
            $runners = $stmt->fetchAll();

            if (!is_array($runners) || count($runners) === 0) {
                return [['ok' => false, 'message' => 'Keine Core-Runner konfiguriert.']];
            }

            // Build ZIP once for all runners
            try {
                $tmpZip  = bh_core_build_zip();
                $zipData = file_get_contents($tmpZip);
                if ($zipData === false) throw new RuntimeException('ZIP konnte nicht gelesen werden.');
            } catch (Throwable $zipErr) {
                return [['ok' => false, 'message' => 'ZIP-Fehler: ' . $zipErr->getMessage()]];
            }

            foreach ($runners as $runner) {
                $endpoint = rtrim(trim((string)($runner['endpoint'] ?? '')), '/');
                if ($endpoint === '') continue;

                $ch = curl_init($endpoint . '/core/update');
                if ($ch === false) {
                    $results[] = ['endpoint' => $endpoint, 'ok' => false, 'message' => 'curl_init fehlgeschlagen.'];
                    continue;
                }

                $headers = [
                    'Authorization: Bearer ' . $appKey,
                    'Content-Type: application/octet-stream',
                    'Content-Length: ' . strlen($zipData),
                ];
                if ($version !== '') {
                    $headers[] = 'X-Core-Version: ' . $version;
                }

                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $zipData,
                    CURLOPT_TIMEOUT        => 60,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_HTTPHEADER     => $headers,
                ]);

                $response = curl_exec($ch);
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

                $decoded = $response ? json_decode($response, true) : null;
                $ok      = $httpCode === 200 && isset($decoded['ok']) && $decoded['ok'];
                $message = $decoded['message'] ?? ($ok ? 'Update gestartet.' : "HTTP $httpCode");

                $results[] = ['endpoint' => $endpoint, 'ok' => $ok, 'message' => $message];
            }
        } catch (Throwable $e) {
            $results[] = ['ok' => false, 'message' => 'Fehler: ' . $e->getMessage()];
        } finally {
            if ($tmpZip !== null && is_file($tmpZip)) {
                @unlink($tmpZip);
            }
        }

        return $results;
    }
}

if (!function_exists('bh_cc_toggle_command_enabled')) {
    function bh_cc_toggle_command_enabled(int $userId, int $commandId, bool $enabled): array
    {
        if ($userId <= 0 || $commandId <= 0) {
            return ['ok' => false, 'error' => 'Ungültige Parameter.'];
        }

        $command = bh_cc_get_custom_command($userId, $commandId);
        if ($command === null) {
            return ['ok' => false, 'error' => 'Command nicht gefunden.'];
        }

        $pdo = bh_cc_get_pdo();
        $stmt = $pdo->prepare(
            'UPDATE ' . BH_CC_COMMAND_TABLE . '
             SET is_enabled = :enabled, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([':enabled' => $enabled ? 1 : 0, ':id' => $commandId]);

        return ['ok' => true, 'is_enabled' => $enabled];
    }
}

if (!function_exists('bh_cc_set_command_group')) {
    function bh_cc_set_command_group(int $userId, int $commandId, string $groupName): array
    {
        if ($userId <= 0 || $commandId <= 0) {
            return ['ok' => false, 'error' => 'Ungültige Parameter.'];
        }

        $command = bh_cc_get_custom_command($userId, $commandId);
        if ($command === null) {
            return ['ok' => false, 'error' => 'Command nicht gefunden.'];
        }

        $groupName = trim(mb_substr($groupName, 0, 80, 'UTF-8'));
        $groupValue = $groupName !== '' ? $groupName : null;

        $pdo = bh_cc_get_pdo();
        $stmt = $pdo->prepare(
            'UPDATE ' . BH_CC_COMMAND_TABLE . '
             SET group_name = :group_name, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([':group_name' => $groupValue, ':id' => $commandId]);

        return ['ok' => true, 'group_name' => $groupValue];
    }
}

if (!function_exists('bh_cc_list_groups')) {
    function bh_cc_list_groups(int $userId, int $botId): array
    {
        if ($userId <= 0 || $botId <= 0 || !bh_cc_user_owns_bot($userId, $botId)) {
            return [];
        }

        if (!bh_cc_commands_table_ready()) {
            return [];
        }

        $pdo = bh_cc_get_pdo();
        $stmt = $pdo->prepare(
            'SELECT DISTINCT group_name
             FROM ' . BH_CC_COMMAND_TABLE . '
             WHERE bot_id = :bot_id AND group_name IS NOT NULL AND group_name != \'\'
             ORDER BY group_name ASC'
        );
        $stmt->execute([':bot_id' => $botId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return is_array($rows) ? $rows : [];
    }
}
