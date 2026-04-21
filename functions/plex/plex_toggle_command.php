<?php
declare(strict_types=1);

ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Nicht authentifiziert.']);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Methode nicht erlaubt.']);
    exit;
}

require_once __DIR__ . '/plex_client.php';

$userId = (int)$_SESSION['user_id'];

$rawInput = (string)file_get_contents('php://input');
$input    = json_decode($rawInput, true);
if (!is_array($input)) {
    $input = $_POST;
}

$botId      = isset($input['bot_id']) && is_numeric($input['bot_id']) ? (int)$input['bot_id'] : 0;
$commandKey = trim((string)($input['command_key'] ?? ''));
$enabled    = !empty($input['enabled']);

$allowedKeys = ['plex-search', 'plex-random', 'plex-info', 'plex-stats'];

if ($botId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ungültige Bot-ID.']);
    exit;
}

if ($commandKey === '' || !in_array($commandKey, $allowedKeys, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unbekannter Command-Key.']);
    exit;
}

try {
    plex_upsert_bot_command_state($userId, $botId, $commandKey, $enabled);
    plex_notify_bot_reload($botId);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
