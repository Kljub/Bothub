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

$userId = (int)$_SESSION['user_id'];

function bt_json(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    bt_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$action = trim((string)($_POST['action'] ?? ''));
$botId  = (int)($_POST['bot_id'] ?? 0);

if ($botId <= 0) {
    bt_json(['ok' => false, 'error' => 'invalid_bot_id'], 400);
}
if ($action !== 'start' && $action !== 'stop') {
    bt_json(['ok' => false, 'error' => 'invalid_action'], 400);
}

try {
    $cfgPath = dirname(__DIR__, 2) . '/db/config/app.php';
    $cfg = require $cfgPath;
    $db  = $cfg['db'];
    $dsn = 'mysql:host=' . $db['host'] . ';port=' . ($db['port'] ?? '3306') . ';dbname=' . $db['name'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (Throwable) {
    bt_json(['ok' => false, 'error' => 'db_error'], 500);
}

$newState = $action === 'start' ? 'running' : 'stopped';
$jobType  = $action === 'start' ? 'bot_start' : 'bot_stop';

try {
    $pdo->beginTransaction();

    // 1) Update desired_state
    $stmt = $pdo->prepare(
        'UPDATE bot_instances
         SET desired_state = :state,
             last_error     = NULL
         WHERE id = :id AND owner_user_id = :uid
         LIMIT 1'
    );
    $stmt->execute([':state' => $newState, ':id' => $botId, ':uid' => $userId]);

    if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        bt_json(['ok' => false, 'error' => 'not_found'], 404);
    }

    // 2) Cancel any pending/queued jobs for this bot so we don't queue duplicates
    $pdo->prepare(
        "UPDATE runner_jobs
         SET status = 'canceled', updated_at = NOW()
         WHERE bot_id = :id AND status IN ('queued','leased')
         AND job_type IN ('bot_start','bot_stop','bot_restart')"
    )->execute([':id' => $botId]);

    // 3) Enqueue a new job so the core runner picks it up immediately
    $uid = sprintf(
        '%04x%04x-%04x-%04x-%04x-%012x',
        random_int(0, 0xffff), random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0x4000, 0x4fff),
        random_int(0x8000, 0xbfff),
        random_int(0, 0xffffffffffff)
    );
    $pdo->prepare(
        "INSERT INTO runner_jobs
            (job_uid, bot_id, job_type, status, priority, available_at, created_at, updated_at)
         VALUES
            (:uid, :bot_id, :job_type, 'queued', 10, NOW(), NOW(), NOW())"
    )->execute([':uid' => $uid, ':bot_id' => $botId, ':job_type' => $jobType]);

    $pdo->commit();
} catch (Throwable) {
    try { $pdo->rollBack(); } catch (Throwable) {}
    bt_json(['ok' => false, 'error' => 'db_update_error'], 500);
}

bt_json(['ok' => true, 'new_state' => $newState]);
