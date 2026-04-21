<?php
declare(strict_types=1);

session_start();

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/functions/admin_guard.php';
require_once $projectRoot . '/functions/html.php';
require_once $projectRoot . '/auth/_db.php';

$adminUser = bh_admin_require_user();
$pageTitle = 'Migrations';

$messages = [];
$errors = [];

function bh_admin_ensure_schema_migrations(PDO $pdo): void
{
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS schema_migrations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  filename VARCHAR(190) NOT NULL,
  checksum CHAR(64) NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_schema_migrations_filename (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    $pdo->exec($sql);
}

function bh_admin_load_applied_migrations(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT filename, checksum, applied_at FROM schema_migrations ORDER BY filename ASC');
    $rows = $stmt->fetchAll();

    $out = [];
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $filename = trim((string)($row['filename'] ?? ''));
            if ($filename !== '') {
                $out[$filename] = [
                    'checksum' => trim((string)($row['checksum'] ?? '')),
                    'applied_at' => trim((string)($row['applied_at'] ?? '')),
                ];
            }
        }
    }

    return $out;
}

function bh_admin_list_migration_files(string $dir): array
{
    $files = glob($dir . '/*.sql');
    if (!is_array($files)) {
        return [];
    }

    sort($files, SORT_NATURAL | SORT_FLAG_CASE);

    $out = [];
    foreach ($files as $file) {
        if (!is_file($file) || !is_readable($file)) {
            continue;
        }

        $basename = basename($file);
        $content = file_get_contents($file);
        if ($content === false) {
            continue;
        }

        $out[] = [
            'filename' => $basename,
            'path' => $file,
            'checksum' => hash('sha256', $content),
            'sql' => $content,
        ];
    }

    return $out;
}

$migrationRows = [];
$appliedMap = [];

try {
    $pdo = bh_pdo();
    bh_admin_ensure_schema_migrations($pdo);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $postAction = (string)($_POST['action'] ?? '');
        $migrationDir = $projectRoot . '/db/migrations';
        $files = bh_admin_list_migration_files($migrationDir);
        $appliedMap = bh_admin_load_applied_migrations($pdo);

        if ($postAction === 'run-pending') {
            $ranCount = 0;

            foreach ($files as $file) {
                $filename = $file['filename'];
                $checksum = $file['checksum'];
                $sql = trim((string)$file['sql']);

                if ($sql === '' || isset($appliedMap[$filename])) {
                    continue;
                }

                $pdo->exec($sql);

                $insert = $pdo->prepare(
                    'INSERT INTO schema_migrations (filename, checksum) VALUES (:filename, :checksum)
                     ON DUPLICATE KEY UPDATE checksum = :checksum2, applied_at = NOW()'
                );
                $insert->execute([':filename' => $filename, ':checksum' => $checksum, ':checksum2' => $checksum]);

                @unlink($file['path']);

                $ranCount++;
            }

            $messages[] = $ranCount > 0
                ? $ranCount . ' Migration(en) ausgeführt und Dateien gelöscht.'
                : 'Keine neuen Migrationen vorhanden.';

        } elseif ($postAction === 'run-single') {
            $targetFilename = basename(trim((string)($_POST['filename'] ?? '')));

            // Validate: must exist as a real file in the migrations directory
            $fileMap = [];
            foreach ($files as $file) {
                $fileMap[$file['filename']] = $file;
            }

            if ($targetFilename === '' || !isset($fileMap[$targetFilename])) {
                $errors[] = 'Unbekannte Migration: ' . h($targetFilename);
            } else {
                $file     = $fileMap[$targetFilename];
                $sql      = trim((string)$file['sql']);
                $checksum = $file['checksum'];

                if ($sql === '') {
                    $errors[] = 'Migration ist leer: ' . h($targetFilename);
                } else {
                    $pdo->exec($sql);

                    $insert = $pdo->prepare(
                        'INSERT INTO schema_migrations (filename, checksum) VALUES (:filename, :checksum)
                         ON DUPLICATE KEY UPDATE checksum = :checksum2, applied_at = NOW()'
                    );
                    $insert->execute([':filename' => $targetFilename, ':checksum' => $checksum, ':checksum2' => $checksum]);

                    @unlink($file['path']);

                    $messages[] = 'Migration ausgeführt und Datei gelöscht: ' . $targetFilename;
                }
            }
        }
    }

    $migrationDir = $projectRoot . '/db/migrations';
    $files = bh_admin_list_migration_files($migrationDir);
    $appliedMap = bh_admin_load_applied_migrations($pdo);

    foreach ($files as $file) {
        $filename = $file['filename'];
        $checksum = $file['checksum'];
        $isApplied = isset($appliedMap[$filename]);
        $checksumMismatch = false;

        if ($isApplied) {
            $storedChecksum = trim((string)($appliedMap[$filename]['checksum'] ?? ''));
            if ($storedChecksum !== '' && $storedChecksum !== $checksum) {
                $checksumMismatch = true;
            }
        }

        $migrationRows[] = [
            'filename' => $filename,
            'checksum' => $checksum,
            'applied' => $isApplied,
            'checksum_mismatch' => $checksumMismatch,
            'applied_at' => $isApplied ? (string)($appliedMap[$filename]['applied_at'] ?? '') : '',
        ];
    }
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

