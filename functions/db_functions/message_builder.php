<?php
declare(strict_types=1);

function bhmb_ensure_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_message_templates (
          id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          bot_id           BIGINT UNSIGNED NOT NULL,
          name             VARCHAR(100)    NOT NULL,
          tag              VARCHAR(50)     NOT NULL DEFAULT '',
          is_embed         TINYINT(1)      NOT NULL DEFAULT 1,
          plain_text       TEXT            NULL,
          embed_author     VARCHAR(256)    NOT NULL DEFAULT '',
          embed_thumbnail  VARCHAR(512)    NOT NULL DEFAULT '',
          embed_title      VARCHAR(256)    NOT NULL DEFAULT '',
          embed_body       TEXT            NULL,
          embed_image      VARCHAR(512)    NOT NULL DEFAULT '',
          embed_color      VARCHAR(16)     NOT NULL DEFAULT '#5865f2',
          embed_url        VARCHAR(512)    NOT NULL DEFAULT '',
          created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          INDEX idx_mb_bot  (bot_id),
          UNIQUE KEY uq_mb_bot_name (bot_id, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function bhmb_list(PDO $pdo, int $botId): array
{
    $stmt = $pdo->prepare('SELECT * FROM bot_message_templates WHERE bot_id = ? ORDER BY name ASC');
    $stmt->execute([$botId]);
    return $stmt->fetchAll() ?: [];
}

function bhmb_get(PDO $pdo, int $botId, int $id): array|false
{
    $stmt = $pdo->prepare('SELECT * FROM bot_message_templates WHERE id = ? AND bot_id = ? LIMIT 1');
    $stmt->execute([$id, $botId]);
    return $stmt->fetch() ?: false;
}

function bhmb_save(PDO $pdo, int $botId, array $p, ?int $id = null): int
{
    if ($id !== null) {
        $pdo->prepare("
            UPDATE bot_message_templates SET
              name = ?, tag = ?, is_embed = ?, plain_text = ?,
              embed_author = ?, embed_thumbnail = ?, embed_title = ?,
              embed_body = ?, embed_image = ?, embed_color = ?, embed_url = ?,
              updated_at = NOW()
            WHERE id = ? AND bot_id = ?
        ")->execute([
            $p['name'], $p['tag'], $p['is_embed'], $p['plain_text'] ?: null,
            $p['author'], $p['thumb'], $p['title'],
            $p['body'] ?: null, $p['image'], $p['color'], $p['embed_url'],
            $id, $botId,
        ]);
        return $id;
    }

    $pdo->prepare("
        INSERT INTO bot_message_templates
          (bot_id, name, tag, is_embed, plain_text, embed_author, embed_thumbnail,
           embed_title, embed_body, embed_image, embed_color, embed_url)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $botId, $p['name'], $p['tag'], $p['is_embed'], $p['plain_text'] ?: null,
        $p['author'], $p['thumb'], $p['title'],
        $p['body'] ?: null, $p['image'], $p['color'], $p['embed_url'],
    ]);
    return (int)$pdo->lastInsertId();
}

function bhmb_delete(PDO $pdo, int $botId, int $id): void
{
    $pdo->prepare('DELETE FROM bot_message_templates WHERE id = ? AND bot_id = ?')->execute([$id, $botId]);
}
