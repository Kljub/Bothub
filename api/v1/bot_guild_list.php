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

$userId = (int)$_SESSION['user_id'];
$botId  = isset($_GET['bot_id']) && is_numeric($_GET['bot_id']) ? (int)$_GET['bot_id'] : 0;

if ($botId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bot_id fehlt']);
    exit;
}

require_once dirname(__DIR__, 2) . '/functions/custom_commands.php';
require_once dirname(__DIR__, 2) . '/functions/bot_token.php';

try {
    $pdo  = bh_cc_get_pdo();
    $stmt = $pdo->prepare(
        'SELECT bot_token_encrypted, bot_token_enc_meta
           FROM bot_instances
          WHERE id = :id AND owner_user_id = :uid
          LIMIT 1'
    );
    $stmt->execute([':id' => $botId, ':uid' => $userId]);
    $row = $stmt->fetch();

    if (!is_array($row)) {
        echo json_encode(['ok' => false, 'error' => 'Bot nicht gefunden']);
        exit;
    }

    $result = bh_bot_token_resolve($row);
    if (!$result['ok']) {
        echo json_encode(['ok' => false, 'error' => 'Token nicht verfügbar']);
        exit;
    }

    $botToken = (string)$result['token'];

    $url = 'https://discord.com/api/v10/users/@me/guilds';
    $ch  = curl_init($url);
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
    $data     = is_string($raw) ? json_decode($raw, true) : null;

    if ($httpCode < 200 || $httpCode >= 300 || !is_array($data)) {
        echo json_encode(['ok' => false, 'error' => 'Discord API Fehler (HTTP ' . $httpCode . ')']);
        exit;
    }

    $guilds = [];
    foreach ($data as $g) {
        if (!is_array($g) || empty($g['id'])) continue;
        $guilds[] = [
            'id'   => (string)$g['id'],
            'name' => trim((string)($g['name'] ?? $g['id'])),
        ];
    }

    usort($guilds, static fn($a, $b) => strcasecmp($a['name'], $b['name']));

    echo json_encode(['ok' => true, 'guilds' => $guilds], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
