<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    header('Location: /?auth=login', true, 302);
    exit;
}

require_once __DIR__ . '/plex_client.php';

$userId = (int)$_SESSION['user_id'];
$pinId = isset($_SESSION['plex_pin_id']) && is_numeric($_SESSION['plex_pin_id'])
    ? (int)$_SESSION['plex_pin_id']
    : 0;
$pinCode = trim((string)($_SESSION['plex_pin_code'] ?? ''));
$botId = isset($_SESSION['plex_bot_id']) && is_numeric($_SESSION['plex_bot_id'])
    ? (int)$_SESSION['plex_bot_id']
    : 0;

$redirect = '/dashboard?view=plex';
if ($botId > 0) {
    $redirect .= '&bot_id=' . $botId;
}

if ($pinId <= 0 || $pinCode === '') {
    $_SESSION['plex_flash_error'] = 'Keine gültige Plex PIN gefunden.';
    header('Location: ' . $redirect, true, 302);
    exit;
}

try {
    $token = null;

    // Poll up to 3 times with a short delay (max ~3 s) instead of blocking for 12 s.
    // Plex authorises almost immediately after the user clicks "Allow" in their app,
    // so 3 quick attempts cover the normal case without exhausting PHP-FPM workers.
    for ($i = 0; $i < 3; $i++) {
        $token = plex_check_pin($pinId, $pinCode);
        if ($token !== null && $token !== '') {
            break;
        }
        if ($i < 2) {
            usleep(800000); // 0.8 s between retries
        }
    }

    if ($token === null || $token === '') {
        $_SESSION['plex_flash_error'] = 'Plex Login noch nicht bestätigt. Bitte erneut versuchen.';
        header('Location: ' . $redirect, true, 302);
        exit;
    }

    $accountId = plex_upsert_account($userId, $token);
    $servers = plex_get_resources($token);
    plex_replace_servers($accountId, $servers);

    if ($botId > 0) {
        $libraries = plex_get_all_libraries_for_user($userId);
        plex_sync_bot_library_catalog($userId, $botId, $accountId, $libraries);
    }

    unset(
        $_SESSION['plex_pin_id'],
        $_SESSION['plex_pin_code'],
        $_SESSION['plex_auth_started_at'],
        $_SESSION['plex_bot_id']
    );

    $_SESSION['plex_flash_success'] = 'Plex verbunden.';
    header('Location: ' . $redirect, true, 302);
    exit;
} catch (Throwable $e) {
    $_SESSION['plex_flash_error'] = $e->getMessage();
    header('Location: ' . $redirect, true, 302);
    exit;
}
