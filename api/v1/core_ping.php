<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$projectRoot = dirname(__DIR__, 2);
$secretCfgPath = $projectRoot . '/db/config/secret.php';

if (!is_file($secretCfgPath)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'secret_missing',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$secretConfig = require $secretCfgPath;
if (!is_array($secretConfig)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'secret_invalid',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$appKey = (string)($secretConfig['APP_KEY'] ?? '');
if ($appKey === '') {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'app_key_missing',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$authHeader = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$prefix = 'Bearer ';

if ($authHeader === '' || strncmp($authHeader, $prefix, strlen($prefix)) !== 0) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'missing_bearer',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$receivedKey = substr($authHeader, strlen($prefix));
if (!hash_equals($appKey, $receivedKey)) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'invalid_key',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode([
    'ok' => true,
    'message' => 'dashboard_connection_ok',
    'time' => gmdate('c'),
], JSON_UNESCAPED_SLASHES);