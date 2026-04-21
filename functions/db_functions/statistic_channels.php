<?php
declare(strict_types=1);

function bhsc_ensure_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_statistic_channels (
          id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          bot_id       BIGINT UNSIGNED NOT NULL,
          guild_id     VARCHAR(32)  NOT NULL DEFAULT '',
          channel_id   VARCHAR(32)  NOT NULL DEFAULT '',
          channel_name VARCHAR(100) NOT NULL DEFAULT 'Members: {value}',
          stat_type    VARCHAR(50)  NOT NULL DEFAULT 'total_members',
          auto_lock    TINYINT(1)   NOT NULL DEFAULT 1,
          is_active    TINYINT(1)   NOT NULL DEFAULT 1,
          cached_value   VARCHAR(50)  NOT NULL DEFAULT '',
          updated_at   DATETIME     NULL,
          created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          INDEX idx_stat_bot     (bot_id),
          INDEX idx_stat_channel (channel_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function bhsc_add(PDO $pdo, int $botId, string $guildId, string $channelId, string $channelName, string $statType, int $autoLock): int
{
    $pdo->prepare("
        INSERT INTO bot_statistic_channels
          (bot_id, guild_id, channel_id, channel_name, stat_type, auto_lock)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$botId, $guildId, $channelId, $channelName, $statType, $autoLock]);
    return (int)$pdo->lastInsertId();
}

function bhsc_delete(PDO $pdo, int $botId, int $id): void
{
    $pdo->prepare('DELETE FROM bot_statistic_channels WHERE id = ? AND bot_id = ?')
        ->execute([$id, $botId]);
}

function bhsc_toggle(PDO $pdo, int $botId, int $id, int $isActive): void
{
    $pdo->prepare('UPDATE bot_statistic_channels SET is_active = ? WHERE id = ? AND bot_id = ?')
        ->execute([$isActive, $id, $botId]);
}

function bhsc_list(PDO $pdo, int $botId): array
{
    $stmt = $pdo->prepare('SELECT * FROM bot_statistic_channels WHERE bot_id = ? ORDER BY created_at DESC');
    $stmt->execute([$botId]);
    return $stmt->fetchAll() ?: [];
}
