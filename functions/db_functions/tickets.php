<?php
declare(strict_types=1);

function bht_ensure_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_ticket_settings (
          id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          bot_id          BIGINT UNSIGNED NOT NULL,
          support_role_id VARCHAR(32) NOT NULL DEFAULT '',
          category_id     VARCHAR(32) NOT NULL DEFAULT '',
          log_channel_id  VARCHAR(32) NOT NULL DEFAULT '',
          open_message    TEXT NOT NULL,
          dm_message      TEXT NOT NULL,
          ticket_count    INT UNSIGNED NOT NULL DEFAULT 0,
          PRIMARY KEY (id),
          UNIQUE KEY uq_ticket_settings_bot (bot_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_tickets (
          id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          bot_id      BIGINT UNSIGNED NOT NULL,
          guild_id    VARCHAR(32) NOT NULL,
          ticket_num  INT UNSIGNED NOT NULL DEFAULT 0,
          channel_id  VARCHAR(32) NOT NULL,
          creator_id  VARCHAR(32) NOT NULL,
          claimed_by  VARCHAR(32) NOT NULL DEFAULT '',
          resolved    TINYINT(1) NOT NULL DEFAULT 0,
          created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_ticket_channel (bot_id, channel_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function bht_get_settings(PDO $pdo, int $botId): array
{
    $stmt = $pdo->prepare('SELECT * FROM bot_ticket_settings WHERE bot_id = ? LIMIT 1');
    $stmt->execute([$botId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : bht_defaults($botId);
}

function bht_defaults(int $botId): array
{
    return [
        'bot_id'          => $botId,
        'support_role_id' => '',
        'category_id'     => '',
        'log_channel_id'  => '',
        'open_message'    => "Thanks for creating a ticket!\nSupport will be with you shortly.",
        'dm_message'      => "Here is the transcript for your ticket, please keep this if you ever want to refer to it!",
        'ticket_count'    => 0,
    ];
}

function bht_save_settings(PDO $pdo, int $botId, array $p): void
{
    $pdo->prepare("
        INSERT INTO bot_ticket_settings
          (bot_id, support_role_id, category_id, log_channel_id, open_message, dm_message)
        VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          support_role_id = VALUES(support_role_id),
          category_id     = VALUES(category_id),
          log_channel_id  = VALUES(log_channel_id),
          open_message    = VALUES(open_message),
          dm_message      = VALUES(dm_message)
    ")->execute([
        $botId,
        mb_substr(trim((string)$p['support_role_id']), 0, 32),
        mb_substr(trim((string)$p['category_id']),     0, 32),
        mb_substr(trim((string)$p['log_channel_id']),  0, 32),
        mb_substr(trim((string)$p['open_message']),    0, 2000),
        mb_substr(trim((string)$p['dm_message']),      0, 2000),
    ]);
}

function bht_list_tickets(PDO $pdo, int $botId, bool $onlyOpen = false): array
{
    $sql = 'SELECT * FROM bot_tickets WHERE bot_id = ?';
    $params = [$botId];
    if ($onlyOpen) {
        $sql .= ' AND resolved = 0';
    }
    $sql .= ' ORDER BY created_at DESC LIMIT 100';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

// ── Features (command/event toggles) ─────────────────────────────────────────

function bht_ensure_features_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_ticket_features (
          id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          bot_id       BIGINT UNSIGNED NOT NULL,
          feature_key  VARCHAR(64)     NOT NULL,
          is_enabled   TINYINT(1)      NOT NULL DEFAULT 1,
          PRIMARY KEY (id),
          UNIQUE KEY uq_ticket_feature (bot_id, feature_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/** Returns map feature_key => bool for $botId. Missing keys default to true (enabled). */
function bht_get_features(PDO $pdo, int $botId): array
{
    $stmt = $pdo->prepare('SELECT feature_key, is_enabled FROM bot_ticket_features WHERE bot_id = ?');
    $stmt->execute([$botId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $row) {
        $map[(string)$row['feature_key']] = (bool)(int)$row['is_enabled'];
    }
    return $map;
}

/** Save a single feature toggle for $botId. */
function bht_save_feature(PDO $pdo, int $botId, string $featureKey, bool $enabled): void
{
    $featureKey = mb_substr(trim($featureKey), 0, 64);
    $pdo->prepare("
        INSERT INTO bot_ticket_features (bot_id, feature_key, is_enabled)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled)
    ")->execute([$botId, $featureKey, $enabled ? 1 : 0]);
}
