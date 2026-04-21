<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId  = (int)$_SESSION['user_id'];
$botId   = isset($_GET['bot_id'])   && is_numeric($_GET['bot_id'])  ? (int)$_GET['bot_id']   : 0;
$guildId = isset($_GET['guild_id']) && is_string($_GET['guild_id']) ? trim($_GET['guild_id']) : '';

if ($botId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bot_id fehlt oder ungültig']);
    exit;
}

require_once dirname(__DIR__, 2) . '/functions/custom_commands.php';
require_once dirname(__DIR__, 2) . '/functions/bot_token.php';

function bgc_fail(int $code, string $msg): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function bgc_get_plain_token(PDO $pdo, int $botId, int $userId): string
{
    $stmt = $pdo->prepare(
        'SELECT bot_token_encrypted, bot_token_enc_meta
           FROM bot_instances
          WHERE id = :id AND owner_user_id = :uid
          LIMIT 1'
    );
    $stmt->execute([':id' => $botId, ':uid' => $userId]);
    $row = $stmt->fetch();

    if (!is_array($row)) {
        return '';
    }

    $result = bh_bot_token_resolve($row);

    return $result['ok'] ? (string)$result['token'] : '';
}

function bgc_curl_get(string $botToken, string $path): array
{
    $url = 'https://discord.com/api/v10' . $path;
    $ch  = curl_init($url);

    if ($ch === false) {
        return ['http' => 0, 'data' => null];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bot ' . $botToken,
            'Accept: application/json',
            'User-Agent: BotHub/1.0',
        ],
    ]);

    $raw      = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $data = is_string($raw) ? json_decode($raw, true) : null;

    return ['http' => $httpCode, 'data' => $data];
}

// Discord channel type constants
const BGC_CHANNEL_TEXT         = 0;
const BGC_CHANNEL_VOICE        = 2;
const BGC_CHANNEL_CATEGORY     = 4;
const BGC_CHANNEL_ANNOUNCEMENT = 5;

// Allowed types from ?types=0,2,4,5  (defaults to text+announcement for backwards compat)
$allowedTypes = null;
if (isset($_GET['types']) && $_GET['types'] !== '') {
    $allowedTypes = array_map('intval', explode(',', (string)$_GET['types']));
}

try {
    $pdo      = bh_cc_get_pdo();
    $botToken = bgc_get_plain_token($pdo, $botId, $userId);

    if ($botToken === '') {
        bgc_fail(422, 'Bot-Token nicht verfügbar (verschlüsselt oder nicht gesetzt)');
    }

    // If no guild_id provided, fetch from Discord API
    if ($guildId === '') {
        $res = bgc_curl_get($botToken, '/users/@me/guilds');

        if ($res['http'] < 200 || $res['http'] >= 300 || !is_array($res['data'])) {
            bgc_fail(502, 'Guilds konnten nicht geladen werden (HTTP ' . $res['http'] . ')');
        }

        $guilds = [];
        foreach ($res['data'] as $g) {
            if (!is_array($g) || empty($g['id'])) {
                continue;
            }
            $guilds[] = [
                'id'   => (string)$g['id'],
                'name' => trim((string)($g['name'] ?? $g['id'])),
            ];
        }

        if (count($guilds) === 0) {
            echo json_encode(['ok' => true, 'guild_id' => '', 'channels' => [], 'guilds' => []],
                JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (count($guilds) > 1) {
            usort($guilds, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
            echo json_encode([
                'ok'          => true,
                'needs_guild' => true,
                'guilds'      => $guilds,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $guildId = $guilds[0]['id'];
    }

    // Fetch channels for the guild via Discord API
    $res = bgc_curl_get($botToken, '/guilds/' . rawurlencode($guildId) . '/channels');

    if ($res['http'] < 200 || $res['http'] >= 300 || !is_array($res['data'])) {
        bgc_fail(502, 'Channels konnten nicht geladen werden (HTTP ' . $res['http'] . ')');
    }

    $channels = [];
    foreach ($res['data'] as $c) {
        if (!is_array($c) || empty($c['id'])) {
            continue;
        }

        $type = (int)($c['type'] ?? -1);

        // Filter by requested types; default to text + announcement
        $defaultTypes = [BGC_CHANNEL_TEXT, BGC_CHANNEL_ANNOUNCEMENT];
        $filterTypes  = $allowedTypes ?? $defaultTypes;
        if (!in_array($type, $filterTypes, true)) {
            continue;
        }

        $channels[] = [
            'id'       => (string)$c['id'],
            'name'     => trim((string)($c['name'] ?? $c['id'])),
            'type'     => $type,
            'position' => (int)($c['position'] ?? 0),
            'parent_id'=> isset($c['parent_id']) ? (string)$c['parent_id'] : null,
        ];
    }

    usort($channels, static fn(array $a, array $b): int =>
        $a['position'] !== $b['position']
            ? $a['position'] <=> $b['position']
            : strcasecmp($a['name'], $b['name'])
    );

    echo json_encode([
        'ok'       => true,
        'guild_id' => $guildId,
        'channels' => $channels,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    bgc_fail(500, $e->getMessage());
}
