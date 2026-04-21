<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

$userId = isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

if ($userId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Nicht eingeloggt.']);
    exit;
}

require_once dirname(__DIR__, 2) . '/functions/timed_events.php';

// CSRF check
$csrf       = (string)($_SERVER['HTTP_X_TE_CSRF'] ?? '');
$csrfStored = (string)($_SESSION['bh_te_csrf'] ?? '');

if ($csrfStored === '' || !hash_equals($csrfStored, $csrf)) {
    echo json_encode(['ok' => false, 'error' => 'Ungültiges CSRF-Token.']);
    exit;
}

// Parse JSON body
$rawBody = (string)file_get_contents('php://input');
$body    = json_decode($rawBody, true);

if (!is_array($body)) {
    echo json_encode(['ok' => false, 'error' => 'Ungültige Anfrage.']);
    exit;
}

$action = (string)($body['action'] ?? '');

// ── toggle_enabled ────────────────────────────────────────────────────────────
if ($action === 'toggle_enabled') {
    $eventId = isset($body['event_id']) && is_numeric($body['event_id']) ? (int)$body['event_id'] : 0;
    $enabled = isset($body['enabled']) ? (bool)$body['enabled'] : false;

    if ($eventId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Ungültige Event-ID.']);
        exit;
    }

    $result = bh_te_toggle_enabled($userId, $eventId, $enabled);

    if (($result['ok'] ?? false) && isset($result['bot_id']) && $result['bot_id'] > 0) {
        try {
            if (function_exists('bh_notify_bot_reload')) {
                bh_notify_bot_reload((int)$result['bot_id']);
            }
        } catch (Throwable) {}
    }

    echo json_encode($result);
    exit;
}

// ── set_group ─────────────────────────────────────────────────────────────────
if ($action === 'set_group') {
    $eventId   = isset($body['event_id']) && is_numeric($body['event_id']) ? (int)$body['event_id'] : 0;
    $groupName = (string)($body['group_name'] ?? '');

    if ($eventId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Ungültige Event-ID.']);
        exit;
    }

    $result = bh_te_set_group($userId, $eventId, $groupName);
    echo json_encode($result);
    exit;
}

// ── delete_event ──────────────────────────────────────────────────────────────
if ($action === 'delete_event') {
    $eventId = isset($body['event_id']) && is_numeric($body['event_id']) ? (int)$body['event_id'] : 0;

    if ($eventId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Ungültige Event-ID.']);
        exit;
    }

    $result = bh_te_delete($userId, $eventId);
    echo json_encode($result);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unbekannte Aktion.']);
