<?php
declare(strict_types=1);

// Authenticated audio-streaming endpoint for the bot core.
// Auth: Authorization: Bearer <APP_KEY>  (same key the core uses for all internal calls)
// Usage: GET /api/v1/soundboard_stream.php?bot_id=1&sound_id=5

$secretPath = dirname(__DIR__, 2) . '/db/config/secret.php';
if (!is_file($secretPath)) {
    http_response_code(500);
    exit;
}
$secret = require $secretPath;
$appKey = trim((string)($secret['APP_KEY'] ?? ''));

$auth = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
if ($appKey === '' || $auth !== 'Bearer ' . $appKey) {
    http_response_code(401);
    exit;
}

$botId   = isset($_GET['bot_id'])   && is_numeric($_GET['bot_id'])   ? (int)$_GET['bot_id']   : 0;
$soundId = isset($_GET['sound_id']) && is_numeric($_GET['sound_id']) ? (int)$_GET['sound_id'] : 0;

if ($botId <= 0 || $soundId <= 0) {
    http_response_code(400);
    exit;
}

require_once dirname(__DIR__, 2) . '/functions/soundboard.php';

$pdo  = sb_get_pdo();
$stmt = $pdo->prepare(
    'SELECT filename, mime_type, filesize FROM bot_soundboard_sounds WHERE id = ? AND bot_id = ? LIMIT 1'
);
$stmt->execute([$soundId, $botId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    exit;
}

$filePath = sb_storage_dir($botId) . '/' . $row['filename'];
if (!is_file($filePath)) {
    http_response_code(404);
    exit;
}

$mime     = $row['mime_type'] ?: 'audio/mpeg';
$filesize = (int)($row['filesize'] ?: filesize($filePath));

header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Cache-Control: no-store');

$start = 0;
$end   = $filesize - 1;

if (isset($_SERVER['HTTP_RANGE'])) {
    preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m);
    $start = $m[1] !== '' ? (int)$m[1] : 0;
    $end   = $m[2] !== '' ? (int)$m[2] : $filesize - 1;
    $end   = min($end, $filesize - 1);
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $filesize);
    header('Content-Length: ' . ($end - $start + 1));
} else {
    http_response_code(200);
    header('Content-Length: ' . $filesize);
}

$fp = fopen($filePath, 'rb');
if ($fp === false) { exit; }
fseek($fp, $start);
$remaining = $end - $start + 1;
while ($remaining > 0 && !feof($fp)) {
    $chunk = fread($fp, min(65536, $remaining));
    if ($chunk === false) break;
    echo $chunk;
    $remaining -= strlen($chunk);
    flush();
}
fclose($fp);
exit;
