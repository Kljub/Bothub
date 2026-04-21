<?php
declare(strict_types=1);
/** @var array $sidebarBots */
/** @var int|null $currentBotId */
require __DIR__ . '/menu.php';

if (!isset($sidebarBots) || !is_array($sidebarBots)) {
    $sidebarBots = [];
}
if (!isset($currentBotId) || !is_int($currentBotId)) {
    $currentBotId = null;
}
if (!isset($ticketsItems) || !is_array($ticketsItems)) {
    $ticketsItems = [];
}

$currentBotName = 'Meine Bots';
if ($currentBotId !== null && $currentBotId > 0) {
    foreach ($sidebarBots as $b) {
        $bid = (int)($b['id'] ?? 0);
        if ($bid === $currentBotId) {
            $n = trim((string)($b['name'] ?? ''));
            $currentBotName = ($n !== '') ? $n : ('Bot #' . $bid);
            break;
        }
    }
}

$botInitial = '?';
if ($currentBotName !== '') {
    $botInitial = mb_strtoupper(mb_substr($currentBotName, 0, 1, 'UTF-8'), 'UTF-8');
}

$view = (string)($_GET['view'] ?? '');
$isCreateView = ($view === 'create-bot');
$isDashboardView = ($view === '' || $view === 'dashboard');

$requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$requestPath = is_string($requestPath) ? rtrim($requestPath, '/') : '/';
if ($requestPath === '') {
    $requestPath = '/';
}

function bh_sidebar_href(string $viewName, ?int $botId): string
{
    $href = '/dashboard?view=' . rawurlencode($viewName);
    if ($botId !== null && $botId > 0) {
        $href .= '&bot_id=' . $botId;
    }
    return $href;
}

function bh_sidebar_is_active(string $currentView, array $views): bool
{
    return in_array($currentView, $views, true);
}

function bh_sidebar_item(string $label, string $viewName, string $currentView, ?int $botId, string $iconPath, array $activeViews = []): string
{
    $views = $activeViews !== [] ? $activeViews : [$viewName];
    $isActive = bh_sidebar_is_active($currentView, $views);
    $href = bh_sidebar_href($viewName, $botId);

    ob_start();
    ?>
    <li class="pl-4 pr-3 py-2 rounded-lg mb-0.5 last:mb-0 <?= $isActive ? 'bg-linear-to-r from-violet-500/[0.12] dark:from-violet-500/[0.24] to-violet-500/[0.04]' : '' ?>">
        <a class="block text-gray-800 dark:text-gray-100 truncate transition" href="<?= htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <div class="flex items-center">
                <svg class="shrink-0 fill-current <?= $isActive ? 'text-violet-500' : 'text-gray-400 dark:text-gray-500' ?>" width="16" height="16" viewBox="0 0 16 16">
                    <?= $iconPath ?>
                </svg>
                <span class="text-sm font-medium ml-4 lg:opacity-0 lg:sidebar-expanded:opacity-100 2xl:opacity-100 duration-200">
                    <?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </span>
            </div>
        </a>
    </li>
    <?php
    return (string)ob_get_clean();
}

$defaultIcon = '<path d="M3 3h10v2H3V3Zm0 4h10v2H3V7Zm0 4h10v2H3v-2Z"/>';
?>
<!-- Sidebar backdrop (mobile only) -->
<div
    class="fixed inset-0 bg-gray-900/30 z-40 lg:hidden lg:z-auto transition-opacity duration-200"
    :class="sidebarOpen ? 'opacity-100' : 'opacity-0 pointer-events-none'"
    aria-hidden="true"
    x-cloak
></div>

<!-- Sidebar -->
<div
    id="sidebar"
    class="flex flex-col absolute z-40 left-0 top-0 lg:static lg:translate-x-0 h-[100dvh] overflow-y-scroll lg:overflow-y-auto no-scrollbar w-64 lg:w-20 lg:sidebar-expanded:!w-64 2xl:w-64! shrink-0 bg-white dark:bg-gray-800 shadow-xs rounded-r-2xl p-4 transition-all duration-200 ease-in-out"
    :class="sidebarOpen ? 'translate-x-0' : '-translate-x-64'"
    @click.outside="sidebarOpen = false"
    @keydown.escape.window="sidebarOpen = false"
    @mouseenter="sidebarExpanded = true"
    @mouseleave="sidebarExpanded = false"
    x-cloak="lg"
