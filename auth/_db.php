<?php
declare(strict_types=1);

/**
 * DB helper for Auth endpoints
 * - Loads config from /db/config/app.php (outside public)
 * - Provides bh_pdo(): PDO
 */

function bh_app_config_path(): string
{
    // Project root is one level above /auth
    $root = dirname(__DIR__);
    return $root . '/db/config/app.php';
}

/**
 * @return array<string,mixed>
 */
function bh_app_config(): array
{
    $path = bh_app_config_path();

    // Use realpath for consistent checks (symlinks/relative traversal)
    $real = realpath($path);
    if ($real === false || !is_file($real) || !is_readable($real)) {
        throw new RuntimeException('Config nicht gefunden: ' . $path);
    }

    $cfg = require $real;

    if (!is_array($cfg)) {
        throw new RuntimeException('Config ungültig (kein Array): ' . $real);
    }

    return $cfg;
}

function bh_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = bh_app_config();

    // Erwartete Struktur:
    // return [
    //   'db' => [
    //     'host' => '127.0.0.1',
    //     'port' => 3306,
    //     'name' => '...',
    //     'user' => '...',
    //     'pass' => '...',
    //     'charset' => 'utf8mb4',
    //   ],
    // ];

    $db = $cfg['db'] ?? null;
    if (!is_array($db)) {
        throw new RuntimeException('Config ungültig: db fehlt');
    }

    $host = (string)($db['host'] ?? '127.0.0.1');
    $port = (int)($db['port'] ?? 3306);
    $name = (string)($db['name'] ?? '');
    $user = (string)($db['user'] ?? '');
    $pass = (string)($db['pass'] ?? '');
    $charset = (string)($db['charset'] ?? 'utf8mb4');

    if ($name === '' || $user === '') {
        throw new RuntimeException('Config ungültig: db.name oder db.user fehlt');
    }

    $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=' . $charset;

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}