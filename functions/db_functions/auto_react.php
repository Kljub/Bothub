<?php
declare(strict_types=1);

function bharc_ensure_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_auto_react_settings (
          id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          bot_id           BIGINT UNSIGNED NOT NULL,
          enabled_channels JSON NULL,
          reaction_emojis  JSON NULL,
          ignore_embeds    TINYINT(1) NOT NULL DEFAULT 1,
          allowed_roles    JSON NULL,
          check_words      JSON NULL,
          evt_handler      TINYINT(1) NOT NULL DEFAULT 1,
          PRIMARY KEY (id),
          UNIQUE KEY uq_arc_bot (bot_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function bharc_save(PDO $pdo, int $botId, array $p): void
{
    $pdo->prepare("
        INSERT INTO bot_auto_react_settings
          (bot_id, enabled_channels, reaction_emojis, ignore_embeds, allowed_roles, check_words, evt_handler)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          enabled_channels = VALUES(enabled_channels),
          reaction_emojis  = VALUES(reaction_emojis),
          ignore_embeds    = VALUES(ignore_embeds),
          allowed_roles    = VALUES(allowed_roles),
          check_words      = VALUES(check_words),
          evt_handler      = VALUES(evt_handler)
    ")->execute([
        $botId,
        $p['enabled_channels'], $p['reaction_emojis'],
        $p['ignore_embeds'],    $p['allowed_roles'],
        $p['check_words'],      $p['evt_handler'],
    ]);
}

function bharc_load(PDO $pdo, int $botId): array
{
    $stmt = $pdo->prepare('SELECT * FROM bot_auto_react_settings WHERE bot_id = ? LIMIT 1');
    $stmt->execute([$botId]);
    return $stmt->fetch() ?: [];
}
