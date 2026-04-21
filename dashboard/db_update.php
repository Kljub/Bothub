<?php
declare(strict_types=1);
# PFAD: /dashboard/db_update.php
session_start();

if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    header('Location: /?auth=login', true, 302);
    exit;
}

$userId = (int)$_SESSION['user_id'];

function pdo_from_app_config(): PDO
{
    $cfgPath = __DIR__ . '/../db/config/app.php';
    if (!is_file($cfgPath) || !is_readable($cfgPath)) {
        throw new RuntimeException('DB Config nicht gefunden: ' . $cfgPath);
    }

    $cfg = require $cfgPath;
    if (!is_array($cfg) || !isset($cfg['db']) || !is_array($cfg['db'])) {
        throw new RuntimeException('DB Config ungültig');
    }

    $db = $cfg['db'];
    $dsn = 'mysql:host=' . $db['host'] .
           ';port=' . ($db['port'] ?? '3306') .
           ';dbname=' . $db['name'] .
           ';charset=utf8mb4';

    return new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function is_admin(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();
    return is_array($row) && (string)($row['role'] ?? '') === 'admin';
}

function csrf_get(): string
{
    if (!isset($_SESSION['csrf_db_update'])) {
        $_SESSION['csrf_db_update'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_db_update'];
}

function csrf_ok(string $t): bool
{
    return isset($_SESSION['csrf_db_update'])
        && hash_equals($_SESSION['csrf_db_update'], $t);
}

function split_sql(string $sql): array
{
    $sql = str_replace("\r\n", "\n", $sql);
    $lines = explode("\n", $sql);

    $clean = [];
    foreach ($lines as $ln) {
        $t = trim($ln);
        if ($t === '' || str_starts_with($t, '--')) continue;
        $clean[] = $ln;
    }

    $parts = explode(';', implode("\n", $clean));
    $out = [];
    foreach ($parts as $p) {
        $s = trim($p);
        if ($s !== '') $out[] = $s;
    }
    return $out;
}

$pdo = pdo_from_app_config();

if (!is_admin($pdo, $userId)) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

$migrationsDir = __DIR__ . '/../db/migrations';

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && (string)($_POST['action'] ?? '') === 'apply') {

    $csrf = (string)($_POST['csrf'] ?? '');
    if (!csrf_ok($csrf)) {
        header('Location: /dashboard?db_update=csrf_error', true, 302);
        exit;
    }

    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                filename VARCHAR(190) NOT NULL,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_schema_migrations_filename (filename)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $applied = [];
        $stmt = $pdo->query('SELECT filename FROM schema_migrations');
        while ($r = $stmt->fetch()) {
            $applied[$r['filename']] = true;
        }

        $files = glob($migrationsDir . '/*.sql');
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $filename = basename($file);
            if (isset($applied[$filename])) continue;

            $sql = file_get_contents($file);
            if ($sql === false) continue;

            foreach (split_sql($sql) as $stmtSql) {
                $pdo->exec($stmtSql);
            }

            $ins = $pdo->prepare(
                'INSERT INTO schema_migrations (filename, checksum, applied_at)
                 VALUES (:f, :c, NOW())'
            );
            $ins->execute([
                ':f' => $filename,
                ':c' => hash('sha256', $sql),
            ]);
        }

        // ✅ Redirect back to Dashboard after success
        header('Location: /dashboard?db_update=success', true, 302);
        exit;

    } catch (Throwable) {
        header('Location: /dashboard?db_update=error', true, 302);
        exit;
    }
}

$pageTitle = 'DB Update';
ob_start();
?>

<main class="grow">
    <div class="px-4 sm:px-6 lg:px-8 py-8 w-full max-w-9xl mx-auto">
        <h1 class="text-2xl md:text-3xl font-bold">DB Update</h1>

        <form method="post" class="mt-6">
            <input type="hidden" name="action" value="apply">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_get(), ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn-sm bg-violet-500 hover:bg-violet-600 text-white">
                Migrations anwenden
            </button>
        </form>
    </div>
</main>

<?php
$contentHtml = ob_get_clean();
require __DIR__ . '/_layout.php';