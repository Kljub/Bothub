<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/functions/html.php';

if (!isset($pageTitle) || !is_string($pageTitle)) {
    $pageTitle = 'Dashboard';
}
if (!isset($contentHtml) || !is_string($contentHtml)) {
    $contentHtml = '';
}
if (!isset($extraCssFiles) || !is_array($extraCssFiles)) {
    $extraCssFiles = [];
}
if (!isset($extraJsFiles) || !is_array($extraJsFiles)) {
    $extraJsFiles = [];
}

// Read core ping interval from admin_settings
$_bhPingInterval = 60;
try {
    $_bhPdo = bh_get_pdo();
    $_bhStmt = $_bhPdo->query(
        "SELECT setting_value FROM admin_settings WHERE setting_key = 'core_ping_interval' LIMIT 1"
    );
    $_bhRow = $_bhStmt ? $_bhStmt->fetch() : false;
    if (is_array($_bhRow) && is_numeric($_bhRow['setting_value'] ?? '')) {
        $_bhPingInterval = max(10, (int)$_bhRow['setting_value']);
    }
} catch (Throwable) {
    $_bhPingInterval = 60;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title><?= h('BotHub – ' . $pageTitle) ?></title>
    <meta name="csrf-token" content="<?= h($_SESSION['csrf_token'] ?? '') ?>">
    <meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1">
    <link href="/assets/css/vendors/flatpickr.min.css" rel="stylesheet">
    <link href="/assets/css/mosaic.css" rel="stylesheet">
    <link href="/assets/css/command-switches.css" rel="stylesheet">
    <link href="/assets/css/command-accordion.css" rel="stylesheet">
    <link href="/assets/css/_components.css" rel="stylesheet">
    <link href="/assets/css/_mobile.css" rel="stylesheet">
<?php foreach ($extraCssFiles as $extraCssFile): ?>
    <?php if (is_string($extraCssFile) && $extraCssFile !== ''): ?>
    <link href="<?= h($extraCssFile) ?>" rel="stylesheet">
    <?php endif; ?>
<?php endforeach; ?>
    <script src="/assets/js/mosaic-theme.js"></script>
    <script>window.BhCoreSettings = { pingInterval: <?= (int)$_bhPingInterval ?> };</script>
</head>
<body class="font-inter antialiased bg-gray-100 dark:bg-gray-900 text-gray-600 dark:text-gray-400" :class="{ 'sidebar-expanded': sidebarExpanded }" x-data="{ sidebarOpen: false, sidebarExpanded: false }">
    <div class="flex h-[100dvh] overflow-hidden">
        <div class="min-w-fit"><?php require __DIR__ . '/_partials/sidebar.php'; ?></div>
        <script>!function(){var s=document.getElementById('sidebar');if(s){var v=sessionStorage.getItem('bh_sidebar_scroll');if(v)s.scrollTop=parseInt(v,10)||0;}}();</script>
        <div class="relative flex flex-col flex-1 overflow-y-auto overflow-x-hidden">
            <?php require __DIR__ . '/_partials/header.php'; ?>
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
    <script src="/assets/js/bot-metrics-chart.js"></script>
    <script src="/assets/js/command-switches.js"></script>
    <script src="/assets/js/command-accordion.js"></script>
    <script src="/assets/js/module-toggle.js"></script>
    <script src="/assets/js/channel-picker.js"></script>
<?php foreach ($extraJsFiles as $extraJsFile): ?>
    <?php if (is_string($extraJsFile) && $extraJsFile !== ''): ?>
    <script src="<?= h($extraJsFile) ?>"></script>
    <?php endif; ?>
<?php endforeach; ?>
<script>
(function () {
    'use strict';

    /* ── Sidebar scroll position persistence ── */
    (function () {
        var sb = document.getElementById('sidebar');
        if (!sb) return;
        window.addEventListener('beforeunload', function () {
            sessionStorage.setItem('bh_sidebar_scroll', String(sb.scrollTop));
        });
    }());

    /* ── Bot Start/Stop toggle ── */
    var toggleBtn = document.getElementById('bh-bot-toggle');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            var botId  = parseInt(toggleBtn.dataset.botId, 10);
            var desired = toggleBtn.dataset.desired;
            var action = desired === 'running' ? 'stop' : 'start';

            toggleBtn.disabled = true;

            var fd = new FormData();
            fd.append('bot_id', botId);
            fd.append('action', action);

            fetch('/api/v1/bot_toggle.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d && d.ok) {
                        var newDesired = d.new_state;
                        toggleBtn.dataset.desired = newDesired;
                        document.getElementById('bh-bot-toggle-label').textContent =
                            newDesired === 'running' ? 'Stop Bot' : 'Start Bot';
                        toggleBtn.className = toggleBtn.className
                            .replace(/bg-(rose|emerald)-\d+/g, '')
                            .replace(/hover:bg-(rose|emerald)-\d+/g, '')
                            .trim();
                        if (newDesired === 'running') {
                            toggleBtn.classList.add('bg-rose-600', 'hover:bg-rose-700', 'text-white');
                        } else {
                            toggleBtn.classList.add('bg-emerald-600', 'hover:bg-emerald-700', 'text-white');
                        }
                    }
                })
                .catch(function () {})
                .finally(function () { toggleBtn.disabled = false; });
        });
    }

    /* ── Core health ping ── */
    var coreDot   = document.getElementById('bh-core-dot');
    var coreLabel = document.getElementById('bh-core-label');
    var interval  = (window.BhCoreSettings && window.BhCoreSettings.pingInterval > 0)
        ? window.BhCoreSettings.pingInterval * 1000
        : 60000;

    function pingCore() {
        if (!coreDot) return;
        fetch('/api/v1/health_proxy.php')
            .then(function (r) {
                if (r.ok) {
                    coreDot.className = 'inline-block w-2.5 h-2.5 rounded-full bg-emerald-500';
                    if (coreLabel) coreLabel.title = 'Core: online';
                } else {
                    coreDot.className = 'inline-block w-2.5 h-2.5 rounded-full bg-rose-500';
                    if (coreLabel) coreLabel.title = 'Core: error';
                }
            })
            .catch(function () {
                if (!coreDot) return;
                coreDot.className = 'inline-block w-2.5 h-2.5 rounded-full bg-gray-500';
                if (coreLabel) coreLabel.title = 'Core: unreachable';
            });
    }

    if (coreDot) {
        pingCore();
        setInterval(pingCore, interval);
    }
}());
</script>
</body>
</html>
