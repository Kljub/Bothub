<?php
declare(strict_types=1);

session_start();

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/functions/admin_guard.php';
require_once $projectRoot . '/functions/html.php';
require_once $projectRoot . '/auth/_db.php';

$adminUser = bh_admin_require_user();
$pageTitle = 'Overview';

$stats = [
    'users' => 0,
    'bots' => 0,
    'runners' => 0,
    'migrations' => 0,
];
$dbError = null;

try {
    $pdo = bh_pdo();

    $stats['users'] = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $stats['bots'] = (int)$pdo->query('SELECT COUNT(*) FROM bot_instances')->fetchColumn();
    $stats['runners'] = (int)$pdo->query('SELECT COUNT(*) FROM runners')->fetchColumn();
    $stats['migrations'] = (int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

ob_start();
?>
<main class="grow">
    <div class="px-4 sm:px-6 lg:px-8 py-8 w-full max-w-9xl mx-auto">
        <div class="mb-8">
            <h1 class="text-2xl md:text-3xl text-gray-800 dark:text-gray-100 font-bold">
                Admin Panel
            </h1>
            <div class="text-sm text-gray-500 dark:text-gray-400 mt-2">
                Eingeloggt als <?= h($adminUser['username'] !== '' ? $adminUser['username'] : $adminUser['email']) ?>
            </div>
        </div>

        <?php if ($dbError !== null): ?>
            <div class="mb-6 rounded-xl border border-rose-200 dark:border-rose-700/60 bg-rose-50 dark:bg-rose-500/10 px-4 py-3 text-sm text-rose-700 dark:text-rose-300">
                DB Fehler: <?= h($dbError) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-12 gap-6">
            <div class="col-span-full sm:col-span-6 xl:col-span-3 bg-white dark:bg-gray-800 shadow-xs rounded-xl">
                <div class="p-5">
                    <div class="text-sm font-semibold text-gray-800 dark:text-gray-100">Users</div>
                    <div class="mt-3 text-3xl font-bold text-gray-800 dark:text-gray-100"><?= (int)$stats['users'] ?></div>
                </div>
            </div>

            <div class="col-span-full sm:col-span-6 xl:col-span-3 bg-white dark:bg-gray-800 shadow-xs rounded-xl">
                <div class="p-5">
                    <div class="text-sm font-semibold text-gray-800 dark:text-gray-100">Bots</div>
                    <div class="mt-3 text-3xl font-bold text-gray-800 dark:text-gray-100"><?= (int)$stats['bots'] ?></div>
                </div>
            </div>

            <div class="col-span-full sm:col-span-6 xl:col-span-3 bg-white dark:bg-gray-800 shadow-xs rounded-xl">
                <div class="p-5">
                    <div class="text-sm font-semibold text-gray-800 dark:text-gray-100">Runners</div>
                    <div class="mt-3 text-3xl font-bold text-gray-800 dark:text-gray-100"><?= (int)$stats['runners'] ?></div>
                </div>
            </div>

            <div class="col-span-full sm:col-span-6 xl:col-span-3 bg-white dark:bg-gray-800 shadow-xs rounded-xl">
                <div class="p-5">
                    <div class="text-sm font-semibold text-gray-800 dark:text-gray-100">Applied Migrations</div>
                    <div class="mt-3 text-3xl font-bold text-gray-800 dark:text-gray-100"><?= (int)$stats['migrations'] ?></div>
                </div>
            </div>

            <div class="col-span-full xl:col-span-6 bg-white dark:bg-gray-800 shadow-xs rounded-xl">
                <div class="p-5 border-b border-gray-100 dark:border-gray-700/60">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Migrations</h2>
                </div>
                <div class="p-5">
                    <div class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                        Prüfe den Status deiner SQL Migrationen und führe neue Dateien aus.
                    </div>
                    <a href="/admin/migrations" class="btn bg-violet-500 hover:bg-violet-600 text-white">Zu den Migrations</a>
                </div>
            </div>

            <div class="col-span-full xl:col-span-6 bg-white dark:bg-gray-800 shadow-xs rounded-xl">
                <div class="p-5 border-b border-gray-100 dark:border-gray-700/60">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Core Check</h2>
                </div>
                <div class="p-5">
                    <div class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                        Prüft den aktuellen Shared-Secret-Endpunkt für die Core-Verbindung.
                    </div>
                    <a href="/admin/core-check" class="btn bg-violet-500 hover:bg-violet-600 text-white">Zum Core Check</a>
                </div>
            </div>
        </div>
    </div>
</main>
<?php
$contentHtml = (string)ob_get_clean();

require __DIR__ . '/_layout.php';