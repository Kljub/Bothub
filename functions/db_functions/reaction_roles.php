<?php
declare(strict_types=1);

function bhrr_ensure_table(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS bot_reaction_roles (
          id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          bot_id            BIGINT UNSIGNED NOT NULL,
          message_id        VARCHAR(32)     NOT NULL,
          channel_id        VARCHAR(32)     NOT NULL DEFAULT \'\',
          emoji             VARCHAR(128)    NOT NULL DEFAULT \'\',
          roles_to_add      JSON            NULL,
          roles_to_remove   JSON            NULL,
          blacklisted_roles JSON            NULL,
          restrict_one      TINYINT(1)      NOT NULL DEFAULT 0,
          remove_reaction   TINYINT(1)      NOT NULL DEFAULT 1,
          created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          INDEX idx_rr_bot     (bot_id),
          INDEX idx_rr_message (message_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');
}

function bhrr_add(PDO $pdo, int $botId, string $messageId, string $channelId, string $emoji, string $rolesToAdd, string $rolesToRemove, string $blacklisted, int $restrictOne, int $removeReaction): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO bot_reaction_roles
         (bot_id, message_id, channel_id, emoji, roles_to_add, roles_to_remove, blacklisted_roles, restrict_one, remove_reaction)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$botId, $messageId, $channelId, $emoji, $rolesToAdd, $rolesToRemove, $blacklisted, $restrictOne, $removeReaction]);
    return (int)$pdo->lastInsertId();
}

function bhrr_delete(PDO $pdo, int $botId, int $id): void
{
    $pdo->prepare('DELETE FROM bot_reaction_roles WHERE id = ? AND bot_id = ?')->execute([$id, $botId]);
}

function bhrr_list(PDO $pdo, int $botId): array
{
    $stmt = $pdo->prepare('SELECT * FROM bot_reaction_roles WHERE bot_id = ? ORDER BY id ASC');
    $stmt->execute([$botId]);
    return $stmt->fetchAll() ?: [];
}
