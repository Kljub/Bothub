<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function bh_discord_json_error(string $message, int $statusCode = 400): never
{
    http_response_code($statusCode);
    echo json_encode([
        'ok' => false,
        'message' => $message,
        'guilds' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bh_discord_load_app_config(): array
{
    $path = __DIR__ . '/../db/config/app.php';

    if (!is_file($path) || !is_readable($path)) {
        bh_discord_json_error('Config nicht gefunden: /db/config/app.php', 500);
    }

    $cfg = require $path;

    if (!is_array($cfg) || !isset($cfg['db']) || !is_array($cfg['db'])) {
        bh_discord_json_error('Ungültige App-Konfiguration.', 500);
    }

    return $cfg;
}

function bh_discord_get_pdo(): PDO
{
    $cfg = bh_discord_load_app_config();
    $db = $cfg['db'];

    $host = trim((string)($db['host'] ?? '127.0.0.1'));
    $port = trim((string)($db['port'] ?? '3306'));
    $name = trim((string)($db['name'] ?? ''));
    $user = trim((string)($db['user'] ?? ''));
    $pass = (string)($db['pass'] ?? '');

    if ($name === '' || $user === '') {
        bh_discord_json_error('DB-Konfiguration unvollständig.', 500);
    }

    $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';

    try {
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (Throwable $e) {
        bh_discord_json_error('DB-Verbindung fehlgeschlagen: ' . $e->getMessage(), 500);
    }
}

function bh_discord_table_exists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) 
         FROM information_schema.tables 
         WHERE table_schema = DATABASE() 
           AND table_name = :table_name'
    );
    $stmt->execute([
        ':table_name' => $tableName,
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

function bh_discord_column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND column_name = :column_name'
    );
    $stmt->execute([
        ':table_name' => $tableName,
        ':column_name' => $columnName,
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

function bh_discord_resolve_bot_token(PDO $pdo, int $botId): string
{
    if (!bh_discord_table_exists($pdo, 'bots')) {
        bh_discord_json_error('Tabelle "bots" nicht gefunden. Tokenquelle unklar.', 500);
    }

    $candidateColumns = ['bot_token', 'token', 'discord_token'];

    foreach ($candidateColumns as $column) {
        if (!bh_discord_column_exists($pdo, 'bots', $column)) {
            continue;
        }

        $sql = 'SELECT ' . $column . ' AS token_value FROM bots WHERE id = :bot_id LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':bot_id' => $botId,
        ]);

        $row = $stmt->fetch();
        if (!is_array($row)) {
            continue;
        }

        $token = trim((string)($row['token_value'] ?? ''));
        if ($token !== '') {
            return $token;
        }
    }

    bh_discord_json_error(
        'Kein Bot-Token gefunden. Erwartet in bots.bot_token, bots.token oder bots.discord_token.',
        500
    );
}

function bh_discord_api_request(string $botToken, string $path): array
{
    $url = 'https://discord.com/api/v10' . $path;

    $ch = curl_init($url);
    if ($ch === false) {
        bh_discord_json_error('curl_init fehlgeschlagen.', 500);
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bot ' . $botToken,
            'Accept: application/json',
            'User-Agent: BotHub/1.0',
        ],
    ]);

    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($raw === false) {
        $error = curl_error($ch);
            bh_discord_json_error('Discord Request fehlgeschlagen: ' . $error, 502);
    }


    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        bh_discord_json_error('Discord Antwort ist kein gültiges JSON.', 502);
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $message = trim((string)($decoded['message'] ?? 'Unbekannter Discord API Fehler'));
        bh_discord_json_error('Discord API Fehler (' . $httpCode . '): ' . $message, 502);
    }

    return $decoded;
}

function bh_discord_build_guild_icon_url(string $guildId, string $iconHash): ?string
{
    $guildId = trim($guildId);
    $iconHash = trim($iconHash);

    if ($guildId === '' || $iconHash === '') {
        return null;
    }

    return 'https://cdn.discordapp.com/icons/' . rawurlencode($guildId) . '/' . rawurlencode($iconHash) . '.png?size=64';
}

function bh_discord_fetch_guilds(string $botToken): array
{
    $rows = bh_discord_api_request($botToken, '/users/@me/guilds');

    $guilds = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $id = trim((string)($row['id'] ?? ''));
        if ($id === '') {
            continue;
        }

        $name = trim((string)($row['name'] ?? ''));
        $icon = trim((string)($row['icon'] ?? ''));
        $owner = (bool)($row['owner'] ?? false);
        $permissions = trim((string)($row['permissions'] ?? '0'));

        $guilds[] = [
            'id' => $id,
            'name' => $name !== '' ? $name : ('Guild ' . $id),
            'icon' => $icon,
            'icon_url' => bh_discord_build_guild_icon_url($id, $icon),
            'owner' => $owner ? 1 : 0,
            'permissions' => $permissions,
        ];
    }

    usort(
        $guilds,
        static function (array $a, array $b): int {
            return strcasecmp((string)$a['name'], (string)$b['name']);
        }
    );

    return $guilds;
}

$botId = (int)($_GET['bot_id'] ?? 0);
if ($botId <= 0) {
    bh_discord_json_error('bot_id fehlt oder ist ungültig.', 400);
}

$pdo = bh_discord_get_pdo();
$botToken = bh_discord_resolve_bot_token($pdo, $botId);
$guilds = bh_discord_fetch_guilds($botToken);

echo json_encode([
    'ok' => true,
    'message' => 'Guilds geladen.',
    'guilds' => $guilds,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);