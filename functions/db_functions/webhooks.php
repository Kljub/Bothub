<?php
declare(strict_types=1);

function bh_wh_ensure_tables(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS bot_webhook_keys (
            id         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            bot_id     INT UNSIGNED  NOT NULL,
            api_key    VARCHAR(128)  NOT NULL,
            created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_webhook_key_bot (bot_id),
            KEY        idx_webhook_api_key (api_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS bot_webhooks (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            bot_id     INT UNSIGNED    NOT NULL,
            event_id   VARCHAR(32)     NOT NULL,
            event_name VARCHAR(128)    NOT NULL DEFAULT \'\',
            created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_webhook_event (bot_id, event_id),
            KEY        idx_bot_webhooks (bot_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function bh_wh_generate_api_key(): string
{
    return bin2hex(random_bytes(32)); // 64 hex chars
}

function bh_wh_generate_event_id(): string
{
    $raw = base64_encode(random_bytes(16));
    return substr(str_replace(['+', '/', '='], ['', '', ''], $raw), 0, 22);
}

function bh_wh_get_api_key(PDO $pdo, int $botId): ?string
{
    $stmt = $pdo->prepare('SELECT api_key FROM bot_webhook_keys WHERE bot_id = :bid LIMIT 1');
    $stmt->execute([':bid' => $botId]);
    $row = $stmt->fetch();
    return is_array($row) ? (string)$row['api_key'] : null;
}

function bh_wh_upsert_api_key(PDO $pdo, int $botId, string $key): void
{
    $pdo->prepare(
        'INSERT INTO bot_webhook_keys (bot_id, api_key)
         VALUES (:bid, :key)
         ON DUPLICATE KEY UPDATE api_key = VALUES(api_key), updated_at = CURRENT_TIMESTAMP'
    )->execute([':bid' => $botId, ':key' => $key]);
}

function bh_wh_list_webhooks(PDO $pdo, int $botId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, event_id, event_name, created_at
         FROM bot_webhooks WHERE bot_id = :bid ORDER BY id DESC'
    );
    $stmt->execute([':bid' => $botId]);
    return $stmt->fetchAll() ?: [];
}

function bh_wh_set_flash(string $type, string $msg): void
{
    $_SESSION['wh_flash'] = ['type' => $type, 'msg' => $msg];
}

function bh_wh_redirect(?int $botId): never
{
    $url = '/dashboard?view=webhooks';
    if ($botId !== null && $botId > 0) {
        $url .= '&bot_id=' . $botId;
    }
    header('Location: ' . $url, true, 302);
    exit;
}

function bh_wh_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
