<?php
declare(strict_types=1);

function bhcmd_upsert_command(PDO $pdo, int $botId, string $key, string $type, string $name, string $description, int $isEnabled, ?string $settingsJson): void
{
    $pdo->prepare(
        "INSERT INTO commands (bot_id, command_key, command_type, name, description, is_enabled, settings_json, created_at, updated_at)
         VALUES (:bot_id, :command_key, :command_type, :name, :description, :is_enabled, :settings_json, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
         command_type  = VALUES(command_type),
         is_enabled    = VALUES(is_enabled),
         settings_json = VALUES(settings_json),
         name          = VALUES(name),
         description   = VALUES(description),
         updated_at    = NOW()"
    )->execute([
        ':bot_id'        => $botId,
        ':command_key'   => $key,
        ':command_type'  => $type,
        ':name'          => $name,
        ':description'   => $description,
        ':is_enabled'    => $isEnabled,
        ':settings_json' => $settingsJson,
    ]);
}

function bhcmd_load_by_type(PDO $pdo, int $botId, string $type): array
{
    $stmt = $pdo->prepare(
        "SELECT command_key, is_enabled, settings_json FROM commands WHERE bot_id = :bot_id AND command_type = :type"
    );
    $stmt->execute([':bot_id' => $botId, ':type' => $type]);
    return $stmt->fetchAll() ?: [];
}

function bhcmd_fix_type(PDO $pdo, int $botId, array $commandKeys, string $type): void
{
    if (empty($commandKeys)) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($commandKeys), '?'));
    $params = array_merge([$type, $botId], $commandKeys, [$type]);
    $pdo->prepare(
        "UPDATE commands SET command_type = ?
         WHERE bot_id = ? AND command_key IN ($placeholders) AND command_type != ?"
    )->execute($params);
}

/**
 * Load is_enabled for a single command key.
 * Returns 1 (enabled) when no row exists — the "default enabled" contract.
 */
function bhcmd_is_enabled(PDO $pdo, int $botId, string $key): int
{
    $stmt = $pdo->prepare(
        'SELECT is_enabled FROM commands WHERE bot_id = :bot_id AND command_key = :key LIMIT 1'
    );
    $stmt->execute([':bot_id' => $botId, ':key' => $key]);
    $row = $stmt->fetch();
    return $row === false ? 1 : (int)$row['is_enabled'];
}

/**
 * Ensure a command row exists without overwriting is_enabled.
 * On first insert the row is created with $defaultEnabled.
 * If the row already exists only the metadata (type/name/description) is updated.
 */
function bhcmd_ensure_command(PDO $pdo, int $botId, string $key, string $type, string $name, string $description, int $defaultEnabled = 1): void
{
    $pdo->prepare(
        "INSERT INTO commands (bot_id, command_key, command_type, name, description, is_enabled, created_at, updated_at)
         VALUES (:bot_id, :command_key, :command_type, :name, :description, :is_enabled, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
             command_type = VALUES(command_type),
             name         = VALUES(name),
             description  = VALUES(description),
             updated_at   = NOW()"
    )->execute([
        ':bot_id'       => $botId,
        ':command_key'  => $key,
        ':command_type' => $type,
        ':name'         => $name,
        ':description'  => $description,
        ':is_enabled'   => $defaultEnabled,
    ]);
}

/**
 * Upsert is_enabled for a module command (type='module').
 */
function bhcmd_set_module_enabled(PDO $pdo, int $botId, string $key, int $isEnabled): void
{
    $pdo->prepare(
        "INSERT INTO commands (bot_id, command_key, command_type, name, is_enabled, created_at, updated_at)
         VALUES (:bot_id, :key, 'module', :name, :enabled, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
             command_type = 'module',
             is_enabled   = VALUES(is_enabled),
             updated_at   = NOW()"
    )->execute([
        ':bot_id'  => $botId,
        ':key'     => $key,
        ':name'    => $key,
        ':enabled' => $isEnabled,
    ]);
}
