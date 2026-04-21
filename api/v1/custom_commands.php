<?php
declare(strict_types=1);
# PFAD: /api/v1/custom_commands.php

session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$userId = (int)$_SESSION['user_id'];

require_once dirname(__DIR__, 2) . '/functions/custom_commands.php';

function cc_api_fail(int $code, string $msg): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cc_api_fail(405, 'Method not allowed');
}

$body   = (string) file_get_contents('php://input');
$data   = json_decode($body, true);
$action = isset($data['action']) && is_string($data['action']) ? $data['action'] : '';

// CSRF via session token sent as header
$csrfHeader = $_SERVER['HTTP_X_CC_CSRF'] ?? '';
$csrfSession = $_SESSION['bh_cc_csrf'] ?? '';
if (!is_string($csrfSession) || $csrfSession === '' || !hash_equals($csrfSession, $csrfHeader)) {
    cc_api_fail(403, 'invalid csrf');
}

try {
    if ($action === 'toggle_enabled') {
        $commandId = isset($data['command_id']) ? (int)$data['command_id'] : 0;
        $enabled   = isset($data['enabled']) ? (bool)$data['enabled'] : false;

        $result = bh_cc_toggle_command_enabled($userId, $commandId, $enabled);
        echo json_encode($result);
        exit;
    }

    if ($action === 'set_group') {
        $commandId = isset($data['command_id']) ? (int)$data['command_id'] : 0;
        $groupName = isset($data['group_name']) && is_string($data['group_name']) ? $data['group_name'] : '';

        $result = bh_cc_set_command_group($userId, $commandId, $groupName);

        // Trigger bot reload so slash commands reflect the change
        if ($result['ok'] ?? false) {
            $command = bh_cc_get_custom_command($userId, $commandId);
            if (is_array($command) && isset($command['bot_id'])) {
                bh_notify_bot_reload((int)$command['bot_id']);
            }
        }

        echo json_encode($result);
        exit;
    }

    if ($action === 'delete_command') {
        $commandId = isset($data['command_id']) ? (int)$data['command_id'] : 0;

        // Get bot_id before deleting (for reload)
        $command = bh_cc_get_custom_command($userId, $commandId);
        $botId   = is_array($command) ? (int)($command['bot_id'] ?? 0) : 0;

        $result = bh_cc_delete_custom_command($userId, $commandId);

        if ($result['ok'] ?? false) {
            bh_notify_bot_reload($botId);
        }

        echo json_encode($result);
        exit;
    }

    cc_api_fail(400, 'unknown action');
} catch (Throwable $e) {
    cc_api_fail(500, $e->getMessage());
}