>
    <!-- Top: Logo -->
    <div class="flex items-center justify-between pr-3 sm:px-2 mb-6">
        <button class="lg:hidden text-gray-500 hover:text-gray-400" @click.stop="sidebarOpen = !sidebarOpen">
            <span class="sr-only">Close sidebar</span>
            <svg class="w-6 h-6 fill-current" viewBox="0 0 24 24">
                <path d="M10.7 18.7l1.4-1.4L7.8 13H20v-2H7.8l4.3-4.3-1.4-1.4L4 12z" />
            </svg>
        </button>

        <a class="block" href="/dashboard" aria-label="BotHub Dashboard">
            <svg class="fill-violet-500" xmlns="http://www.w3.org/2000/svg" width="32" height="32">
                <path d="M31.956 14.8C31.372 6.92 25.08.628 17.2.044V5.76a9.04 9.04 0 0 0 9.04 9.04h5.716ZM14.8 26.24v5.716C6.92 31.372.63 25.08.044 17.2H5.76a9.04 9.04 0 0 1 9.04 9.04Zm11.44-9.04h5.716c-.584 7.88-6.876 14.172-14.756 14.756V26.24a9.04 9.04 0 0 1 9.04-9.04ZM.044 14.8C.63 6.92.044 14.8Z" />
            </svg>
        </a>
    </div>

    <div class="space-y-8">

        <!-- Section: Bots -->
        <div x-data="{ botOpen: false }">
            <h3 class="text-xs uppercase text-gray-400 dark:text-gray-500 font-semibold pl-3">
                <span class="hidden lg:block lg:sidebar-expanded:hidden 2xl:hidden text-center w-6" aria-hidden="true">•••</span>
                <span class="lg:hidden lg:sidebar-expanded:block 2xl:block">Bots</span>
            </h3>

            <div class="mt-3 relative">
                <button
                    type="button"
                    class="w-full flex items-center justify-between px-3 py-2 rounded-xl
                           bg-gray-800/60 dark:bg-gray-900/50
                           border border-gray-700/60 dark:border-gray-700/60
                           hover:border-gray-600/70 dark:hover:border-gray-600/70
                           transition"
                    @click="botOpen = !botOpen"
                    aria-haspopup="true"
                    :aria-expanded="botOpen"
                >
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-9 h-9 rounded-full bg-violet-600/25 flex items-center justify-center text-violet-300 font-semibold">
                            <?= htmlspecialchars($botInitial, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </div>

                        <div class="min-w-0 text-left lg:hidden lg:sidebar-expanded:block 2xl:block">
                            <div class="text-sm font-semibold text-gray-100 truncate">
                                <?= htmlspecialchars($currentBotName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </div>
                            <div class="text-xs text-gray-400 truncate">Bot</div>
                        </div>
                    </div>

                    <svg class="w-4 h-4 fill-current text-gray-300 lg:hidden lg:sidebar-expanded:block 2xl:block"
                         viewBox="0 0 16 16"
                         :class="botOpen ? 'rotate-180' : 'rotate-0'"
                         style="transition: transform 150ms ease;">
                        <path d="M4.427 6.427 8 10l3.573-3.573-1.146-1.146L8 7.708 5.573 5.281 4.427 6.427Z"/>
                    </svg>
                </button>

                <div
                    class="hidden lg:sidebar-expanded:block 2xl:block"
                    x-show="botOpen"
                    x-cloak
                    @click.outside="botOpen = false"
                >
                    <div class="mt-2 rounded-xl overflow-hidden border border-gray-700/70 bg-gray-900/70 backdrop-blur-sm">
                        <div class="max-h-64 overflow-y-auto">
                            <?php if (count($sidebarBots) > 0): ?>
                                <?php foreach ($sidebarBots as $b): ?>
                                    <?php
                                    $bid = (int)($b['id'] ?? 0);
                                    $bname = trim((string)($b['name'] ?? ''));
                                    if ($bname === '') {
                                        $bname = 'Bot #' . $bid;
                                    }
                                    $bInit = mb_strtoupper(mb_substr($bname, 0, 1, 'UTF-8'), 'UTF-8');
                                    $isActive = ($currentBotId !== null && $bid === $currentBotId);
                                    $botSwitchHref = '/dashboard?bot_id=' . $bid;
                                    if ($view !== '') {
                                        $botSwitchHref .= '&view=' . rawurlencode($view);
                                    }
                                    ?>
                                    <a
                                        href="<?= htmlspecialchars($botSwitchHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                        class="flex items-center gap-3 px-4 py-3 transition border-b border-gray-700/60 last:border-b-0
                                               <?= $isActive ? 'bg-gray-800/60' : 'hover:bg-gray-800/40' ?>"
                                    >
                                        <div class="w-8 h-8 rounded-full bg-gray-700/50 flex items-center justify-center text-gray-100 font-semibold text-sm">
                                            <?= htmlspecialchars($bInit, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="text-sm font-semibold text-gray-100 truncate">
                                                <?= htmlspecialchars($bname, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                            </div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="px-4 py-3 text-sm text-gray-300">
                                    Keine Bots vorhanden.
                                </div>
                            <?php endif; ?>
                        </div>

                        <a
                            href="/dashboard?view=create-bot"
                            class="flex items-center gap-3 px-4 py-3 bg-gray-950/60 hover:bg-gray-950/80 transition border-t border-gray-700/60
                                   <?= $isCreateView ? 'text-violet-200' : 'text-gray-100' ?>"
                        >
                            <div class="w-8 h-8 rounded-full bg-gray-700/50 flex items-center justify-center text-gray-100 font-semibold text-sm">
                                +
                            </div>
                            <div class="text-sm font-semibold">Add new bot</div>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section: Settings -->
        <div>
            <h3 class="text-xs uppercase text-gray-400 dark:text-gray-500 font-semibold pl-3">
                <span class="hidden lg:block lg:sidebar-expanded:hidden 2xl:hidden text-center w-6" aria-hidden="true">•••</span>
                <span class="lg:hidden lg:sidebar-expanded:block 2xl:block">Settings</span>
            </h3>

            <ul class="mt-3 space-y-1">
                <?php foreach ($settingsItems as $item): ?>
                    <?php if (($item['type'] ?? '') === 'custom'): ?>
                        <li class="pl-4 pr-3 py-2 rounded-lg mb-0.5 last:mb-0 <?= !empty($item['active']) ? 'bg-linear-to-r from-violet-500/[0.12] dark:from-violet-500/[0.24] to-violet-500/[0.04]' : '' ?>">
                            <a class="block text-gray-800 dark:text-gray-100 truncate transition" href="<?= htmlspecialchars((string)$item['href'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                <div class="flex items-center">
                                    <svg class="shrink-0 fill-current <?= !empty($item['active']) ? 'text-violet-500' : 'text-gray-400 dark:text-gray-500' ?>" width="16" height="16" viewBox="0 0 16 16">
                                        <?= (string)$item['icon'] ?>
                                    </svg>
                                    <span class="text-sm font-medium ml-4 lg:opacity-0 lg:sidebar-expanded:opacity-100 2xl:opacity-100 duration-200">
                                        <?= htmlspecialchars((string)$item['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                    </span>
                                </div>
                            </a>
                        </li>
                    <?php else: ?>
                        <?= bh_sidebar_item((string)$item['label'], (string)$item['view'], $view, $currentBotId, (string)$item['icon']) ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Section: Messages -->
        <div>
            <h3 class="text-xs uppercase text-gray-400 dark:text-gray-500 font-semibold pl-3">
                <span class="hidden lg:block lg:sidebar-expanded:hidden 2xl:hidden text-center w-6" aria-hidden="true">•••</span>
                <span class="lg:hidden lg:sidebar-expanded:block 2xl:block">Messages</span>
            </h3>

            <ul class="mt-3 space-y-1">
                <?php foreach ($messagesItems as $item): ?>
                    <?= bh_sidebar_item((string)$item['label'], (string)$item['view'], $view, $currentBotId, $defaultIcon) ?>
                <?php endforeach; ?>
                <?= bh_sidebar_item('Giveaways', 'giveaways', $view, $currentBotId, '<path d="M2 4h12v2H2V4Zm1 3h10v7H3V7Zm3-5a2 2 0 0 1 4 0v2H6V2Z"/>') ?>
            </ul>
        </div>

        <!-- Section: Moderation -->
        <div>
            <h3 class="text-xs uppercase text-gray-400 dark:text-gray-500 font-semibold pl-3">
                <span class="hidden lg:block lg:sidebar-expanded:hidden 2xl:hidden text-center w-6" aria-hidden="true">•••</span>
                <span class="lg:hidden lg:sidebar-expanded:block 2xl:block">Moderation</span>
            </h3>

            <ul class="mt-3 space-y-1">
                <?php foreach ($moderationItems as $item): ?>
                    <?= bh_sidebar_item((string)$item['label'], (string)$item['view'], $view, $currentBotId, $defaultIcon) ?>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Section: Customization -->
        <div>
            <h3 class="text-xs uppercase text-gray-400 dark:text-gray-500 font-semibold pl-3">
                <span class="hidden lg:block lg:sidebar-expanded:hidden 2xl:hidden text-center w-6" aria-hidden="true">•••</span>
                <span class="lg:hidden lg:sidebar-expanded:block 2xl:block">Customization</span>
            </h3>

            <ul class="mt-3 space-y-1">
                <?php foreach ($customizationItems as $item): ?>
                    <?= bh_sidebar_item((string)$item['label'], (string)$item['view'], $view, $currentBotId, $defaultIcon) ?>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Section: Fun -->
        <div>
            <h3 class="text-xs uppercase text-gray-400 dark:text-gray-500 font-semibold pl-3">
                <span class="hidden lg:block lg:sidebar-expanded:hidden 2xl:hidden text-center w-6" aria-hidden="true">•••</span>
                <span class="lg:hidden lg:sidebar-expanded:block 2xl:block">Fun</span>
            </h3>

            <ul class="mt-3 space-y-1">
                <?php foreach ($funItems as $item): ?>
                    <?= bh_sidebar_item((string)$item['label'], (string)$item['view'], $view, $currentBotId, $defaultIcon) ?>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Section: Tickets -->
        <div>
            <h3 class="text-xs uppercase text-gray-400 dark:text-gray-500 font-semibold pl-3">
                <span class="hidden lg:block lg:sidebar-expanded:hidden 2xl:hidden text-center w-6" aria-hidden="true">•••</span>
                <span class="lg:hidden lg:sidebar-expanded:block 2xl:block">Tickets</span>
            </h3>

            <ul class="mt-3 space-y-1">
                <?php foreach ($ticketsItems as $item): ?>
                    <?= bh_sidebar_item((string)$item['label'], (string)$item['view'], $view, $currentBotId, (string)($item['icon'] ?? $defaultIcon)) ?>
                <?php endforeach; ?>
            </ul>
        </div>



        <!-- Section: Social -->
        <div>
            <h3 class="text-xs uppercase text-gray-400 dark:text-gray-500 font-semibold pl-3">
                <span class="hidden lg:block lg:sidebar-expanded:hidden 2xl:hidden text-center w-6" aria-hidden="true">•••</span>
                <span class="lg:hidden lg:sidebar-expanded:block 2xl:block">Social</span>
            </h3>

            <ul class="mt-3 space-y-1">
                <?php foreach ($socialItems as $item): ?>
                    <?php if (($item['type'] ?? '') === 'custom'): ?>
                        <li class="pl-4 pr-3 py-2 rounded-lg mb-0.5 last:mb-0 <?= !empty($item['active']) ? 'bg-linear-to-r from-violet-500/[0.12] dark:from-violet-500/[0.24] to-violet-500/[0.04]' : '' ?>">
                            <a class="block text-gray-800 dark:text-gray-100 truncate transition" href="<?= htmlspecialchars((string)$item['href'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                <div class="flex items-center">
                                    <svg class="shrink-0 fill-current <?= !empty($item['active']) ? 'text-violet-500' : 'text-gray-400 dark:text-gray-500' ?>" width="16" height="16" viewBox="0 0 16 16">
                                        <?= (string)$item['icon'] ?>
                                    </svg>
                                    <span class="text-sm font-medium ml-4 lg:opacity-0 lg:sidebar-expanded:opacity-100 2xl:opacity-100 duration-200">
                                        <?= htmlspecialchars((string)$item['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                    </span>
                                </div>
                            </a>
                        </li>
                    <?php else: ?>
                        <?= bh_sidebar_item((string)$item['label'], (string)$item['view'], $view, $currentBotId, $defaultIcon) ?>
                    <?php endif; ?>

                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Section: Apps (dynamisch aus App Store) -->
        <?php if (!empty($appSidebarItems)): ?>
        <div>
            <h3 class="text-xs uppercase text-gray-400 dark:text-gray-500 font-semibold pl-3">
                <span class="hidden lg:block lg:sidebar-expanded:hidden 2xl:hidden text-center w-6" aria-hidden="true">•••</span>
                <span class="lg:hidden lg:sidebar-expanded:block 2xl:block">Apps</span>
            </h3>
            <ul class="mt-3 space-y-1">
                <?php foreach ($appSidebarItems as $_appItem):
                    $_appView   = (string)$_appItem['view'];
                    $_appLabel  = (string)$_appItem['label'];
                    $_appIcon   = (string)$_appItem['icon'];
                    $_isActive  = ($view === $_appView);
                    $_botParam  = ($currentBotId !== null && $currentBotId > 0) ? '&bot_id=' . $currentBotId : '';
                ?>
                <li class="pl-4 pr-3 py-2 rounded-lg mb-0.5 last:mb-0 <?= $_isActive ? 'bg-linear-to-r from-violet-500/[0.12] dark:from-violet-500/[0.24] to-violet-500/[0.04]' : '' ?>">
                    <a class="block text-gray-800 dark:text-gray-100 truncate transition" href="/dashboard?view=<?= htmlspecialchars($_appView, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= $_botParam ?>">
                        <div class="flex items-center">
                            <svg class="shrink-0 fill-current <?= $_isActive ? 'text-violet-500' : 'text-gray-400 dark:text-gray-500' ?>" width="16" height="16" viewBox="0 0 16 16">
                                <?= $_appIcon !== '' ? $_appIcon : '<path d="M2 2h12v12H2z"/>' ?>
                            </svg>
                            <span class="text-sm font-medium ml-4 lg:opacity-0 lg:sidebar-expanded:opacity-100 2xl:opacity-100 duration-200">
                                <?= htmlspecialchars($_appLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </span>
                        </div>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Logout (immer letztes Element) -->
        <div>
            <ul class="space-y-1">
                <li class="pl-4 pr-3 py-2 rounded-lg">
                    <a class="block text-gray-700 dark:text-gray-200 hover:text-gray-900 dark:hover:text-white truncate transition" href="/auth/logout">
                        <div class="flex items-center">
                            <svg class="shrink-0 fill-current text-gray-400 dark:text-gray-500" width="16" height="16" viewBox="0 0 16 16">
                                <path d="M6 2a2 2 0 0 0-2 2v2h2V4h7v8H6v-2H4v2a2 2 0 0 0 2 2h7a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H6Z"/>
                                <path d="M7.293 8.707 9.586 11 11 9.586 9.414 7.414 11 5.828 9.586 4.414 7.293 6.707 6 7.999l1.293.708Z"/>
                            </svg>
                            <span class="text-sm font-medium ml-4 lg:opacity-0 lg:sidebar-expanded:opacity-100 2xl:opacity-100 duration-200">Logout</span>
                        </div>
                    </a>
                </li>
            </ul>
        </div>

    </div>
</div>