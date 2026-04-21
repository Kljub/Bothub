<?php
declare(strict_types=1);

const BOT_TABLE       = 'bot_instances';
const BOT_COL_ID      = 'id';
const BOT_COL_USER_ID = 'owner_user_id';
const BOT_COL_NAME    = 'display_name';

function bh_get_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfgPath = dirname(__DIR__, 2) . '/db/config/app.php';
    if (!is_file($cfgPath) || !is_readable($cfgPath)) {
        throw new RuntimeException('DB Config nicht gefunden/lesbar: ' . $cfgPath);
    }

    $cfg = require $cfgPath;
    if (!is_array($cfg) || !isset($cfg['db']) || !is_array($cfg['db'])) {
        throw new RuntimeException('DB Config app.php ungültig.');
    }

    $db   = $cfg['db'];
    $host = trim((string)($db['host'] ?? ''));
    $port = trim((string)($db['port'] ?? '3306'));
    $name = trim((string)($db['name'] ?? ''));
    $user = trim((string)($db['user'] ?? ''));
    $pass = (string)($db['pass'] ?? '');

    if ($host === '' || $name === '' || $user === '') {
        throw new RuntimeException('DB Config unvollständig.');
    }

    $dsn = 'mysql:host=' . $host . ';port=' . ($port !== '' ? $port : '3306')
         . ';dbname=' . $name . ';charset=utf8mb4';

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

function bh_load_user_bots(int $userId): array
{
    $pdo = bh_get_pdo();

    $sql = 'SELECT ' . BOT_COL_ID . ' AS id, ' . BOT_COL_NAME . ' AS name '
         . 'FROM '  . BOT_TABLE . ' '
         . 'WHERE ' . BOT_COL_USER_ID . ' = :uid '
         . 'ORDER BY ' . BOT_COL_ID . ' DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $userId]);

    $rows = $stmt->fetchAll();
    if (!is_array($rows)) {
        return [];
    }

    $out = [];
    foreach ($rows as $r) {
        $id   = isset($r['id'])   ? (int)$r['id']         : 0;
        $name = isset($r['name']) ? trim((string)$r['name']) : '';
        if ($id > 0) {
            $out[] = [
                'id'   => $id,
                'name' => $name !== '' ? $name : ('Bot #' . $id),
            ];
        }
    }
    return $out;
}
