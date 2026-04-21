<?php
declare(strict_types=1);

function installer_secret_path(string $projectRoot): string
{
    return rtrim($projectRoot, '/') . '/db/config/secret.php';
}

/**
 * Create /db/config/secret.php if missing.
 * - Generates 32 random bytes (256-bit) as APP_KEY
 * - Stores as base64 string
 * - Never overwrites existing secret.php
 */
function installer_write_secret_if_missing(string $projectRoot): array
{
    $path = installer_secret_path($projectRoot);

    if (is_file($path)) {
        return ['ok' => true, 'path' => $path, 'created' => false, 'error' => null];
    }

    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'path' => $path, 'created' => false, 'error' => 'Config-Ordner konnte nicht erstellt werden: ' . $dir];
        }
    }

    try {
        $raw = random_bytes(32);
    } catch (Throwable $e) {
        return ['ok' => false, 'path' => $path, 'created' => false, 'error' => 'random_bytes() fehlgeschlagen: ' . $e->getMessage()];
    }

    $b64 = base64_encode($raw);

    $php = "<?php\n";
    $php .= "declare(strict_types=1);\n";
    $php .= "\n";
    $php .= "return [\n";
    $php .= "    'APP_KEY' => " . var_export($b64, true) . ",\n";
    $php .= "];\n";

    $ok = @file_put_contents($path, $php, LOCK_EX);
    if ($ok === false) {
        return ['ok' => false, 'path' => $path, 'created' => false, 'error' => 'Konnte secret.php nicht schreiben: ' . $path];
    }

    return ['ok' => true, 'path' => $path, 'created' => true, 'error' => null];
}

function installer_pdo(array $cfg): PDO
{
    $host = (string)($cfg['host'] ?? '127.0.0.1');
    $port = (int)($cfg['port'] ?? 3306);
    $dbname = (string)($cfg['dbname'] ?? '');
    $user = (string)($cfg['user'] ?? '');
    $pass = (string)($cfg['pass'] ?? '');

    if ($dbname === '' || $user === '') {
        throw new RuntimeException('DB-Konfiguration unvollständig (dbname/user).');
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function installer_try_connect(array $cfg): array
{
    try {
        $pdo = installer_pdo($cfg);
        $pdo->query('SELECT 1');
        return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function installer_safe_html(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}