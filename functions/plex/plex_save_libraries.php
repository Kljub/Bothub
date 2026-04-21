<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    header('Location: /?auth=login', true, 302);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Location: /dashboard?view=plex', true, 302);
    exit;
}

require_once __DIR__ . '/plex_client.php';

$userId = (int)$_SESSION['user_id'];

$botId = 0;
if (isset($_POST['bot_id']) && is_numeric((string)$_POST['bot_id'])) {
    $botId = (int)$_POST['bot_id'];
} elseif (isset($_GET['bot_id']) && is_numeric((string)$_GET['bot_id'])) {
    $botId = (int)$_GET['bot_id'];
}

$redirect = '/dashboard?view=plex';
if ($botId > 0) {
    $redirect .= '&bot_id=' . $botId;
}

if ($botId <= 0) {
    $_SESSION['plex_flash_error'] = 'Bitte wähle zuerst einen Bot aus.';
    header('Location: ' . $redirect, true, 302);
    exit;
}

try {
    plex_assert_user_owns_bot($userId, $botId);

    $state = plex_load_state_for_user($userId);
    $account = isset($state['account']) && is_array($state['account']) ? $state['account'] : null;

    if (empty($state['connected']) || $account === null || !isset($account['id'])) {
        $_SESSION['plex_flash_error'] = 'Es ist kein Plex Account verbunden.';
        header('Location: ' . $redirect, true, 302);
        exit;
    }

    $allowedLibraries = [];
    if (isset($_POST['allowed_libraries']) && is_array($_POST['allowed_libraries'])) {
        foreach ($_POST['allowed_libraries'] as $value) {
            $compoundKey = trim((string)$value);
            if ($compoundKey === '' || !str_contains($compoundKey, '::')) {
                continue;
            }
            $allowedLibraries[] = $compoundKey;
        }
    }

    plex_replace_bot_allowed_libraries($userId, $botId, $allowedLibraries);

    $_SESSION['plex_flash_success'] = 'Die erlaubten Plex Libraries wurden gespeichert.';
    header('Location: ' . $redirect, true, 302);
    exit;
} catch (Throwable $e) {
    $_SESSION['plex_flash_error'] = $e->getMessage();
    header('Location: ' . $redirect, true, 302);
    exit;
}