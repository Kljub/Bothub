<?php
declare(strict_types=1);

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/admin'), PHP_URL_PATH);
$path = is_string($path) ? rtrim($path, '/') : '/admin';
if ($path === '') {
    $path = '/admin';
}

$isAdminHome = ($path === '/admin');
$isMigrations = ($path === '/admin/migrations');
$isCoreCheck = ($path === '/admin/core-check');
$isCoreUpdate = ($path === '/admin/core-update');
$isCoreRunners = ($path === '/admin/core-runners');
$isSettings = ($path === '/admin/settings');
$isAppStore = ($path === '/admin/app-store');
$isDanger        = ($path === '/admin/danger');
?>
<div
    class="fixed inset-0 bg-gray-900/30 z-40 lg:hidden lg:z-auto transition-opacity duration-200"
    :class="sidebarOpen ? 'opacity-100' : 'opacity-0 pointer-events-none'"
    aria-hidden="true"
    x-cloak
></div>

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
    <div class="flex items-center justify-between pr-3 sm:px-2 mb-6">
        <button class="lg:hidden text-gray-500 hover:text-gray-400" @click.stop="sidebarOpen = !sidebarOpen">
            <span class="sr-only">Close sidebar</span>
            <svg class="w-6 h-6 fill-current" viewBox="0 0 24 24">
                <path d="M10.7 18.7l1.4-1.4L7.8 13H20v-2H7.8l4.3-4.3-1.4-1.4L4 12z" />
            </svg>
        </button>

        <a class="block" href="/admin" aria-label="BotHub Admin">
            <svg class="fill-violet-500" xmlns="http://www.w3.org/2000/svg" width="32" height="32">
                <path d="M31.956 14.8C31.372 6.92 25.08.628 17.2.044V5.76a9.04 9.04 0 0 0 9.04 9.04h5.716ZM14.8 26.24v5.716C6.92 31.372.63 25.08.044 17.2H5.76a9.04 9.04 0 0 1 9.04 9.04Zm11.44-9.04h5.716c-.584 7.88-6.876 14.172-14.756 14.756V26.24a9.04 9.04 0 0 1 9.04-9.04ZM.044 14.8C.628 6.92 6.92.628 14.8.044V5.76A9.04 9.04 0 0 0 5.76 14.8H.044Z" />
            </svg>
        </a>
    </div>

    <div class="space-y-8">
        <div>
            <h3 class="text-xs uppercase text-gray-400 dark:text-gray-500 font-semibold pl-3">
                <span class="hidden lg:block lg:sidebar-expanded:hidden 2xl:hidden text-center w-6" aria-hidden="true">•••</span>
                <span class="lg:hidden lg:sidebar-expanded:block 2xl:block">Admin</span>
            </h3>

            <ul class="mt-3 space-y-1">
                <li class="pl-4 pr-3 py-2 rounded-lg mb-0.5 last:mb-0 <?= $isAdminHome ? 'bg-linear-to-r from-violet-500/[0.12] dark:from-violet-500/[0.24] to-violet-500/[0.04]' : '' ?>">
                    <a class="block text-gray-800 dark:text-gray-100 truncate transition" href="/admin">
                        <div class="flex items-center">
                            <svg class="shrink-0 fill-current <?= $isAdminHome ? 'text-violet-500' : 'text-gray-400 dark:text-gray-500' ?>" width="16" height="16" viewBox="0 0 16 16">
                                <path d="M8 0a8 8 0 1 0 0 16A8 8 0 0 0 8 0Zm1 3v5h4v2H7V3h2Z"/>
                            </svg>
                            <span class="text-sm font-medium ml-4 lg:opacity-0 lg:sidebar-expanded:opacity-100 2xl:opacity-100 duration-200">Overview</span>
                        </div>
                    </a>
                </li>

                <li class="pl-4 pr-3 py-2 rounded-lg mb-0.5 last:mb-0 <?= $isMigrations ? 'bg-linear-to-r from-violet-500/[0.12] dark:from-violet-500/[0.24] to-violet-500/[0.04]' : '' ?>">
                    <a class="block text-gray-800 dark:text-gray-100 truncate transition" href="/admin/migrations">
                        <div class="flex items-center">
                            <svg class="shrink-0 fill-current <?= $isMigrations ? 'text-violet-500' : 'text-gray-400 dark:text-gray-500' ?>" width="16" height="16" viewBox="0 0 16 16">
                                <path d="M3 2h7l3 3v9H3V2Zm7 1.5V6h2.5L10 3.5ZM5 8h6v1H5V8Zm0 2h6v1H5v-1Z"/>
                            </svg>
                            <span class="text-sm font-medium ml-4 lg:opacity-0 lg:sidebar-expanded:opacity-100 2xl:opacity-100 duration-200">Migrations</span>
                        </div>
                    </a>
                </li>

                <li class="pl-4 pr-3 py-2 rounded-lg mb-0.5 last:mb-0 <?= $isCoreCheck ? 'bg-linear-to-r from-violet-500/[0.12] dark:from-violet-500/[0.24] to-violet-500/[0.04]' : '' ?>">
                    <a class="block text-gray-800 dark:text-gray-100 truncate transition" href="/admin/core-check">
                        <div class="flex items-center">
                            <svg class="shrink-0 fill-current <?= $isCoreCheck ? 'text-violet-500' : 'text-gray-400 dark:text-gray-500' ?>" width="16" height="16" viewBox="0 0 16 16">
                                <path d="M8 1a5 5 0 0 1 5 5c0 1.5-.66 2.84-1.7 3.75L13.5 12H11v2H5v-2H2.5l2.2-2.25A4.97 4.97 0 0 1 3 6a5 5 0 0 1 5-5Zm0 2a3 3 0 0 0-3 3c0 .92.41 1.75 1.05 2.3l.33.28-.76.77h4.76l-.76-.77.33-.28A2.98 2.98 0 0 0 11 6a3 3 0 0 0-3-3Z"/>
                            </svg>
                            <span class="text-sm font-medium ml-4 lg:opacity-0 lg:sidebar-expanded:opacity-100 2xl:opacity-100 duration-200">Core Check</span>
                        </div>
                    </a>
                </li>

                <li class="pl-4 pr-3 py-2 rounded-lg mb-0.5 last:mb-0 <?= $isCoreUpdate ? 'bg-linear-to-r from-violet-500/[0.12] dark:from-violet-500/[0.24] to-violet-500/[0.04]' : '' ?>">
                    <a class="block text-gray-800 dark:text-gray-100 truncate transition" href="/admin/core-update">
                        <div class="flex items-center">
                            <svg class="shrink-0 fill-current <?= $isCoreUpdate ? 'text-violet-500' : 'text-gray-400 dark:text-gray-500' ?>" width="16" height="16" viewBox="0 0 16 16">
                                <path d="M8 1 3 4v3c0 3 1.8 5.7 5 7 3.2-1.3 5-4 5-7V4L8 1Zm0 2.2 3 1.8V7c0 2-1.1 3.9-3 5-1.9-1.1-3-3-3-5V5l3-1.8ZM7 5h2v3h2L8 11 5 8h2V5Z"/>
                            </svg>
                            <span class="text-sm font-medium ml-4 lg:opacity-0 lg:sidebar-expanded:opacity-100 2xl:opacity-100 duration-200">Core Update</span>
                        </div>
                    </a>
                </li>

                <li class="pl-4 pr-3 py-2 rounded-lg mb-0.5 last:mb-0 <?= $isCoreRunners ? 'bg-linear-to-r from-violet-500/[0.12] dark:from-violet-500/[0.24] to-violet-500/[0.04]' : '' ?>">
                    <a class="block text-gray-800 dark:text-gray-100 truncate transition" href="/admin/core-runners">
                        <div class="flex items-center">
                            <svg class="shrink-0 fill-current <?= $isCoreRunners ? 'text-violet-500' : 'text-gray-400 dark:text-gray-500' ?>" width="16" height="16" viewBox="0 0 16 16">
                                <path d="M3 3h10a2 2 0 0 1 2 2v1H1V5a2 2 0 0 1 2-2Zm12 4v4a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V7h14ZM4 9v1h2V9H4Zm3 0v1h5V9H7Z"/>
                            </svg>
                            <span class="text-sm font-medium ml-4 lg:opacity-0 lg:sidebar-expanded:opacity-100 2xl:opacity-100 duration-200">Core Runners</span>
                        </div>
                    </a>
                </li>

                <li class="pl-4 pr-3 py-2 rounded-lg mb-0.5 last:mb-0 <?= $isSettings ? 'bg-linear-to-r from-violet-500/[0.12] dark:from-violet-500/[0.24] to-violet-500/[0.04]' : '' ?>">
                    <a class="block text-gray-800 dark:text-gray-100 truncate transition" href="/admin/settings">
                        <div class="flex items-center">
                            <svg class="shrink-0 fill-current <?= $isSettings ? 'text-violet-500' : 'text-gray-400 dark:text-gray-500' ?>" width="16" height="16" viewBox="0 0 16 16">
                                <path d="M8 0a1 1 0 0 0-1 1v.6A5.99 5.99 0 0 0 3.1 3.7L2.6 3.4a1 1 0 0 0-1.4 1.4l.4.4A6 6 0 0 0 1 7H.5a1 1 0 0 0 0 2H1c.07.6.24 1.17.5 1.69l-.4.4a1 1 0 1 0 1.4 1.41l.4-.4A6 6 0 0 0 7 14.5v.5a1 1 0 0 0 2 0v-.5a6 6 0 0 0 3.6-1.6l.4.4a1 1 0 1 0 1.4-1.41l-.4-.4c.27-.52.44-1.09.5-1.69h.5a1 1 0 0 0 0-2H15a6 6 0 0 0-.6-2.3l.4-.4A1 1 0 0 0 13.4 3.4l-.5.3A5.99 5.99 0 0 0 9 1.6V1a1 1 0 0 0-1-1Zm0 4a3 3 0 1 1 0 6A3 3 0 0 1 8 4Z"/>
                            </svg>
                            <span class="text-sm font-medium ml-4 lg:opacity-0 lg:sidebar-expanded:opacity-100 2xl:opacity-100 duration-200">Settings</span>
                        </div>
                    </a>
                </li>

                <li class="pl-4 pr-3 py-2 rounded-lg mb-0.5 last:mb-0 <?= $isAppStore ? 'bg-linear-to-r from-violet-500/[0.12] dark:from-violet-500/[0.24] to-violet-500/[0.04]' : '' ?>">
                    <a class="block text-gray-800 dark:text-gray-100 truncate transition" href="/admin/app-store">
                        <div class="flex items-center">
                            <svg class="shrink-0 fill-current <?= $isAppStore ? 'text-violet-500' : 'text-gray-400 dark:text-gray-500' ?>" width="16" height="16" viewBox="0 0 16 16">
                                <path d="M1 2.5C1 1.67 1.67 1 2.5 1h11c.83 0 1.5.67 1.5 1.5v2c0 .55-.3 1.03-.74 1.29L13 11.5c0 .83-.67 1.5-1.5 1.5h-7A1.5 1.5 0 0 1 3 11.5L1.74 6.79A1.5 1.5 0 0 1 1 5.5v-3Zm1.5-.5a.5.5 0 0 0-.5.5v3c0 .17.09.32.22.41L3.5 11.59c.06.25.29.41.5.41h7c.21 0 .44-.16.5-.41l1.28-5.68c.13-.09.22-.24.22-.41v-3a.5.5 0 0 0-.5-.5h-11ZM6 7a2 2 0 1 1 4 0A2 2 0 0 1 6 7Z"/>
                            </svg>
                            <span class="text-sm font-medium ml-4 lg:opacity-0 lg:sidebar-expanded:opacity-100 2xl:opacity-100 duration-200">App Store</span>
                        </div>
                    </a>
                </li>

                <li class="pl-4 pr-3 py-2 rounded-lg mb-0.5 last:mb-0 <?= $isDanger ? 'bg-linear-to-r from-rose-500/[0.12] dark:from-rose-500/[0.24] to-rose-500/[0.04]' : '' ?>">
                    <a class="block text-gray-800 dark:text-gray-100 truncate transition" href="/admin/danger">
                        <div class="flex items-center">
                            <svg class="shrink-0 fill-current <?= $isDanger ? 'text-rose-500' : 'text-gray-400 dark:text-gray-500' ?>" width="16" height="16" viewBox="0 0 16 16">
                                <path d="M6 2h4a2 2 0 0 1 2 2v1h2v1h-1l-.857 8H3.857L3 6H2V5h2V4a2 2 0 0 1 2-2Zm1 2v1h2V4H7ZM4.143 6l.714 7h6.286l.714-7H4.143ZM6 7h1v5H6V7Zm3 0h1v5H9V7Z"/>
                            </svg>
                            <span class="text-sm font-medium ml-4 lg:opacity-0 lg:sidebar-expanded:opacity-100 2xl:opacity-100 duration-200">Danger Zone</span>
                        </div>
                    </a>
                </li>


                <li class="pl-4 pr-3 py-2 rounded-lg">
                    <a class="block text-gray-700 dark:text-gray-200 hover:text-gray-900 dark:hover:text-white truncate transition" href="/dashboard">
                        <div class="flex items-center">
                            <svg class="shrink-0 fill-current text-gray-400 dark:text-gray-500" width="16" height="16" viewBox="0 0 16 16">
                                <path d="M8 0a8 8 0 1 0 0 16A8 8 0 0 0 8 0ZM7 3h2v6H7V3Zm0 7h2v2H7v-2Z"/>
                            </svg>
                            <span class="text-sm font-medium ml-4 lg:opacity-0 lg:sidebar-expanded:opacity-100 2xl:opacity-100 duration-200">Dashboard</span>
                        </div>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</div>