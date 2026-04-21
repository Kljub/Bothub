<?php
declare(strict_types=1);

/**
 * Route definitions (data only)
 * Engine is in /routes/router.php
 *
 * IMPORTANT:
 * - Keep this file pure: return array only.
 */

return [
    'exact' => [
        // Installer
        '/install'                  => ['type' => 'install', 'file' => '/install/requirements.php'],
        '/install/requirements'     => ['type' => 'install', 'file' => '/install/requirements.php'],
        '/install/install'          => ['type' => 'install', 'file' => '/install/install.php'],
        '/install/account'          => ['type' => 'install', 'file' => '/install/account.php'],
        '/install/finish'           => ['type' => 'install', 'file' => '/install/finish.php'],

        // Auth
        '/auth/login'               => ['type' => 'auth', 'file' => '/auth/login.php'],
        '/auth/login.php'           => ['type' => 'auth', 'file' => '/auth/login.php'],
        '/auth/register'            => ['type' => 'auth', 'file' => '/auth/register.php'],
        '/auth/register.php'        => ['type' => 'auth', 'file' => '/auth/register.php'],
        '/auth/logout'              => ['type' => 'auth', 'file' => '/auth/logout.php'],
        '/auth/logout.php'          => ['type' => 'auth', 'file' => '/auth/logout.php'],

        // Dashboard
        '/dashboard'                        => ['type' => 'php', 'file' => '/dashboard/index.php'],
        '/dashboard/db_update'              => ['type' => 'php', 'file' => '/dashboard/db_update.php'],
        '/dashboard/custom-commands/builder'  => ['type' => 'php', 'file' => '/dashboard/_customcmd_builder.php'],
        '/dashboard/custom-events/builder'   => ['type' => 'php', 'file' => '/dashboard/_custom_events_builder.php'],
        '/dashboard/timed-events/builder'    => ['type' => 'php', 'file' => '/dashboard/_timed_events_builder.php'],

        // "Pretty" dashboard routes via router
        '/dashboard/settings'              => ['type' => 'dashboard_view', 'file' => '/dashboard/index.php', 'view' => 'settings'],
        '/dashboard/predefined-commands'   => ['type' => 'dashboard_view', 'file' => '/dashboard/index.php', 'view' => 'predefined-commands'],
        '/dashboard/custom-commands'       => ['type' => 'dashboard_view', 'file' => '/dashboard/index.php', 'view' => 'custom-commands'],
        '/dashboard/custom-events'         => ['type' => 'dashboard_view', 'file' => '/dashboard/index.php', 'view' => 'custom-events'],
        '/dashboard/plex'                  => ['type' => 'dashboard_view', 'file' => '/dashboard/index.php', 'view' => 'plex'],
        '/dashboard/music'                 => ['type' => 'dashboard_view', 'file' => '/dashboard/index.php', 'view' => 'music'],
        '/dashboard/economy-minigames'     => ['type' => 'dashboard_view', 'file' => '/dashboard/index.php', 'view' => 'economy-minigames'],
        '/dashboard/pokemia'               => ['type' => 'dashboard_view', 'file' => '/dashboard/index.php', 'view' => 'pokemia'],
        '/dashboard/leveling'              => ['type' => 'dashboard_view', 'file' => '/dashboard/index.php', 'view' => 'leveling'],
        '/dashboard/giveaways'             => ['type' => 'dashboard_view', 'file' => '/dashboard/index.php', 'view' => 'giveaways'],
        '/dashboard/plex/connect'          => ['type' => 'php', 'file' => '/functions/plex/plex_connect.php'],
        '/dashboard/plex/callback'         => ['type' => 'php', 'file' => '/functions/plex/plex_callback.php'],
        '/dashboard/plex/disconnect'       => ['type' => 'php', 'file' => '/functions/plex/plex_disconnect.php'],
        '/dashboard/plex/libraries/save'    => ['type' => 'php', 'file' => '/functions/plex/plex_save_libraries.php'],
        '/dashboard/plex/command/toggle'    => ['type' => 'php', 'file' => '/functions/plex/plex_toggle_command.php'],

        // Admin
        '/admin'                    => ['type' => 'php', 'file' => '/admin/index.php'],
        '/admin/migrations'         => ['type' => 'php', 'file' => '/admin/migrations.php'],
        '/admin/core-check'         => ['type' => 'php', 'file' => '/admin/core_check.php'],
        '/admin/core-update'        => ['type' => 'php', 'file' => '/admin/core_update.php'],
        '/admin/download-core'      => ['type' => 'php', 'file' => '/admin/download_core.php'],
        '/admin/core-runners'       => ['type' => 'php', 'file' => '/admin/core_runners.php'],
        '/admin/settings'           => ['type' => 'php', 'file' => '/admin/settings.php'],
        '/admin/app-store'          => ['type' => 'php', 'file' => '/admin/app_store.php'],
        '/admin/danger'             => ['type' => 'php', 'file' => '/admin/danger.php'],
        '/admin/project-builder'    => ['type' => 'php', 'file' => '/admin/project_builder.php'],

        // Landing (after installation)
        '/'                         => ['type' => 'landing'],
    ],
];