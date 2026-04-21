<?php
declare(strict_types=1);

function bham_ensure_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_automod_settings (
          id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          bot_id          BIGINT UNSIGNED NOT NULL,
          anti_invite     TINYINT(1)      NOT NULL DEFAULT 0,
          anti_links      TINYINT(1)      NOT NULL DEFAULT 0,
          anti_spam       TINYINT(1)      NOT NULL DEFAULT 0,
          spam_max_msg    SMALLINT UNSIGNED NOT NULL DEFAULT 5,
          spam_window_s   SMALLINT UNSIGNED NOT NULL DEFAULT 5,
          spam_action     ENUM('delete','warn','kick','ban') NOT NULL DEFAULT 'delete',
          link_channels   JSON NULL,
          blacklist       JSON NULL,
          log_channel_id  VARCHAR(32) NOT NULL DEFAULT '',
          PRIMARY KEY (id),
          UNIQUE KEY uq_automod_bot (bot_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function bham_get(PDO $pdo, int $botId): array
{
    $stmt = $pdo->prepare('SELECT * FROM bot_automod_settings WHERE bot_id = ? LIMIT 1');
    $stmt->execute([$botId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row)) {
        return bham_defaults($botId);
    }

    $row['link_channels'] = is_string($row['link_channels'])
        ? (json_decode($row['link_channels'], true) ?: [])
        : [];
    $row['blacklist'] = is_string($row['blacklist'])
        ? (json_decode($row['blacklist'], true) ?: [])
        : [];

    return $row;
}

function bham_defaults(int $botId): array
{
    return [
        'bot_id'         => $botId,
        'anti_invite'    => 0,
        'anti_links'     => 0,
        'anti_spam'      => 0,
        'spam_max_msg'   => 5,
        'spam_window_s'  => 5,
        'spam_action'    => 'delete',
        'link_channels'  => [],
        'blacklist'      => [],
        'log_channel_id' => '',
    ];
}

function bham_save(PDO $pdo, int $botId, array $p): void
{
    $pdo->prepare("
        INSERT INTO bot_automod_settings
          (bot_id, anti_invite, anti_links, anti_spam,
           spam_max_msg, spam_window_s, spam_action,
           link_channels, blacklist, log_channel_id)
        VALUES (?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          anti_invite    = VALUES(anti_invite),
          anti_links     = VALUES(anti_links),
          anti_spam      = VALUES(anti_spam),
          spam_max_msg   = VALUES(spam_max_msg),
          spam_window_s  = VALUES(spam_window_s),
          spam_action    = VALUES(spam_action),
          link_channels  = VALUES(link_channels),
          blacklist      = VALUES(blacklist),
          log_channel_id = VALUES(log_channel_id)
    ")->execute([
        $botId,
        (int)$p['anti_invite'],
        (int)$p['anti_links'],
        (int)$p['anti_spam'],
        max(1, min(50,  (int)$p['spam_max_msg'])),
        max(1, min(120, (int)$p['spam_window_s'])),
        in_array($p['spam_action'], ['delete','warn','kick','ban'], true) ? $p['spam_action'] : 'delete',
        json_encode(array_values((array)$p['link_channels']), JSON_UNESCAPED_UNICODE),
        json_encode(array_values((array)$p['blacklist']),     JSON_UNESCAPED_UNICODE),
        mb_substr(trim((string)$p['log_channel_id']), 0, 32),
    ]);
}
