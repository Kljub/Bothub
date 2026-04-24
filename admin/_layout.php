<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/functions/html.php';

if (!defined('BH_DEV_MODE')) {
    $appCfg = @include $projectRoot . '/db/config/app.php';
    define('BH_DEV_MODE', is_array($appCfg) && !empty($appCfg['dev_mode']));
    unset($appCfg);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($pageTitle) || !is_string($pageTitle)) {
    $pageTitle = 'Admin';
}
if (!isset($contentHtml) || !is_string($contentHtml)) {
    $contentHtml = '';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title><?= h('BotHub Admin – ' . $pageTitle) ?></title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="<?= h($_SESSION['csrf_token'] ?? '') ?>">

    <link href="/assets/css/vendors/flatpickr.min.css" rel="stylesheet">
    <link href="/assets/css/mosaic.css" rel="stylesheet">

    <script src="/assets/js/mosaic-theme.js"></script>
</head>

<body
    class="font-inter antialiased bg-gray-100 dark:bg-gray-900 text-gray-600 dark:text-gray-400"
    :class="{ 'sidebar-expanded': sidebarExpanded }"
    x-data="{ sidebarOpen: false, sidebarExpanded: false }"
>
    <div class="flex h-[100dvh] overflow-hidden">
        <div class="min-w-fit">
            <?php require __DIR__ . '/_partials/sidebar.php'; ?>
        </div>

        <div class="relative flex flex-col flex-1 overflow-y-auto overflow-x-hidden">
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
                            <a
                                href="/dashboard"
                                class="btn-sm bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700/60 hover:border-gray-300 dark:hover:border-gray-600 text-gray-700 dark:text-gray-200"
                            >
                                Dashboard
                            </a>

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

            <?= $contentHtml ?>
        </div>
    </div>

    <script src="/assets/js/vendors/alpinejs.min.js" defer></script>
    <script src="/assets/js/vendors/chart.js"></script>
    <script src="/assets/js/vendors/moment.js"></script>
    <script src="/assets/js/vendors/chartjs-adapter-moment.js"></script>
    <script src="/assets/js/vendors/flatpickr.js"></script>

    <script src="/assets/js/mosaic-main.js"></script>
    <script src="/assets/js/mosaic-flatpickr-init.js"></script>
    <script src="/assets/js/mosaic-dashboard-charts.js"></script>

    <script src="/assets/js/mosaic-darkmode-toggle.js"></script>
</body>
</html>