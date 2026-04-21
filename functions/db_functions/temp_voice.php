<?php
declare(strict_types=1);

function bh_tv_get_pdo(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    require_once dirname(__DIR__) . '/../auth/_db.php';
    $pdo = bh_pdo();
    return $pdo;
}

function bh_tv_ensure_tables(): void
{
    $pdo = bh_tv_get_pdo();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `bot_temp_voice_settings` (
            `id`                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `bot_id`             INT UNSIGNED NOT NULL,
            `guild_id`           VARCHAR(20)  NOT NULL DEFAULT '',
            `trigger_channel_id` VARCHAR(20)  NOT NULL DEFAULT '',
            `category_id`        VARCHAR(20)  NOT NULL DEFAULT '',
            `channel_name`       VARCHAR(100) NOT NULL DEFAULT 'Temp #{n}',
            `user_limit`         TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `bitrate`            INT UNSIGNED NOT NULL DEFAULT 64000,
            `is_enabled`         TINYINT(1)   NOT NULL DEFAULT 1,
            `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_bot_guild` (`bot_id`, `guild_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `bot_temp_voice_channels` (
            `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `bot_id`       INT UNSIGNED NOT NULL,
            `guild_id`     VARCHAR(20)  NOT NULL,
            `channel_id`   VARCHAR(20)  NOT NULL,
            `owner_id`     VARCHAR(20)  NOT NULL DEFAULT '',
            `channel_num`  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_channel` (`bot_id`, `guild_id`, `channel_id`),
            KEY `idx_bot_guild` (`bot_id`, `guild_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function bh_tv_get_settings(int $botId, string $guildId): ?array
{
    $pdo  = bh_tv_get_pdo();
    $stmt = $pdo->prepare(
        'SELECT * FROM bot_temp_voice_settings WHERE bot_id = ? AND guild_id = ? LIMIT 1'
    );
    $stmt->execute([$botId, $guildId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function bh_tv_get_settings_by_bot(int $botId): array
{
    $pdo  = bh_tv_get_pdo();
    $stmt = $pdo->prepare(
        'SELECT * FROM bot_temp_voice_settings WHERE bot_id = ? ORDER BY id ASC'
    );
    $stmt->execute([$botId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function bh_tv_save_settings(
    int $botId,
    string $guildId,
    string $triggerChannelId,
    string $categoryId,
    string $channelName,
    int $userLimit,
    int $bitrate,
    int $isEnabled
): void {
    $pdo = bh_tv_get_pdo();
    $pdo->prepare("
        INSERT INTO bot_temp_voice_settings
            (bot_id, guild_id, trigger_channel_id, category_id, channel_name, user_limit, bitrate, is_enabled)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            trigger_channel_id = VALUES(trigger_channel_id),
            category_id        = VALUES(category_id),
            channel_name       = VALUES(channel_name),
            user_limit         = VALUES(user_limit),
            bitrate            = VALUES(bitrate),
            is_enabled         = VALUES(is_enabled),
            updated_at         = NOW()
    ")->execute([$botId, $guildId, $triggerChannelId, $categoryId, $channelName, $userLimit, $bitrate, $isEnabled]);
}

function bh_tv_list_active_channels(int $botId, string $guildId): array
{
    $pdo  = bh_tv_get_pdo();
    $stmt = $pdo->prepare(
        'SELECT * FROM bot_temp_voice_channels WHERE bot_id = ? AND guild_id = ? ORDER BY channel_num ASC'
    );
    $stmt->execute([$botId, $guildId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function bh_tv_next_channel_num(int $botId, string $guildId): int
{
    $pdo  = bh_tv_get_pdo();
    // Find the lowest available number not currently in use
    $stmt = $pdo->prepare(
        'SELECT channel_num FROM bot_temp_voice_channels WHERE bot_id = ? AND guild_id = ? ORDER BY channel_num ASC'
    );
    $stmt->execute([$botId, $guildId]);
    $used = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'channel_num');
    for ($i = 1; $i <= 999; $i++) {
        if (!in_array($i, $used, true)) return $i;
    }
    return count($used) + 1;
}
