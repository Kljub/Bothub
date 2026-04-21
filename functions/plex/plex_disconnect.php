<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    header('Location: /?auth=login', true, 302);
    exit;
}

require_once __DIR__ . '/plex_client.php';

$userId = (int)$_SESSION['user_id'];

try {
    plex_disconnect_account($userId);
    unset($_SESSION['plex_pin_id'], $_SESSION['plex_auth_started_at']);
    $_SESSION['plex_flash_success'] = 'Plex Verbindung wurde getrennt.';
} catch (Throwable $e) {
    $_SESSION['plex_flash_error'] = $e->getMessage();
}

header('Location: /dashboard?view=plex', true, 302);
exit;
