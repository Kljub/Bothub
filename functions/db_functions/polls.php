<?php
declare(strict_types=1);

function bhpo_ensure_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_polls_settings (
          id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          bot_id               BIGINT UNSIGNED NOT NULL,
          manager_roles        JSON NULL,
          whitelisted_channels JSON NULL,
          blacklisted_roles    JSON NULL,
          single_choice        TINYINT(1)   NOT NULL DEFAULT 0,
          embed_title          VARCHAR(256) NOT NULL DEFAULT '🗳️ Poll - {poll.question}',
          embed_footer         VARCHAR(512) NOT NULL DEFAULT 'Participate in the poll by reacting with one of the options specified below. We thank you for your feedback!',
          embed_color          VARCHAR(16)  NOT NULL DEFAULT '#EE3636',
          show_poster_name     TINYINT(1)   NOT NULL DEFAULT 1,
          choice_reactions     JSON NULL,
          evt_polls_handler    TINYINT(1)   NOT NULL DEFAULT 1,
          PRIMARY KEY (id),
          UNIQUE KEY uq_polls_settings_bot (bot_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_polls (
          id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          bot_id           BIGINT UNSIGNED NOT NULL,
          guild_id         VARCHAR(32) NOT NULL,
          channel_id       VARCHAR(32) NOT NULL DEFAULT '',
          message_id       VARCHAR(32) NOT NULL DEFAULT '',
          question         VARCHAR(512) NOT NULL,
          choices          JSON NULL,
          creator_user_id  VARCHAR(32) NOT NULL DEFAULT '',
          creator_username VARCHAR(100) NOT NULL DEFAULT '',
          is_active        TINYINT(1) NOT NULL DEFAULT 1,
          created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          INDEX idx_polls_bot (bot_id),
          INDEX idx_polls_message (message_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_poll_votes (
          id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          poll_id  BIGINT UNSIGNED NOT NULL,
          user_id  VARCHAR(32) NOT NULL,
          emoji    VARCHAR(128) NOT NULL,
          voted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_poll_vote (poll_id, user_id, emoji),
          INDEX idx_poll_votes (poll_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function bhpo_save_settings(PDO $pdo, int $botId, array $params): void
{
    $pdo->prepare("
        INSERT INTO bot_polls_settings
          (bot_id, manager_roles, whitelisted_channels, blacklisted_roles, single_choice,
           embed_title, embed_footer, embed_color, show_poster_name, choice_reactions,
           evt_polls_handler)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          manager_roles        = VALUES(manager_roles),
          whitelisted_channels = VALUES(whitelisted_channels),
          blacklisted_roles    = VALUES(blacklisted_roles),
          single_choice        = VALUES(single_choice),
          embed_title          = VALUES(embed_title),
          embed_footer         = VALUES(embed_footer),
          embed_color          = VALUES(embed_color),
          show_poster_name     = VALUES(show_poster_name),
          choice_reactions     = VALUES(choice_reactions),
          evt_polls_handler    = VALUES(evt_polls_handler)
    ")->execute([
        $botId,
        $params['manager_roles'],
        $params['whitelisted_channels'],
        $params['blacklisted_roles'],
        $params['single_choice'],
        $params['embed_title'],
        $params['embed_footer'],
        $params['embed_color'],
        $params['show_poster_name'],
        $params['choice_reactions'],
        $params['evt_polls_handler'],
    ]);
}

function bhpo_load_settings(PDO $pdo, int $botId): array
{
    $stmt = $pdo->prepare('SELECT * FROM bot_polls_settings WHERE bot_id = ? LIMIT 1');
    $stmt->execute([$botId]);
    return $stmt->fetch() ?: [];
}

/**
 * Ensure all poll command rows exist in the commands table (INSERT IGNORE).
 * Does not overwrite is_enabled if the row already exists.
 */
/**
 * Ensure all poll command rows exist in the commands table (INSERT IGNORE).
 * Returns the number of newly inserted rows (0 if all already existed).
 */
function bhpo_seed_commands(PDO $pdo, int $botId): int
{
    $keys = ['poll-create', 'poll-find', 'poll-list', 'poll-delete'];
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO commands (bot_id, command_key, command_type, name, description, is_enabled, created_at, updated_at)
        VALUES (?, ?, 'module', ?, NULL, 1, NOW(), NOW())
    ");
    $inserted = 0;
    foreach ($keys as $key) {
        $stmt->execute([$botId, $key, $key]);
        $inserted += $stmt->rowCount();
    }
    return $inserted;
}
