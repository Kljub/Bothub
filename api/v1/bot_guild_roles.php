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

function bgr_fail(int $code, string $msg): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function bgr_get_plain_token(PDO $pdo, int $botId, int $userId): string
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

function bgr_curl_get(string $botToken, string $path): array
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

try {
    $pdo      = bh_cc_get_pdo();
    $botToken = bgr_get_plain_token($pdo, $botId, $userId);

    if ($botToken === '') {
        bgr_fail(422, 'Bot-Token nicht verfügbar (verschlüsselt oder nicht gesetzt)');
    }

    // If no guild_id provided, fetch from Discord API
    if ($guildId === '') {
        $res = bgr_curl_get($botToken, '/users/@me/guilds');

        if ($res['http'] < 200 || $res['http'] >= 300 || !is_array($res['data'])) {
            bgr_fail(502, 'Guilds konnten nicht geladen werden (HTTP ' . $res['http'] . ')');
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
            echo json_encode(['ok' => true, 'guild_id' => '', 'roles' => [], 'guilds' => []],
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

    // Fetch roles for the guild via Discord API
    $res = bgr_curl_get($botToken, '/guilds/' . rawurlencode($guildId) . '/roles');

    if ($res['http'] < 200 || $res['http'] >= 300 || !is_array($res['data'])) {
        bgr_fail(502, 'Rollen konnten nicht geladen werden (HTTP ' . $res['http'] . ')');
    }

    $roles = [];
    foreach ($res['data'] as $r) {
        if (!is_array($r) || empty($r['id'])) {
            continue;
        }
        $roles[] = [
            'id'    => (string)$r['id'],
            'name'  => trim((string)($r['name'] ?? $r['id'])),
            'color' => (int)($r['color'] ?? 0),
        ];
    }

    // Sort: @everyone last, rest alphabetically
    usort($roles, static function (array $a, array $b): int {
        if ($a['name'] === '@everyone') {
            return 1;
        }
        if ($b['name'] === '@everyone') {
            return -1;
        }

        return strcasecmp($a['name'], $b['name']);
    });

    echo json_encode([
        'ok'       => true,
        'guild_id' => $guildId,
        'roles'    => $roles,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    bgr_fail(500, $e->getMessage());
}
