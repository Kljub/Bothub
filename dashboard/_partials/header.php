<?php
declare(strict_types=1);

$isAdmin = false;
$hdr_botDesiredState  = 'stopped';
$hdr_botRuntimeStatus = 'unknown';
$hdr_hasBotId = isset($currentBotId) && is_int($currentBotId) && $currentBotId > 0;

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
                    $hdr_pdo = new PDO($dsn, $user, $pass, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]);

                    $uid = (int)$_SESSION['user_id'];
                    $stmt = $hdr_pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
                    $stmt->execute([':id' => $uid]);
                    $row = $stmt->fetch();
                    if (is_array($row) && (string)($row['role'] ?? '') === 'admin') {
                        $isAdmin = true;
                    }

                    if ($hdr_hasBotId) {
                        $stmt2 = $hdr_pdo->prepare(
                            'SELECT desired_state, runtime_status
                             FROM bot_instances
                             WHERE id = :id AND owner_user_id = :uid
                             LIMIT 1'
                        );
                        $stmt2->execute([':id' => (int)$currentBotId, ':uid' => $uid]);
                        $botRow = $stmt2->fetch();
                        if (is_array($botRow)) {
                            $hdr_botDesiredState  = (string)($botRow['desired_state']  ?? 'stopped');
                            $hdr_botRuntimeStatus = (string)($botRow['runtime_status'] ?? 'unknown');
                        }
                    }
                }
            }
        }
    } catch (Throwable) {
        $isAdmin = false;
    }
}

// Dot color for runtime_status
$hdr_dotClass = match ($hdr_botRuntimeStatus) {
    'running' => 'bg-emerald-500',
    'error'   => 'bg-rose-500',
    'stopped' => 'bg-gray-500',
    default   => 'bg-yellow-500',
};
// Core status dot (updated live by JS ping)
$hdr_coreDotClass = 'bg-yellow-500'; // unknown until first ping
?>
<header class="sticky top-0 before:absolute before:inset-0 before:backdrop-blur-md before:bg-gray-100/90 dark:before:bg-gray-900/90 before:-z-10 before:pointer-events-none z-30 border-b border-gray-200 dark:border-gray-700/60">
    <div class="px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16 -mb-px">
            <!-- Left: Hamburger -->
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

            <!-- Right: Actions -->
            <div class="flex items-center space-x-2">

                <?php if ($hdr_hasBotId): ?>
                <!-- Core status indicator -->
                <div class="flex items-center gap-1.5" title="Core Status">
                    <span id="bh-core-dot" class="inline-block w-2.5 h-2.5 rounded-full <?= $hdr_coreDotClass ?>"></span>
                    <span id="bh-core-label" class="text-xs text-gray-500 dark:text-gray-400 hidden sm:inline">Core</span>
                </div>

                <!-- Bot Start / Stop -->
                <button
                    id="bh-bot-toggle"
                    type="button"
                    data-bot-id="<?= (int)$currentBotId ?>"
                    data-desired="<?= htmlspecialchars($hdr_botDesiredState, ENT_QUOTES, 'UTF-8') ?>"
                    class="btn-sm flex items-center gap-1.5 <?= $hdr_botDesiredState === 'running' ? 'bg-rose-600 hover:bg-rose-700 text-white' : 'bg-emerald-600 hover:bg-emerald-700 text-white' ?>"
                >
                    <!-- dot for runtime_status -->
                    <span id="bh-bot-runtime-dot" class="inline-block w-2 h-2 rounded-full <?= $hdr_dotClass ?>"></span>
                    <span id="bh-bot-toggle-label" class="bh-desktop-only-text"><?= $hdr_botDesiredState === 'running' ? 'Stop' : 'Start' ?></span>
                </button>
                <?php endif; ?>

                <!-- Dark mode toggle — always visible -->
                <button
                    id="darkModeToggle"
                    type="button"
                    class="btn-sm bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700/60 hover:border-gray-300 dark:hover:border-gray-600"
                    title="Theme wechseln"
                >
                    <img src="/assets/img/header/DarkMode.png"   alt="Dark Mode"  class="block dark:hidden w-4 h-4 object-contain">
                    <img src="/assets/img/header/lightmode.png"  alt="Light Mode" class="hidden dark:block w-4 h-4 object-contain">
                </button>

                <!-- Landing — hidden on mobile (accessible via URL) -->
                <a
                    href="/"
                    class="bh-desktop-only btn-sm bg-gray-900 text-gray-100 hover:bg-gray-800"
                >
                    Landing
                </a>

                <?php if ($isAdmin): ?>
                <!-- Admin — hidden on mobile -->
                <a
                    href="/admin/"
                    class="bh-desktop-only btn-sm bg-violet-500 hover:bg-violet-600 text-white"
                >
                    Admin
                </a>
                <?php endif; ?>

                <!-- Logout — hidden on mobile (available in sidebar) -->
                <a
                    href="/auth/logout"
                    class="bh-desktop-only btn-sm bg-rose-600 hover:bg-rose-700 text-white"
                >
                    Logout
                </a>

                <!-- Mobile-only: logout icon button -->
                <a
                    href="/auth/logout"
                    class="bh-mobile-only btn-sm bg-rose-600 hover:bg-rose-700 text-white p-1.5"
                    title="Logout"
                    aria-label="Logout"
                >
                    <svg class="w-4 h-4 fill-current" viewBox="0 0 16 16">
                        <path d="M6 2a2 2 0 0 0-2 2v2h2V4h7v8H6v-2H4v2a2 2 0 0 0 2 2h7a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H6Z"/>
                        <path d="M1 8l3-3v2h5v2H4v2L1 8Z"/>
                    </svg>
                </a>
            </div>
        </div>
    </div>
</header>