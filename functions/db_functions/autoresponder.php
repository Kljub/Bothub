<?php
declare(strict_types=1);

function bhar_ensure_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_autoresponders (
          id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          bot_id              BIGINT UNSIGNED NOT NULL,
          trigger_type        ENUM('contains','starts_with','exact') NOT NULL DEFAULT 'contains',
          keywords            JSON NULL,
          is_embed            TINYINT(1)   NOT NULL DEFAULT 1,
          plain_text          TEXT         NULL,
          embed_author        VARCHAR(256) NOT NULL DEFAULT '',
          embed_thumbnail     VARCHAR(512) NOT NULL DEFAULT '',
          embed_title         VARCHAR(256) NOT NULL DEFAULT '',
          embed_body          TEXT         NULL,
          embed_image         VARCHAR(512) NOT NULL DEFAULT '',
          embed_color         VARCHAR(16)  NOT NULL DEFAULT '#ef4444',
          embed_url           VARCHAR(512) NOT NULL DEFAULT '',
          channel_cooldown    INT          NOT NULL DEFAULT 10,
          mention_user        TINYINT(1)   NOT NULL DEFAULT 1,
          channel_filter_type ENUM('all_except','selected') NOT NULL DEFAULT 'all_except',
          filtered_channels   JSON NULL,
          role_filter_type    ENUM('all_except','selected') NOT NULL DEFAULT 'all_except',
          filtered_roles      JSON NULL,
          is_active           TINYINT(1)   NOT NULL DEFAULT 1,
          created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          INDEX idx_ar_bot (bot_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_autoresponder_cooldowns (
          id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          ar_id        BIGINT UNSIGNED NOT NULL,
          channel_id   VARCHAR(32) NOT NULL,
          last_sent_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_ar_cooldown (ar_id, channel_id),
          INDEX idx_ar_cd (ar_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function bhar_add(PDO $pdo, int $botId, array $p): int
{
    $pdo->prepare("
        INSERT INTO bot_autoresponders
          (bot_id, trigger_type, keywords, is_embed, plain_text,
           embed_author, embed_thumbnail, embed_title, embed_body,
           embed_image, embed_color, embed_url,
           channel_cooldown, mention_user,
           channel_filter_type, filtered_channels,
           role_filter_type, filtered_roles)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $botId,
        $p['trigger_type'], $p['keywords'],
        $p['is_embed'],     $p['plain_text'] ?: null,
        $p['author'],       $p['thumb'],     $p['title'],
        $p['body'] ?: null, $p['image'],     $p['color'], $p['embed_url'],
        $p['cooldown'],     $p['mention'],
        $p['ch_filter_type'], $p['filtered_channels'],
        $p['role_filter_type'], $p['filtered_roles'],
    ]);
    return (int)$pdo->lastInsertId();
}

function bhar_delete(PDO $pdo, int $botId, int $id): void
{
    $pdo->prepare('DELETE FROM bot_autoresponders WHERE id = ? AND bot_id = ?')->execute([$id, $botId]);
    $pdo->prepare('DELETE FROM bot_autoresponder_cooldowns WHERE ar_id = ?')->execute([$id]);
}

function bhar_toggle(PDO $pdo, int $botId, int $id, int $isActive): void
{
    $pdo->prepare('UPDATE bot_autoresponders SET is_active = ? WHERE id = ? AND bot_id = ?')
        ->execute([$isActive, $id, $botId]);
}

function bhar_list(PDO $pdo, int $botId): array
{
    $stmt = $pdo->prepare('SELECT * FROM bot_autoresponders WHERE bot_id = ? ORDER BY created_at DESC');
    $stmt->execute([$botId]);
    return $stmt->fetchAll() ?: [];
}