$pendingCount = 0;
foreach ($migrationRows as $row) {
    if (!$row['applied']) {
        $pendingCount++;
    }
}

ob_start();
?>
<main class="grow">
    <div class="px-4 sm:px-6 lg:px-8 py-8 w-full max-w-9xl mx-auto">
        <div class="mb-8">
            <h1 class="text-2xl md:text-3xl text-gray-800 dark:text-gray-100 font-bold">
                Migrations
            </h1>
            <div class="text-sm text-gray-500 dark:text-gray-400 mt-2">
                SQL Dateien aus <code>/db/migrations</code> prüfen und ausführen.
            </div>
        </div>

        <?php foreach ($messages as $message): ?>
            <div class="mb-4 rounded-xl border border-emerald-200 dark:border-emerald-700/60 bg-emerald-50 dark:bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-300">
                <?= h($message) ?>
            </div>
        <?php endforeach; ?>

        <?php foreach ($errors as $error): ?>
            <div class="mb-4 rounded-xl border border-rose-200 dark:border-rose-700/60 bg-rose-50 dark:bg-rose-500/10 px-4 py-3 text-sm text-rose-700 dark:text-rose-300">
                <?= h($error) ?>
            </div>
        <?php endforeach; ?>

        <div class="grid grid-cols-12 gap-6">
            <div class="col-span-full xl:col-span-4 bg-white dark:bg-gray-800 shadow-xs rounded-xl">
                <div class="p-5">
                    <div class="text-sm font-semibold text-gray-800 dark:text-gray-100">Pending</div>
                    <div class="mt-3 text-3xl font-bold text-gray-800 dark:text-gray-100"><?= (int)$pendingCount ?></div>

                    <form method="post" class="mt-5">
                        <input type="hidden" name="action" value="run-pending">
                        <button type="submit" class="btn bg-violet-500 hover:bg-violet-600 text-white">
                            Migrationen ausführen
                        </button>
                    </form>
                </div>
            </div>

            <div class="col-span-full xl:col-span-8 bg-white dark:bg-gray-800 shadow-xs rounded-xl">
                <div class="p-5 border-b border-gray-100 dark:border-gray-700/60">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Dateien</h2>
                </div>

                <div class="p-5 overflow-x-auto">
                    <table class="table-auto w-full dark:text-gray-300">
                        <thead class="text-xs uppercase text-gray-400 dark:text-gray-500 bg-gray-50 dark:bg-gray-700/50 rounded-xs">
                            <tr>
                                <th class="p-2 whitespace-nowrap text-left">Datei</th>
                                <th class="p-2 whitespace-nowrap text-left">Status</th>
                                <th class="p-2 whitespace-nowrap text-left">Applied At</th>
                                <th class="p-2 whitespace-nowrap text-left"></th>
                            </tr>
                        </thead>
                        <tbody class="text-sm divide-y divide-gray-100 dark:divide-gray-700/60">
                            <?php if (count($migrationRows) === 0): ?>
                                <tr>
                                    <td class="p-2" colspan="4">Keine Migrationen gefunden.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($migrationRows as $row): ?>
                                    <tr>
                                        <td class="p-2 whitespace-nowrap">
                                            <div class="font-medium text-gray-800 dark:text-gray-100"><?= h((string)$row['filename']) ?></div>
                                        </td>
                                        <td class="p-2 whitespace-nowrap">
                                            <?php if ($row['checksum_mismatch']): ?>
                                                <span class="text-amber-600 dark:text-amber-400">Checksum geändert</span>
                                            <?php elseif ($row['applied']): ?>
                                                <span class="text-emerald-600 dark:text-emerald-400">Applied</span>
                                            <?php else: ?>
                                                <span class="text-violet-600 dark:text-violet-400">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-2 whitespace-nowrap text-gray-500 dark:text-gray-400">
                                            <?= h((string)$row['applied_at']) ?>
                                        </td>
                                        <td class="p-2 whitespace-nowrap text-right">
                                            <?php if (!$row['applied'] || $row['checksum_mismatch']): ?>
                                                <form method="post" onsubmit="return confirm('Migration ausführen: <?= h((string)$row['filename']) ?>?')">
                                                    <input type="hidden" name="action" value="run-single">
                                                    <input type="hidden" name="filename" value="<?= h((string)$row['filename']) ?>">
                                                    <button type="submit" class="btn bg-violet-500 hover:bg-violet-600 text-white text-xs py-1 px-3">
                                                        Run
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>
<?php
$contentHtml = (string)ob_get_clean();

require __DIR__ . '/_layout.php';