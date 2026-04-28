<?php
declare(strict_types=1);
session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_SLASHES);
    exit;
}

$userId = (int)$_SESSION['user_id'];

function api_fail(int $code, string $msg): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_SLASHES);
    exit;
}

function api_pdo(): PDO
{
    $cfgPath = __DIR__ . '/../../db/config/app.php';
    if (!is_file($cfgPath) || !is_readable($cfgPath)) {
        throw new RuntimeException('DB Config nicht gefunden: ' . $cfgPath);
    }

    $cfg = require $cfgPath;
    if (!is_array($cfg) || !isset($cfg['db']) || !is_array($cfg['db'])) {
        throw new RuntimeException('DB Config ungültig');
    }

    $db = $cfg['db'];
    $host = trim((string)($db['host'] ?? ''));
    $port = trim((string)($db['port'] ?? '3306'));
    $name = trim((string)($db['name'] ?? ''));
    $user = trim((string)($db['user'] ?? ''));
    $pass = (string)($db['pass'] ?? '');

    if ($host === '' || $name === '' || $user === '') {
        throw new RuntimeException('DB Config unvollständig');
    }

    $dsn = 'mysql:host=' . $host . ';port=' . ($port !== '' ? $port : '3306') . ';dbname=' . $name . ';charset=utf8mb4';

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

$botId = isset($_GET['bot_id']) && is_numeric($_GET['bot_id']) ? (int)$_GET['bot_id'] : 0;
if ($botId <= 0) {
    api_fail(400, 'missing bot_id');
}

$range = trim((string)($_GET['range'] ?? '6h'));
$allowedRanges = ['1h' => 1, '6h' => 6, '12h' => 12, '24h' => 24, '7d' => 168];
$hours = $allowedRanges[$range] ?? 6;

try {
    $pdo = api_pdo();

    // ownership check
    $own = $pdo->prepare('SELECT id FROM bot_instances WHERE id = :bid AND owner_user_id = :uid LIMIT 1');
    $own->execute([':bid' => $botId, ':uid' => $userId]);
    $ok = $own->fetch();
    if (!is_array($ok)) {
        api_fail(403, 'forbidden');
    }

    $stmt = $pdo->prepare(
        'SELECT bucket_at, uptime_ok, cmd_calls, errors
         FROM bot_metrics_5m
         WHERE bot_id = :bid
           AND bucket_at >= (UTC_TIMESTAMP() - INTERVAL :hrs HOUR)
         ORDER BY bucket_at ASC'
    );
    $stmt->bindValue(':bid', $botId, PDO::PARAM_INT);
    $stmt->bindValue(':hrs', $hours, PDO::PARAM_INT);
    $stmt->execute();

    $labels   = [];
    $uptime   = [];
    $calls    = [];
    $errs     = [];
    $totalCmd = 0;

    while ($row = $stmt->fetch()) {
        $labels[] = (string)$row['bucket_at'];
        $uptime[] = (int)$row['uptime_ok'];
        $n = (int)$row['cmd_calls'];
        $calls[]  = $n;
        $totalCmd += $n;
        $errs[]   = (int)$row['errors'];
    }

    echo json_encode([
        'ok'        => true,
        'bot_id'    => $botId,
        'range'     => $range,
        'total_cmds' => $totalCmd,
        'labels'    => $labels,
        'datasets'  => [
            ['key' => 'uptime_ok', 'label' => 'Online-Pings', 'data' => $uptime],
            ['key' => 'cmd_calls', 'label' => 'Commands',     'data' => $calls],
            ['key' => 'errors',    'label' => 'Errors',       'data' => $errs],
        ],
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    api_fail(500, $e->getMessage());
}