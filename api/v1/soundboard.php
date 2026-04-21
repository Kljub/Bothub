<?php
declare(strict_types=1);
# PFAD: /api/v1/soundboard.php

session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$userId = (int)$_SESSION['user_id'];

require_once dirname(__DIR__, 2) . '/functions/soundboard.php';

function sb_fail(int $code, string $msg): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($_GET['action'] ?? '');
$botId  = isset($_GET['bot_id']) && is_numeric($_GET['bot_id']) ? (int)$_GET['bot_id'] : 0;

if ($botId <= 0) {
    sb_fail(400, 'bot_id fehlt.');
}

// ── GET: stream audio file for browser preview ───────────────────
if ($method === 'GET' && $action === 'preview') {
    $soundId = isset($_GET['sound_id']) && is_numeric($_GET['sound_id']) ? (int)$_GET['sound_id'] : 0;
    if ($soundId <= 0) { sb_fail(400, 'sound_id fehlt.'); }

    $sounds = sb_list_sounds($userId, $botId);
    $sound  = null;
    foreach ($sounds as $s) {
        if ((int)$s['id'] === $soundId) { $sound = $s; break; }
    }
    if (!$sound) { sb_fail(404, 'Sound nicht gefunden.'); }

    $filePath = sb_storage_dir($botId) . '/' . $sound['filename'];
    if (!is_file($filePath)) { sb_fail(404, 'Datei nicht gefunden.'); }

    $mime     = $sound['mime_type'] ?: 'audio/mpeg';
    $filesize = filesize($filePath);

    // Support range requests so HTML5 audio scrubbing works
    header_remove('Content-Type');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . addslashes($sound['name']) . '"');
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
    } else {
        http_response_code(200);
    }

    header('Content-Length: ' . ($end - $start + 1));

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
}

// ── GET: list sounds + VC status ──────────────────────────────────
if ($method === 'GET' && $action === 'list') {
    $sounds = sb_list_sounds($userId, $botId);
    $vc     = sb_get_vc_status($botId);
    echo json_encode([
        'ok'     => true,
        'sounds' => $sounds,
        'vc'     => $vc,
    ]);
    exit;
}

// ── POST actions ──────────────────────────────────────────────────
if ($method !== 'POST') {
    sb_fail(405, 'Method not allowed');
}

// ── upload (multipart) ────────────────────────────────────────────
if ($action === 'upload') {
    if (!isset($_FILES['file'])) {
        sb_fail(400, 'Keine Datei übermittelt.');
    }
    $name   = (string)($_POST['name']   ?? '');
    $emoji  = (string)($_POST['emoji']  ?? '');
    $volume = isset($_POST['volume']) && is_numeric($_POST['volume']) ? (int)$_POST['volume'] : 100;

    $result = sb_upload_sound($userId, $botId, $_FILES['file'], $name, $emoji, $volume);
    echo json_encode($result);
    exit;
}

// ── JSON body actions ─────────────────────────────────────────────
$raw  = (string)file_get_contents('php://input');
$body = json_decode($raw, true) ?? [];

if ($action === 'delete') {
    $soundId = isset($body['sound_id']) && is_numeric($body['sound_id']) ? (int)$body['sound_id'] : 0;
    if ($soundId <= 0) { sb_fail(400, 'sound_id fehlt.'); }
    echo json_encode(sb_delete_sound($userId, $botId, $soundId));
    exit;
}

if ($action === 'play') {
    $soundId  = isset($body['sound_id']) && is_numeric($body['sound_id']) ? (int)$body['sound_id'] : 0;
    $guildId  = (string)($body['guild_id']   ?? '');
    $channelId= (string)($body['channel_id'] ?? '');
    if ($soundId <= 0)   { sb_fail(400, 'sound_id fehlt.'); }
    if ($guildId === '')  { sb_fail(400, 'guild_id fehlt.'); }
    if ($channelId === '') { sb_fail(400, 'channel_id fehlt (VC).'); }

    // Verify sound belongs to this bot (without loading file_data)
    $sounds = sb_list_sounds($userId, $botId);
    $sound  = null;
    foreach ($sounds as $s) {
        if ((int)$s['id'] === $soundId) { $sound = $s; break; }
    }
    if (!$sound) { sb_fail(404, 'Sound nicht gefunden.'); }

    // Bot core reads file_data from MySQL directly — no file path needed
    echo json_encode(sb_send_to_bot($botId, 'play', [
        'guild_id'   => $guildId,
        'channel_id' => $channelId,
        'sound_id'   => $soundId,
        'sound_name' => $sound['name'],
    ]));
    exit;
}

if ($action === 'stop') {
    $guildId = (string)($body['guild_id'] ?? '');
    if ($guildId === '') { sb_fail(400, 'guild_id fehlt.'); }
    echo json_encode(sb_send_to_bot($botId, 'stop', ['guild_id' => $guildId]));
    exit;
}

if ($action === 'join') {
    $guildId   = (string)($body['guild_id']   ?? '');
    $channelId = (string)($body['channel_id'] ?? '');
    if ($guildId === '' || $channelId === '') { sb_fail(400, 'guild_id / channel_id fehlt.'); }
    echo json_encode(sb_send_to_bot($botId, 'join', ['guild_id' => $guildId, 'channel_id' => $channelId]));
    exit;
}

if ($action === 'leave') {
    $guildId = (string)($body['guild_id'] ?? '');
    if ($guildId === '') { sb_fail(400, 'guild_id fehlt.'); }
    echo json_encode(sb_send_to_bot($botId, 'leave', ['guild_id' => $guildId]));
    exit;
}

if ($action === 'guilds') {
    // Return list of guilds the bot is in (for VC selection)
    echo json_encode(sb_send_to_bot($botId, 'guilds', []));
    exit;
}

sb_fail(400, 'Unbekannte Aktion: ' . $action);
