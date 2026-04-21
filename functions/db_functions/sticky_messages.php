<?php
declare(strict_types=1);

function bhsm_ensure_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_sticky_settings (
          id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          bot_id          BIGINT UNSIGNED NOT NULL,
          manager_role_id VARCHAR(32)  NOT NULL DEFAULT '',
          is_embed        TINYINT(1)   NOT NULL DEFAULT 1,
          plain_text      TEXT         NULL,
          embed_author    VARCHAR(256) NOT NULL DEFAULT '',
          embed_thumbnail VARCHAR(512) NOT NULL DEFAULT '',
          embed_title     VARCHAR(256) NOT NULL DEFAULT 'Sticky Messages',
          embed_body      TEXT         NULL,
          embed_image     VARCHAR(512) NOT NULL DEFAULT '',
          embed_color     VARCHAR(16)  NOT NULL DEFAULT '#f48342',
          embed_url       VARCHAR(512) NOT NULL DEFAULT '',
          embed_footer    VARCHAR(512) NOT NULL DEFAULT 'Sticky messages module',
          repost_count    INT          NOT NULL DEFAULT 10,
          show_author     TINYINT(1)   NOT NULL DEFAULT 1,
          add_reaction    TINYINT(1)   NOT NULL DEFAULT 1,
          reaction_emoji  VARCHAR(128) NOT NULL DEFAULT '👍',
          evt_handler     TINYINT(1)   NOT NULL DEFAULT 1,
          PRIMARY KEY (id),
          UNIQUE KEY uq_sticky_settings_bot (bot_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_sticky_channels (
          id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          bot_id          BIGINT UNSIGNED NOT NULL,
          channel_id      VARCHAR(32)  NOT NULL,
          last_message_id VARCHAR(32)  NOT NULL DEFAULT '',
          message_count   INT          NOT NULL DEFAULT 0,
          posted_by       VARCHAR(32)  NOT NULL DEFAULT '',
          is_embed        TINYINT(1)   NOT NULL DEFAULT 1,
          plain_text      TEXT         NULL,
          embed_author    VARCHAR(256) NOT NULL DEFAULT '',
          embed_thumbnail VARCHAR(512) NOT NULL DEFAULT '',
          embed_title     VARCHAR(256) NOT NULL DEFAULT '',
          embed_body      TEXT         NULL,
          embed_image     VARCHAR(512) NOT NULL DEFAULT '',
          embed_color     VARCHAR(16)  NOT NULL DEFAULT '#f48342',
          embed_url       VARCHAR(512) NOT NULL DEFAULT '',
          embed_footer    VARCHAR(512) NOT NULL DEFAULT '',
          created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_sticky_channel (bot_id, channel_id),
          INDEX idx_sticky_bot (bot_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function bhsm_save(PDO $pdo, int $botId, array $p): void
{
    $pdo->prepare("
        INSERT INTO bot_sticky_settings
          (bot_id, manager_role_id, is_embed, plain_text, embed_author, embed_thumbnail,
           embed_title, embed_body, embed_image, embed_color, embed_url, embed_footer,
           repost_count, show_author, add_reaction, reaction_emoji, evt_handler)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          manager_role_id = VALUES(manager_role_id),
          is_embed        = VALUES(is_embed),
          plain_text      = VALUES(plain_text),
          embed_author    = VALUES(embed_author),
          embed_thumbnail = VALUES(embed_thumbnail),
          embed_title     = VALUES(embed_title),
          embed_body      = VALUES(embed_body),
          embed_image     = VALUES(embed_image),
          embed_color     = VALUES(embed_color),
          embed_url       = VALUES(embed_url),
          embed_footer    = VALUES(embed_footer),
          repost_count    = VALUES(repost_count),
          show_author     = VALUES(show_author),
          add_reaction    = VALUES(add_reaction),
          reaction_emoji  = VALUES(reaction_emoji),
          evt_handler     = VALUES(evt_handler)
    ")->execute([
        $botId, $p['manager_role'], $p['is_embed'], $p['plain_text'] ?: null,
        $p['author'], $p['thumb'], $p['title'], $p['body'] ?: null,
        $p['image'], $p['color'], $p['embed_url'], $p['footer'],
        $p['repost_count'], $p['show_author'], $p['add_reaction'],
        $p['reaction_emoji'], $p['evt_handler'],
    ]);
}

function bhsm_load(PDO $pdo, int $botId): array
{
    $stmt = $pdo->prepare('SELECT * FROM bot_sticky_settings WHERE bot_id = ? LIMIT 1');
    $stmt->execute([$botId]);
    return $stmt->fetch() ?: [];
}
