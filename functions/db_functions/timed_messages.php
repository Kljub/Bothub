<?php
declare(strict_types=1);

function bhtm_ensure_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_timed_message_settings (
          id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          bot_id      BIGINT UNSIGNED NOT NULL,
          evt_handler TINYINT(1) NOT NULL DEFAULT 1,
          PRIMARY KEY (id),
          UNIQUE KEY uq_timed_settings_bot (bot_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_timed_messages (
          id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          bot_id           BIGINT UNSIGNED NOT NULL,
          name             VARCHAR(100) NOT NULL,
          channel_id       VARCHAR(32)  NOT NULL DEFAULT '',
          interval_days    INT          NOT NULL DEFAULT 0,
          interval_hours   INT          NOT NULL DEFAULT 1,
          interval_minutes INT          NOT NULL DEFAULT 0,
          is_embed         TINYINT(1)   NOT NULL DEFAULT 1,
          plain_text       TEXT         NULL,
          embed_author     VARCHAR(256) NOT NULL DEFAULT '',
          embed_thumbnail  VARCHAR(512) NOT NULL DEFAULT '',
          embed_title      VARCHAR(256) NOT NULL DEFAULT '',
          embed_body       TEXT         NULL,
          embed_image      VARCHAR(512) NOT NULL DEFAULT '',
          embed_color      VARCHAR(16)  NOT NULL DEFAULT '#ef4444',
          embed_url        VARCHAR(512) NOT NULL DEFAULT '',
          block_stacked    TINYINT(1)   NOT NULL DEFAULT 0,
          is_active        TINYINT(1)   NOT NULL DEFAULT 1,
          last_sent_at     DATETIME     NULL,
          next_send_at     DATETIME     NULL,
          last_message_id  VARCHAR(32)  NOT NULL DEFAULT '',
          created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          INDEX idx_timed_bot  (bot_id),
          INDEX idx_timed_next (next_send_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function bhtm_save_settings(PDO $pdo, int $botId, int $evtHandler): void
{
    $pdo->prepare("
        INSERT INTO bot_timed_message_settings (bot_id, evt_handler)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE evt_handler = VALUES(evt_handler)
    ")->execute([$botId, $evtHandler]);
}

function bhtm_load_settings(PDO $pdo, int $botId): array
{
    $stmt = $pdo->prepare('SELECT * FROM bot_timed_message_settings WHERE bot_id = ? LIMIT 1');
    $stmt->execute([$botId]);
    return $stmt->fetch() ?: [];
}

function bhtm_add(PDO $pdo, int $botId, array $p): int
{
    $pdo->prepare("
        INSERT INTO bot_timed_messages
          (bot_id, name, channel_id, interval_days, interval_hours, interval_minutes,
           is_embed, plain_text, embed_author, embed_thumbnail, embed_title,
           embed_body, embed_image, embed_color, embed_url, block_stacked, next_send_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $botId,
        $p['name'],       $p['channel_id'],
        $p['days'],       $p['hours'],     $p['minutes'],
        $p['is_embed'],   $p['plain_text'] ?: null,
        $p['author'],     $p['thumb'],     $p['title'],
        $p['body'] ?: null, $p['image'],   $p['color'],
        $p['embed_url'],  $p['block_stacked'], $p['next_send_at'],
    ]);
    return (int)$pdo->lastInsertId();
}

function bhtm_delete(PDO $pdo, int $botId, int $id): void
{
    $pdo->prepare('DELETE FROM bot_timed_messages WHERE id = ? AND bot_id = ?')->execute([$id, $botId]);
}

function bhtm_toggle(PDO $pdo, int $botId, int $id, int $isActive): void
{
    $pdo->prepare('UPDATE bot_timed_messages SET is_active = ? WHERE id = ? AND bot_id = ?')
        ->execute([$isActive, $id, $botId]);
}

function bhtm_list(PDO $pdo, int $botId): array
{
    $stmt = $pdo->prepare('SELECT * FROM bot_timed_messages WHERE bot_id = ? ORDER BY created_at DESC');
    $stmt->execute([$botId]);
    return $stmt->fetchAll() ?: [];
}
