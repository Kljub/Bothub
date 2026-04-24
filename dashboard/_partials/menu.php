<?php
declare(strict_types=1);

$settingsHref = '/dashboard/settings';
if ($currentBotId !== null && $currentBotId > 0) {
    $settingsHref .= '?bot_id=' . $currentBotId;
}

// Load installed apps for dynamic sidebar section
$_installedApps = [];
try {
    $_appPdo  = bh_get_pdo();
    $_appStmt = $_appPdo->prepare("
        SELECT a.app_key, a.name, a.icon_svg, a.sidebar_view
        FROM apps a
        INNER JOIN installed_apps i ON a.app_key = i.app_key
        WHERE i.status = 'active' AND a.sidebar_view != ''
        ORDER BY a.is_official DESC, a.name ASC
    ");
    $_appStmt->execute();
    $_installedApps = $_appStmt->fetchAll();
} catch (Throwable) {
    $_installedApps = [];
}

$settingsItems = [
    [
        'label' => 'Settings',
        'type' => 'custom',
        'href' => $settingsHref,
        'active' => ($view === 'settings'),
        'icon' => '<path d="M9.4 1 10 3.1c.2.1.4.2.6.3l2-.8 1 1.7-1.6 1.4c0 .2.1.4.1.6s0 .4-.1.6L13 9.3l-1 1.7-2-.8c-.2.1-.4.2-.6.3L9.4 12H7.6L7 9.9c-.2-.1-.4-.2-.6-.3l-2 .8-1-1.7L5 7.3C5 7.1 5 6.9 5 6.7s0-.4.1-.6L3.4 4.7l1-1.7 2 .8c.2-.1.4-.2.6-.3L7.6 1h1.8ZM8.5 6.7A1.5 1.5 0 1 0 7 8.2a1.5 1.5 0 0 0 1.5-1.5Z"/>',
    ],
    [
        'label' => 'Invite',
        'type' => 'view',
        'view' => 'invite',
        'icon' => '<path d="M8 1a3 3 0 1 1-2.995 3.176L5 4a3 3 0 0 1 3-3Zm0 6c2.761 0 5 1.567 5 3.5V12H3v-1.5C3 8.567 5.239 7 8 7Z"/>',
    ],
    [
        'label' => 'Servers',
        'type' => 'view',
        'view' => 'servers',
        'icon' => '<path d="M2 3h12v3H2V3Zm0 4h12v6H2V7Zm2 1v1h2V8H4Zm0 2v1h5v-1H4Z"/>',
    ],
    [
        'label' => 'Status',
        'type' => 'view',
        'view' => 'status',
        'icon' => '<path d="M8 2a6 6 0 1 0 6 6A6 6 0 0 0 8 2Zm0 2a4 4 0 1 1-4 4 4 4 0 0 1 4-4Zm0 1.5a2.5 2.5 0 1 0 2.5 2.5A2.5 2.5 0 0 0 8 5.5Z"/>',
    ],
    [
        'label' => 'Logs',
        'type' => 'view',
        'view' => 'logs',
        'icon' => '<path d="M3 2h10a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1Zm1 3v1h8V5H4Zm0 3v1h8V8H4Zm0 3v1h5v-1H4Z"/>',
    ],
];

$messagesItems = [
    ['label' => 'Reaction Roles', 'view' => 'reaction-roles'],
    ['label' => 'Polls', 'view' => 'polls'],
    ['label' => 'Timed Messages', 'view' => 'timed-messages'],
    ['label' => 'Autoresponds', 'view' => 'autoresponds'],
    ['label' => 'Predefined Commands', 'view' => 'predefined-commands'],
    ['label' => 'Autoreact', 'view' => 'autoreact'],
    ['label' => 'Welcomer', 'view' => 'welcomer'],
    ['label' => 'Sticky Messages', 'view' => 'sticky-messages'],
];

$moderationItems = [
    ['label' => 'Moderation', 'view' => 'moderation'],
    ['label' => 'Discord Automod', 'view' => 'discord-automod'],
    ['label' => 'Statistic Channels', 'view' => 'statistic-channels'],
];

$customizationItems = [
    ['label' => 'Custom Commands', 'view' => 'custom-commands'],
    ['label' => 'Custom Events', 'view' => 'custom-events'],
    ['label' => 'Message Builder', 'view' => 'message-builder'],
    ['label' => 'Timed Events', 'view' => 'timed-events'],
    ['label' => 'Data Storage', 'view' => 'data-storage'],
    ['label' => 'Webhooks', 'view' => 'webhooks'],
];

$funItems = [
    ['label' => 'Leveling', 'view' => 'leveling'],
    ['label' => 'Starboard', 'view' => 'starboard'],
    ['label' => 'Temp Voice Channel', 'view' => 'temp-voice-channel'],
    ['label' => 'Invite Tracker', 'view' => 'invite-tracker'],
    ['label' => 'Suggestion', 'view' => 'suggestion'],
    ['label' => 'Counting', 'view' => 'counting'],
    ['label' => 'Economy & Minigames', 'view' => 'economy-minigames'],
    ['label' => 'Birthday', 'view' => 'birthday'],
    ['label' => 'Weather', 'view' => 'weather'],
];

$ticketsItems = [
    [
        'label' => 'Ticket System',
        'view'  => 'tickets',
        'icon'  => '<path d="M13 3H3a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h1v2l3-2h6a1 1 0 0 0 1-1V4a1 1 0 0 0-1-1Zm-1 8H6.5L5 12v-1H4V5h8v6Z"/>',
    ],
];

$socialItems = [
    ['label' => 'Twitch Notification', 'view' => 'twitch-notification'],
    ['label' => 'Kick Notifikation', 'view' => 'kick-notification'],
    ['label' => 'Youtube Notification', 'view' => 'youtube-notification'],
    ['label' => 'Free Games', 'view' => 'free-games'],
];

// Build the installed-apps sidebar items (view link with bot_id)
$appSidebarItems = [];
foreach ($_installedApps as $_app) {
    $appSidebarItems[] = [
        'label'   => (string)$_app['name'],
        'view'    => (string)$_app['sidebar_view'],
        'icon'    => (string)$_app['icon_svg'],
        'app_key' => (string)$_app['app_key'],
    ];
}
?>