<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    header('Location: /?auth=login', true, 302);
    exit;
}

require_once __DIR__ . '/plex_client.php';

$botId = isset($_GET['bot_id']) && is_numeric($_GET['bot_id'])
    ? (int)$_GET['bot_id']
    : 0;

$pin = plex_create_pin();

$_SESSION['plex_pin_id'] = (int)$pin['id'];
$_SESSION['plex_pin_code'] = (string)$pin['code'];
$_SESSION['plex_bot_id'] = $botId;

header('Location: ' . plex_build_auth_url($pin['code']));
exit;
