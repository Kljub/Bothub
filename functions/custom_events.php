<?php
declare(strict_types=1);

require_once __DIR__ . '/html.php';

if (!defined('BH_CE_BOT_TABLE')) {
    define('BH_CE_BOT_TABLE', 'bot_instances');
}
if (!defined('BH_CE_EVENT_TABLE')) {
    define('BH_CE_EVENT_TABLE', 'bot_custom_events');
}
if (!defined('BH_CE_BUILDER_TABLE')) {
    define('BH_CE_BUILDER_TABLE', 'bot_custom_event_builders');
}

if (!function_exists('bh_ce_get_pdo')) {
    function bh_ce_get_pdo(): PDO
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

        $db   = $cfg['db'];
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
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return $pdo;
    }
}

if (!function_exists('bh_ce_table_exists')) {
    function bh_ce_table_exists(string $tableName): bool
    {
        static $cache = [];

        if (isset($cache[$tableName])) {
            return $cache[$tableName];
        }

        $pdo  = bh_ce_get_pdo();
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :n LIMIT 1');
        $stmt->execute([':n' => $tableName]);

        $cache[$tableName] = (bool)$stmt->fetchColumn();
        return $cache[$tableName];
    }
}

if (!function_exists('bh_ce_ensure_tables')) {
    function bh_ce_ensure_tables(): void
    {
        $pdo = bh_ce_get_pdo();

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `bot_custom_events` (
                `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `bot_id`              BIGINT UNSIGNED NOT NULL,
                `name`                VARCHAR(120)    NOT NULL DEFAULT '',
                `event_type`          VARCHAR(80)     NOT NULL DEFAULT '',
                `description`         VARCHAR(255)    NOT NULL DEFAULT '',
                `is_enabled`          TINYINT(1)      NOT NULL DEFAULT 1,
                `group_name`          VARCHAR(80)     NULL     DEFAULT NULL,
                `created_by_user_id`  BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `updated_by_user_id`  BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_ce_bot`        (`bot_id`),
                KEY `idx_ce_bot_group`  (`bot_id`, `group_name`),
                KEY `idx_ce_bot_enabled`(`bot_id`, `is_enabled`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `bot_custom_event_builders` (
                `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `custom_event_id`  BIGINT UNSIGNED NOT NULL,
                `builder_json`     LONGTEXT        NOT NULL,
                `builder_version`  INT UNSIGNED    NOT NULL DEFAULT 1,
                `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_ceb_event` (`custom_event_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Backfill group_name for installations that had bot_custom_events before
        // this column was added (CREATE TABLE IF NOT EXISTS won't add missing columns).
        $col = $pdo->prepare(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'bot_custom_events'
               AND COLUMN_NAME  = 'group_name'
             LIMIT 1"
        );
        $col->execute();
        if (!$col->fetchColumn()) {
            $pdo->exec("ALTER TABLE `bot_custom_events`
                ADD COLUMN `group_name` VARCHAR(80) NULL DEFAULT NULL AFTER `is_enabled`");
        }
    }
}

if (!function_exists('bh_ce_events_table_ready')) {
    function bh_ce_events_table_ready(): bool
    {
        return bh_ce_table_exists(BH_CE_EVENT_TABLE);
    }
}

if (!function_exists('bh_ce_builder_table_ready')) {
    function bh_ce_builder_table_ready(): bool
    {
        return bh_ce_table_exists(BH_CE_BUILDER_TABLE);
    }
}

if (!function_exists('bh_ce_user_owns_bot')) {
    function bh_ce_user_owns_bot(int $userId, int $botId): bool
    {
        if ($userId <= 0 || $botId <= 0) {
            return false;
        }

        if (!bh_ce_table_exists(BH_CE_BOT_TABLE)) {
            return false;
        }

        $pdo  = bh_ce_get_pdo();
        $stmt = $pdo->prepare('SELECT id FROM ' . BH_CE_BOT_TABLE . ' WHERE id = :bot_id AND owner_user_id = :user_id LIMIT 1');
        $stmt->execute([':bot_id' => $botId, ':user_id' => $userId]);

        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('bh_ce_normalize_name')) {
    function bh_ce_normalize_name(string $value): string
    {
        return mb_substr(trim($value), 0, 120, 'UTF-8');
    }
}

if (!function_exists('bh_ce_validate_event_type')) {
    /** Returns true if the event type slug is valid (non-empty, only safe chars). */
    function bh_ce_validate_event_type(string $eventType): bool
    {
        return (bool)preg_match('/^[a-z0-9_\.]{2,80}$/', $eventType);
    }
}

if (!function_exists('bh_ce_list_custom_events')) {
    function bh_ce_list_custom_events(int $userId, int $botId): array
    {
        if ($userId <= 0 || $botId <= 0) {
            return [];
        }

        if (!bh_ce_table_exists(BH_CE_EVENT_TABLE)) {
            return [];
        }

        if (!bh_ce_user_owns_bot($userId, $botId)) {
            return [];
        }

        $pdo = bh_ce_get_pdo();

        try {
            $stmt = $pdo->prepare('
                SELECT id, bot_id, name, event_type, description, is_enabled, group_name, created_at, updated_at
                FROM ' . BH_CE_EVENT_TABLE . '
                WHERE bot_id = :bot_id
                ORDER BY group_name ASC, name ASC, id ASC
            ');
            $stmt->execute([':bot_id' => $botId]);
        } catch (PDOException $e) {
            // Fallback without group_name
            $stmt = $pdo->prepare('
                SELECT id, bot_id, name, event_type, description, is_enabled, created_at, updated_at
                FROM ' . BH_CE_EVENT_TABLE . '
                WHERE bot_id = :bot_id
                ORDER BY name ASC, id ASC
            ');
            $stmt->execute([':bot_id' => $botId]);
        }

        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('bh_ce_get_custom_event')) {
    function bh_ce_get_custom_event(int $userId, int $eventId): array|false
    {
        if ($userId <= 0 || $eventId <= 0) {
            return false;
        }

        if (!bh_ce_table_exists(BH_CE_EVENT_TABLE)) {
            return false;
        }

        $pdo  = bh_ce_get_pdo();
        $stmt = $pdo->prepare('
            SELECT e.*, b.owner_user_id
            FROM ' . BH_CE_EVENT_TABLE . ' e
            JOIN ' . BH_CE_BOT_TABLE . ' b ON b.id = e.bot_id
            WHERE e.id = :event_id
            LIMIT 1
        ');
        $stmt->execute([':event_id' => $eventId]);
        $row = $stmt->fetch();

        if (!$row) {
            return false;
        }

        if ((int)($row['owner_user_id'] ?? 0) !== $userId) {
            return false;
        }

        return $row;
    }
}

if (!function_exists('bh_ce_create_custom_event')) {
    function bh_ce_create_custom_event(int $userId, int $botId, string $name, string $eventType, string $description): array
    {
        if (!bh_ce_user_owns_bot($userId, $botId)) {
            return ['ok' => false, 'error' => 'Zugriff verweigert.'];
        }

        $name        = bh_ce_normalize_name($name);
        $description = mb_substr(trim($description), 0, 255, 'UTF-8');

        if ($name === '') {
            return ['ok' => false, 'error' => 'Name darf nicht leer sein.'];
        }

        if (!bh_ce_validate_event_type($eventType)) {
            return ['ok' => false, 'error' => 'Ungültiger Event-Typ.'];
        }

        if (!bh_ce_events_table_ready()) {
            bh_ce_ensure_tables();
        }

        $pdo  = bh_ce_get_pdo();
        $stmt = $pdo->prepare('
            INSERT INTO ' . BH_CE_EVENT_TABLE . '
                (bot_id, name, event_type, description, is_enabled, created_by_user_id, updated_by_user_id)
            VALUES
                (:bot_id, :name, :event_type, :description, 1, :user_id, :user_id2)
        ');
        $stmt->execute([
            ':bot_id'     => $botId,
            ':name'       => $name,
            ':event_type' => $eventType,
            ':description'=> $description,
            ':user_id'    => $userId,
            ':user_id2'   => $userId,
        ]);

        return ['ok' => true, 'id' => (int)$pdo->lastInsertId()];
    }
}

if (!function_exists('bh_ce_update_custom_event_meta')) {
    function bh_ce_update_custom_event_meta(int $userId, int $eventId, string $name, string $description): array
    {
        $event = bh_ce_get_custom_event($userId, $eventId);
        if (!$event) {
            return ['ok' => false, 'error' => 'Event nicht gefunden oder Zugriff verweigert.'];
        }

        $name        = bh_ce_normalize_name($name);
        $description = mb_substr(trim($description), 0, 255, 'UTF-8');

        if ($name === '') {
            return ['ok' => false, 'error' => 'Name darf nicht leer sein.'];
        }

        $pdo  = bh_ce_get_pdo();
        $stmt = $pdo->prepare('
            UPDATE ' . BH_CE_EVENT_TABLE . '
            SET name = :name, description = :description, updated_by_user_id = :user_id
            WHERE id = :event_id
        ');
        $stmt->execute([
            ':name'        => $name,
            ':description' => $description,
            ':user_id'     => $userId,
            ':event_id'    => $eventId,
        ]);

        return ['ok' => true];
    }
}

if (!function_exists('bh_ce_delete_custom_event')) {
    function bh_ce_delete_custom_event(int $userId, int $eventId): array
    {
        $event = bh_ce_get_custom_event($userId, $eventId);
        if (!$event) {
            return ['ok' => false, 'error' => 'Event nicht gefunden oder Zugriff verweigert.'];
        }

        $pdo = bh_ce_get_pdo();

        if (bh_ce_table_exists(BH_CE_BUILDER_TABLE)) {
            $pdo->prepare('DELETE FROM ' . BH_CE_BUILDER_TABLE . ' WHERE custom_event_id = :id')
                ->execute([':id' => $eventId]);
        }

        $pdo->prepare('DELETE FROM ' . BH_CE_EVENT_TABLE . ' WHERE id = :id')
            ->execute([':id' => $eventId]);

        return ['ok' => true];
    }
}

if (!function_exists('bh_ce_toggle_event_enabled')) {
    function bh_ce_toggle_event_enabled(int $userId, int $eventId, bool $enabled): array
    {
        $event = bh_ce_get_custom_event($userId, $eventId);
        if (!$event) {
            return ['ok' => false, 'error' => 'Event nicht gefunden oder Zugriff verweigert.'];
        }

        $pdo  = bh_ce_get_pdo();
        $stmt = $pdo->prepare('UPDATE ' . BH_CE_EVENT_TABLE . ' SET is_enabled = :e WHERE id = :id');
        $stmt->execute([':e' => $enabled ? 1 : 0, ':id' => $eventId]);

        return ['ok' => true, 'bot_id' => (int)$event['bot_id']];
    }
}

if (!function_exists('bh_ce_set_event_group')) {
    function bh_ce_set_event_group(int $userId, int $eventId, string $groupName): array
    {
        $event = bh_ce_get_custom_event($userId, $eventId);
        if (!$event) {
            return ['ok' => false, 'error' => 'Event nicht gefunden oder Zugriff verweigert.'];
        }

        $groupName = mb_substr(trim($groupName), 0, 80, 'UTF-8');

        $pdo  = bh_ce_get_pdo();
        $stmt = $pdo->prepare('UPDATE ' . BH_CE_EVENT_TABLE . ' SET group_name = :g WHERE id = :id');
        $stmt->execute([':g' => $groupName !== '' ? $groupName : null, ':id' => $eventId]);

        return ['ok' => true];
    }
}

if (!function_exists('bh_ce_builder_get')) {
    function bh_ce_builder_get(int $userId, int $eventId): array|false
    {
        $event = bh_ce_get_custom_event($userId, $eventId);
        if (!$event) {
            return false;
        }

        $builder = null;

        if (bh_ce_table_exists(BH_CE_BUILDER_TABLE)) {
            $pdo  = bh_ce_get_pdo();
            $stmt = $pdo->prepare('SELECT * FROM ' . BH_CE_BUILDER_TABLE . ' WHERE custom_event_id = :id LIMIT 1');
            $stmt->execute([':id' => $eventId]);
            $row = $stmt->fetch();

            if ($row) {
                $decoded = json_decode((string)$row['builder_json'], true);
                $builder = is_array($decoded) ? $decoded : null;
            }
        }

        return [
            'event'   => $event,
            'builder' => $builder,
        ];
    }
}

if (!function_exists('bh_ce_builder_save')) {
    function bh_ce_builder_save(int $userId, int $eventId, string $builderJson): array
    {
        $event = bh_ce_get_custom_event($userId, $eventId);
        if (!$event) {
            return ['ok' => false, 'error' => 'Event nicht gefunden oder Zugriff verweigert.'];
        }

        if ($builderJson !== '' && json_decode($builderJson) === null) {
            return ['ok' => false, 'error' => 'Ungültiges Builder-JSON.'];
        }

        if (!bh_ce_builder_table_ready()) {
            bh_ce_ensure_tables();
        }

        $pdo  = bh_ce_get_pdo();
        $stmt = $pdo->prepare('
            INSERT INTO ' . BH_CE_BUILDER_TABLE . ' (custom_event_id, builder_json, builder_version)
            VALUES (:event_id, :json, 1)
            ON DUPLICATE KEY UPDATE
                builder_json    = VALUES(builder_json),
                builder_version = builder_version + 1,
                updated_at      = NOW()
        ');
        $stmt->execute([':event_id' => $eventId, ':json' => $builderJson]);

        return ['ok' => true];
    }
}
