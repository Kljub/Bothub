<?php
declare(strict_types=1);

function bh_birthday_ensure_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `bot_birthdays` (
            `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `bot_id`      BIGINT UNSIGNED  NOT NULL,
            `guild_id`    VARCHAR(32)      NOT NULL,
            `user_id`     VARCHAR(32)      NOT NULL,
            `username`    VARCHAR(100)     NOT NULL DEFAULT '',
            `birth_day`   TINYINT UNSIGNED NOT NULL,
            `birth_month` TINYINT UNSIGNED NOT NULL,
            `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_birthday_user` (`bot_id`, `guild_id`, `user_id`),
            KEY `idx_birthday_bot_guild`  (`bot_id`, `guild_id`),
            KEY `idx_birthday_month_day`  (`birth_month`, `birth_day`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `bot_birthday_settings` (
            `bot_id`           BIGINT UNSIGNED NOT NULL,
            `guild_id`         VARCHAR(32)     NOT NULL,
            `announce_channel` VARCHAR(32)     NOT NULL DEFAULT '',
            `announce_message` VARCHAR(512)    NOT NULL DEFAULT 'Alles Gute zum Geburtstag {user}! 🎂🎉',
            `is_enabled`       TINYINT(1)      NOT NULL DEFAULT 1,
            `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`bot_id`, `guild_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function bh_birthday_list(PDO $pdo, int $botId, string $guildId = ''): array
{
    if ($guildId !== '') {
        $stmt = $pdo->prepare("
            SELECT * FROM bot_birthdays
            WHERE bot_id = ? AND guild_id = ?
            ORDER BY birth_month ASC, birth_day ASC, username ASC
        ");
        $stmt->execute([$botId, $guildId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT * FROM bot_birthdays
            WHERE bot_id = ?
            ORDER BY birth_month ASC, birth_day ASC, username ASC
        ");
        $stmt->execute([$botId]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function bh_birthday_upsert(PDO $pdo, int $botId, string $guildId, string $userId, string $username, int $day, int $month): int
{
    $stmt = $pdo->prepare("
        INSERT INTO bot_birthdays (bot_id, guild_id, user_id, username, birth_day, birth_month)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            username    = VALUES(username),
            birth_day   = VALUES(birth_day),
            birth_month = VALUES(birth_month),
            updated_at  = NOW()
    ");
    $stmt->execute([$botId, $guildId, $userId, $username, $day, $month]);
    return (int)$pdo->lastInsertId();
}

function bh_birthday_delete(PDO $pdo, int $botId, int $id): void
{
    $pdo->prepare("DELETE FROM bot_birthdays WHERE id = ? AND bot_id = ?")->execute([$id, $botId]);
}

function bh_birthday_delete_by_user(PDO $pdo, int $botId, string $guildId, string $userId): void
{
    $pdo->prepare("DELETE FROM bot_birthdays WHERE bot_id = ? AND guild_id = ? AND user_id = ?")
        ->execute([$botId, $guildId, $userId]);
}

function bh_birthday_get_settings(PDO $pdo, int $botId, string $guildId): array
{
    $stmt = $pdo->prepare("SELECT * FROM bot_birthday_settings WHERE bot_id = ? AND guild_id = ? LIMIT 1");
    $stmt->execute([$botId, $guildId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function bh_birthday_save_settings(PDO $pdo, int $botId, string $guildId, string $channel, string $message, int $enabled): void
{
    $pdo->prepare("
        INSERT INTO bot_birthday_settings (bot_id, guild_id, announce_channel, announce_message, is_enabled)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            announce_channel = VALUES(announce_channel),
            announce_message = VALUES(announce_message),
            is_enabled       = VALUES(is_enabled),
            updated_at       = NOW()
    ")->execute([$botId, $guildId, $channel, $message, $enabled]);
}
