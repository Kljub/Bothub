<?php
declare(strict_types=1);

require_once __DIR__ . '/html.php';

if (!defined('BH_TE_EVENT_TABLE')) {
    define('BH_TE_EVENT_TABLE', 'bot_timed_events');
}
if (!defined('BH_TE_BUILDER_TABLE')) {
    define('BH_TE_BUILDER_TABLE', 'bot_timed_event_builders');
}

if (!function_exists('bh_te_get_pdo')) {
    function bh_te_get_pdo(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) { return $pdo; }

        $cfgPath = dirname(__DIR__) . '/db/config/app.php';
        if (!is_file($cfgPath) || !is_readable($cfgPath)) {
            throw new RuntimeException('DB Config nicht gefunden: ' . $cfgPath);
        }
        $cfg = require $cfgPath;
        $db  = $cfg['db'];
        $dsn = 'mysql:host=' . $db['host'] . ';port=' . ($db['port'] ?? '3306') . ';dbname=' . $db['name'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pdo;
    }
}

if (!function_exists('bh_te_ensure_tables')) {
    function bh_te_ensure_tables(): void
    {
        $pdo = bh_te_get_pdo();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `bot_timed_events` (
                `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `bot_id`             BIGINT UNSIGNED NOT NULL,
                `name`               VARCHAR(120)    NOT NULL DEFAULT '',
                `description`        VARCHAR(255)    NOT NULL DEFAULT '',
                `event_type`         ENUM('interval','schedule') NOT NULL DEFAULT 'interval',
                `interval_seconds`   INT UNSIGNED    NOT NULL DEFAULT 0,
                `interval_minutes`   INT UNSIGNED    NOT NULL DEFAULT 0,
                `interval_hours`     INT UNSIGNED    NOT NULL DEFAULT 0,
                `interval_days`      INT UNSIGNED    NOT NULL DEFAULT 0,
                `week_days`          VARCHAR(64)     NOT NULL DEFAULT 'Mon,Tue,Wed,Thu,Fri,Sat,Sun',
                `schedule_time`      VARCHAR(5)      NOT NULL DEFAULT '00:00',
                `schedule_days`      VARCHAR(64)     NOT NULL DEFAULT 'Mon,Tue,Wed,Thu,Fri,Sat,Sun',
                `group_name`         VARCHAR(80)     NULL DEFAULT NULL,
                `is_enabled`         TINYINT(1)      NOT NULL DEFAULT 1,
                `created_by_user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `created_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_te_bot`     (`bot_id`),
                KEY `idx_te_enabled` (`bot_id`, `is_enabled`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // Add group_name column if it doesn't exist (for existing tables)
        try {
            $pdo->exec("ALTER TABLE `bot_timed_events` ADD COLUMN `group_name` VARCHAR(80) NULL DEFAULT NULL AFTER `schedule_days`");
        } catch (Throwable) {
            // Column already exists — ignore
        }
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `bot_timed_event_builders` (
                `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `event_id`     BIGINT UNSIGNED NOT NULL,
                `builder_json` MEDIUMTEXT      NOT NULL,
                `updated_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_te_builder_event` (`event_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

if (!function_exists('bh_te_list')) {
    function bh_te_list(int $userId, int $botId): array
    {
        bh_te_ensure_tables();
        $pdo  = bh_te_get_pdo();
        $stmt = $pdo->prepare('SELECT * FROM `bot_timed_events` WHERE bot_id = :b AND created_by_user_id = :u ORDER BY created_at DESC');
        $stmt->execute([':b' => $botId, ':u' => $userId]);
        return $stmt->fetchAll() ?: [];
    }
}

if (!function_exists('bh_te_get')) {
    function bh_te_get(int $userId, int $eventId): ?array
    {
        bh_te_ensure_tables();
        $pdo  = bh_te_get_pdo();
        $stmt = $pdo->prepare('SELECT * FROM `bot_timed_events` WHERE id = :id AND created_by_user_id = :u LIMIT 1');
        $stmt->execute([':id' => $eventId, ':u' => $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('bh_te_create')) {
    function bh_te_create(int $userId, int $botId, string $name, string $description): array
    {
        bh_te_ensure_tables();
        if ($name === '') { return ['ok' => false, 'error' => 'Name darf nicht leer sein.']; }
        $pdo  = bh_te_get_pdo();
        $stmt = $pdo->prepare('INSERT INTO `bot_timed_events` (bot_id, name, description, created_by_user_id) VALUES (:b,:n,:d,:u)');
        $stmt->execute([':b' => $botId, ':n' => $name, ':d' => $description, ':u' => $userId]);
        return ['ok' => true, 'id' => (int)$pdo->lastInsertId()];
    }
}

if (!function_exists('bh_te_update_meta')) {
    function bh_te_update_meta(int $userId, int $eventId, string $name, string $description): array
    {
        $pdo  = bh_te_get_pdo();
        $stmt = $pdo->prepare('UPDATE `bot_timed_events` SET name=:n, description=:d WHERE id=:id AND created_by_user_id=:u');
        $stmt->execute([':n' => $name, ':d' => $description, ':id' => $eventId, ':u' => $userId]);
        return ['ok' => true];
    }
}

if (!function_exists('bh_te_toggle_enabled')) {
    function bh_te_toggle_enabled(int $userId, int $eventId, bool $enabled): array
    {
        $pdo  = bh_te_get_pdo();
        $stmt = $pdo->prepare('SELECT bot_id FROM `bot_timed_events` WHERE id=:id AND created_by_user_id=:u LIMIT 1');
        $stmt->execute([':id' => $eventId, ':u' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) { return ['ok' => false, 'error' => 'Event nicht gefunden.']; }

        $pdo->prepare('UPDATE `bot_timed_events` SET is_enabled=:e WHERE id=:id AND created_by_user_id=:u')
            ->execute([':e' => (int)$enabled, ':id' => $eventId, ':u' => $userId]);
        return ['ok' => true, 'bot_id' => (int)$row['bot_id']];
    }
}

if (!function_exists('bh_te_set_group')) {
    function bh_te_set_group(int $userId, int $eventId, string $groupName): array
    {
        $groupName = trim($groupName);
        if (strlen($groupName) > 80) { return ['ok' => false, 'error' => 'Gruppenname zu lang.']; }

        $pdo  = bh_te_get_pdo();
        $stmt = $pdo->prepare('UPDATE `bot_timed_events` SET group_name=:g WHERE id=:id AND created_by_user_id=:u');
        $stmt->execute([':g' => ($groupName !== '' ? $groupName : null), ':id' => $eventId, ':u' => $userId]);
        return ['ok' => true];
    }
}

if (!function_exists('bh_te_delete')) {
    function bh_te_delete(int $userId, int $eventId): array
    {
        $pdo = bh_te_get_pdo();
        $pdo->prepare('DELETE FROM `bot_timed_event_builders` WHERE event_id=:id')->execute([':id' => $eventId]);
        $pdo->prepare('DELETE FROM `bot_timed_events` WHERE id=:id AND created_by_user_id=:u')->execute([':id' => $eventId, ':u' => $userId]);
        return ['ok' => true];
    }
}

if (!function_exists('bh_te_builder_save')) {
    function bh_te_builder_save(int $userId, int $eventId, string $builderJson): array
    {
        // Verify ownership
        $event = bh_te_get($userId, $eventId);
        if ($event === null) { return ['ok' => false, 'error' => 'Event nicht gefunden.']; }

        $pdo  = bh_te_get_pdo();
        $stmt = $pdo->prepare('INSERT INTO `bot_timed_event_builders` (event_id, builder_json) VALUES (:id,:j) ON DUPLICATE KEY UPDATE builder_json=:j2, updated_at=NOW()');
        $stmt->execute([':id' => $eventId, ':j' => $builderJson, ':j2' => $builderJson]);
        return ['ok' => true];
    }
}

if (!function_exists('bh_te_builder_get')) {
    function bh_te_builder_get(int $userId, int $eventId): array
    {
        $event = bh_te_get($userId, $eventId);
        if ($event === null) { return ['event' => null, 'builder' => null]; }

        $pdo  = bh_te_get_pdo();
        $stmt = $pdo->prepare('SELECT builder_json FROM `bot_timed_event_builders` WHERE event_id=:id LIMIT 1');
        $stmt->execute([':id' => $eventId]);
        $row  = $stmt->fetch();

        $builder = null;
        if (is_array($row) && isset($row['builder_json'])) {
            $decoded = json_decode((string)$row['builder_json'], true);
            if (is_array($decoded)) { $builder = $decoded; }
        }

        return ['event' => $event, 'builder' => $builder];
    }
}

if (!function_exists('bh_te_validate_event_type')) {
    function bh_te_validate_event_type(string $type): bool
    {
        return in_array($type, ['interval', 'schedule'], true);
    }
}
