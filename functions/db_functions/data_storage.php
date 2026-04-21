<?php
declare(strict_types=1);

function bhds_ensure_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_data_variables (
          id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          bot_id        BIGINT UNSIGNED NOT NULL,
          name          VARCHAR(100)   NOT NULL,
          reference     VARCHAR(100)   NOT NULL,
          var_type      ENUM('text','number','user','channel','collection','object') NOT NULL DEFAULT 'text',
          default_value TEXT           NULL,
          scope         ENUM('global','server') NOT NULL DEFAULT 'server',
          created_at    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_ds_bot_ref (bot_id, reference),
          INDEX idx_ds_bot (bot_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function bhds_list(PDO $pdo, int $botId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, reference, var_type, default_value, scope, created_at
           FROM bot_data_variables
          WHERE bot_id = ?
          ORDER BY name ASC'
    );
    $stmt->execute([$botId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function bhds_add(PDO $pdo, int $botId, string $name, string $reference, string $varType, string $defaultValue, string $scope): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO bot_data_variables (bot_id, name, reference, var_type, default_value, scope)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$botId, $name, $reference, $varType, $defaultValue, $scope]);
    return (int)$pdo->lastInsertId();
}

function bhds_update(PDO $pdo, int $id, int $botId, string $name, string $varType, string $defaultValue, string $scope): void
{
    $pdo->prepare(
        'UPDATE bot_data_variables
            SET name = ?, var_type = ?, default_value = ?, scope = ?
          WHERE id = ? AND bot_id = ?'
    )->execute([$name, $varType, $defaultValue, $scope, $id, $botId]);
}

function bhds_delete(PDO $pdo, int $id, int $botId): void
{
    $pdo->prepare(
        'DELETE FROM bot_data_variables WHERE id = ? AND bot_id = ?'
    )->execute([$id, $botId]);
}

function bhds_reference_exists(PDO $pdo, int $botId, string $reference, int $excludeId = 0): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM bot_data_variables WHERE bot_id = ? AND reference = ? AND id != ? LIMIT 1'
    );
    $stmt->execute([$botId, $reference, $excludeId]);
    return (bool)$stmt->fetchColumn();
}
