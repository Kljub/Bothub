<?php
declare(strict_types=1);

$isAdmin = false;

if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
    try {
        $cfgPath = __DIR__ . '/../../db/config/app.php';
        if (is_file($cfgPath) && is_readable($cfgPath)) {
            $cfg = require $cfgPath;
            if (is_array($cfg) && isset($cfg['db']) && is_array($cfg['db'])) {
                $db = $cfg['db'];
                $host = trim((string)($db['host'] ?? ''));
                $port = trim((string)($db['port'] ?? '3306'));
                $name = trim((string)($db['name'] ?? ''));
                $user = trim((string)($db['user'] ?? ''));
                $pass = (string)($db['pass'] ?? '');

                if ($host !== '' && $name !== '' && $user !== '') {
                    $dsn = 'mysql:host=' . $host . ';port=' . ($port !== '' ? $port : '3306') . ';dbname=' . $name . ';charset=utf8mb4';
                    $pdo = new PDO($dsn, $user, $pass, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]);

                    $uid = (int)$_SESSION['user_id'];
                    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
                    $stmt->execute([':id' => $uid]);
                    $row = $stmt->fetch();
                    if (is_array($row) && (string)($row['role'] ?? '') === 'admin') {
                        $isAdmin = true;
                    }
                }
            }
        }
    } catch (Throwable) {
        $isAdmin = false;
    }
}
?>
<header class="sticky top-0 before:absolute before:inset-0 before:backdrop-blur-md before:bg-gray-100/90 dark:before:bg-gray-900/90 before:-z-10 z-30 border-b border-gray-200 dark:border-gray-700/60">
    <div class="px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16 -mb-px">
            <div class="flex">
                <button
                    class="text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 lg:hidden"
                    @click.stop="sidebarOpen = !sidebarOpen"
                    aria-controls="sidebar"
                    :aria-expanded="sidebarOpen"
                >
                    <span class="sr-only">Open sidebar</span>
                    <svg class="w-6 h-6 fill-current" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M4 6h16v2H4V6Zm0 5h16v2H4v-2Zm0 5h16v2H4v-2Z"/>
                    </svg>
                </button>
            </div>

            <div class="flex items-center space-x-3">
                <button
                    id="darkModeToggle"
                    type="button"
                    class="btn-sm bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700/60 hover:border-gray-300 dark:hover:border-gray-600 text-gray-700 dark:text-gray-200"
                >
                    Dark Mode
                </button>

                <a
                    href="/"
                    class="btn-sm bg-gray-900 text-gray-100 hover:bg-gray-800"
                >
                    Landing
                </a>

                <?php if ($isAdmin): ?>
                    <a
                        href="/admin"
                        class="btn-sm bg-violet-500 hover:bg-violet-600 text-white"
                    >
                        Admin
                    </a>
                <?php endif; ?>

                <a
                    href="/auth/logout"
                    class="btn-sm bg-rose-600 hover:bg-rose-700 text-white"
                >
                    Logout
                </a>
            </div>
        </div>
    </div>
</header>