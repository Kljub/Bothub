<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$projectRoot = dirname(__DIR__, 2);

require_once $projectRoot . '/auth/_db.php';

$secretCfgPath = $projectRoot . '/db/config/secret.php';

if (!is_file($secretCfgPath) || !is_readable($secretCfgPath)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'secret_missing',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$secretCfg = require $secretCfgPath;
if (!is_array($secretCfg)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'secret_invalid',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$appKey = trim((string)($secretCfg['APP_KEY'] ?? ''));
if ($appKey === '') {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'app_key_missing',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$authHeader = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
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

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'error' => 'method_not_allowed',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'body_read_failed',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$data = json_decode($rawBody, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'invalid_json',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$runnerName = trim((string)($data['runner_name'] ?? ''));
$endpoint = trim((string)($data['endpoint'] ?? ''));

if ($runnerName === '') {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'runner_name_missing',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($endpoint === '') {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'endpoint_missing',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if (!preg_match('~^https?://~i', $endpoint) || filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'endpoint_invalid',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$endpoint = rtrim($endpoint, '/');

try {
    $pdo = bh_pdo();

    $stmt = $pdo->prepare('SELECT id FROM core_runners WHERE runner_name = :runner_name LIMIT 1');
    $stmt->execute([
        ':runner_name' => $runnerName,
    ]);

    $existing = $stmt->fetch();

    if (is_array($existing) && isset($existing['id'])) {
        $runnerId = (int)$existing['id'];

        $update = $pdo->prepare("
            UPDATE core_runners
            SET endpoint = :endpoint,
                status = 'online',
                last_ping = NOW(),
                updated_at = NOW()
            WHERE id = :id
        ");
        $update->execute([
            ':endpoint' => $endpoint,
            ':id' => $runnerId,
        ]);

        echo json_encode([
            'ok' => true,
            'action' => 'updated',
            'runner_id' => $runnerId,
            'runner_name' => $runnerName,
            'endpoint' => $endpoint,
            'time' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $insert = $pdo->prepare("
        INSERT INTO core_runners
        (
            runner_name,
            endpoint,
            status,
            last_ping,
            created_at,
            updated_at
        )
        VALUES
        (
            :runner_name,
            :endpoint,
            'online',
            NOW(),
            NOW(),
            NOW()
        )
    ");
    $insert->execute([
        ':runner_name' => $runnerName,
        ':endpoint' => $endpoint,
    ]);

    $runnerId = (int)$pdo->lastInsertId();

    http_response_code(201);
    echo json_encode([
        'ok' => true,
        'action' => 'created',
        'runner_id' => $runnerId,
        'runner_name' => $runnerName,
        'endpoint' => $endpoint,
        'time' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'db_error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}