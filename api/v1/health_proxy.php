<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthenticated']);
    exit;
}

$projectRoot = dirname(__DIR__, 2);

function hp_json_error(string $error, int $code = 500): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $cfgPath = $projectRoot . '/db/config/app.php';
    $cfg = require $cfgPath;
    $db = $cfg['db'];
    $dsn = 'mysql:host=' . $db['host'] . ';port=' . ($db['port'] ?? '3306') . ';dbname=' . $db['name'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (Throwable $e) {
    hp_json_error('db_error');
}

try {
    $stmt = $pdo->query("SELECT endpoint FROM core_runners WHERE endpoint != '' ORDER BY id ASC LIMIT 1");
    $row = $stmt->fetch();
} catch (Throwable) {
    hp_json_error('db_query_error');
}

if (!is_array($row) || trim((string)($row['endpoint'] ?? '')) === '') {
    hp_json_error('no_runner', 503);
}

$endpoint = rtrim(trim((string)$row['endpoint']), '/');
$healthUrl = $endpoint . '/health';

$ch = curl_init($healthUrl);
if ($ch === false) {
    hp_json_error('curl_init_failed');
}

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
]);

$raw    = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($raw === false) {
    hp_json_error('curl_failed: ' . curl_error($ch), 502);
}

$decoded = json_decode(trim((string)$raw), true);
if (!is_array($decoded)) {
    hp_json_error('invalid_json', 502);
}

if ($status < 200 || $status >= 300) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'core_error', 'status' => $status, 'data' => $decoded], JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode($decoded, JSON_UNESCAPED_SLASHES);
