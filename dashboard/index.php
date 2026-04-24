<?php
declare(strict_types=1);
session_start();

if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    header('Location: /?auth=login', true, 302);
    exit;
}

$pageTitle = 'Dashboard';
$extraCssFiles = [];

$userId = (int)$_SESSION['user_id'];

$displayName = trim((string)($_SESSION['user_name'] ?? ''));
if ($displayName === '') {
    $displayName = trim((string)($_SESSION['user_email'] ?? ''));
}
if ($displayName === '') {
    $displayName = 'User';
}

require_once __DIR__ . '/../functions/db_functions/db.php';

$sidebarBots = [];
$botLoadError = null;

try {
    $sidebarBots = bh_load_user_bots($userId);
} catch (Throwable $e) {
    $botLoadError = $e->getMessage();
    $sidebarBots = [];
}

$view = (string)($_GET['view'] ?? '');
$currentBotId = null;

if (isset($_GET['bot_id']) && is_numeric($_GET['bot_id'])) {
    $currentBotId = (int)$_GET['bot_id'];
}

if (count($sidebarBots) === 0 && $view !== 'create-bot') {
    header('Location: /dashboard?view=create-bot', true, 302);
    exit;
}

if (count($sidebarBots) > 0 && ($currentBotId === null || $currentBotId <= 0)) {
    $currentBotId = (int)$sidebarBots[0]['id'];
}

// ── Global guild context (first guild as default; modules override via channel picker) ──
$currentGuildId = '';
if ($currentBotId > 0) {
    try {
        $gStmt = bh_get_pdo()->prepare(
            'SELECT guild_id FROM bot_guilds WHERE bot_id = ? ORDER BY id LIMIT 1'
        );
        $gStmt->execute([$currentBotId]);
        $currentGuildId = (string)($gStmt->fetchColumn() ?? '');
    } catch (Throwable) {}
}

if ($view === 'settings') {
    $pageTitle = 'Settings';
} elseif ($view === 'invite') {
    $pageTitle = 'Invite';
} elseif ($view === 'servers') {
    $pageTitle = 'Servers';
} elseif ($view === 'moderation') {
    $pageTitle = 'Moderation';
} elseif ($view === 'predefined-commands') {
    $pageTitle = 'Predefined Commands';
} elseif ($view === 'custom-commands') {
    $pageTitle = 'Custom Commands';
    $extraCssFiles[] = '/assets/css/_customcmd.css';
} elseif ($view === 'custom-events') {
    $pageTitle = 'Custom Events';
    $extraCssFiles[] = '/assets/css/_custom_events.css';
} elseif ($view === 'plex') {
    $pageTitle = 'Plex';
    $extraCssFiles[] = '/assets/css/plex/_plex.css';
} elseif ($view === 'webhooks') {
    $pageTitle = 'Webhooks';
    $extraCssFiles[] = '/assets/css/_webhook.css';
} elseif ($view === 'welcomer') {
    $pageTitle = 'Welcomer';
    $extraCssFiles[] = '/assets/css/_welcomer.css';
} elseif ($view === 'music') {
    $pageTitle = 'Music';
    $extraCssFiles[] = '/assets/css/_music.css';
    $extraCssFiles[] = '/assets/css/_invite_tracker.css';
} elseif ($view === 'youtube-auth') {
    $pageTitle = 'YouTube Auth';
} elseif ($view === 'soundboard') {
    $pageTitle = 'Soundboard';
    $extraCssFiles[] = '/assets/css/_soundboard.css';
} elseif ($view === 'status') {
    $pageTitle = 'Status';
} elseif ($view === 'logs') {
    $pageTitle = 'Logs';
} elseif ($view === 'economy-minigames') {
    $pageTitle = 'Economy & Minigames';
} elseif ($view === 'pokemia') {
    $pageTitle = 'Pokemia';
    $extraCssFiles[] = '/assets/css/_invite_tracker.css';
} elseif ($view === 'leveling') {
    $pageTitle = 'Leveling';
    $extraCssFiles[] = '/assets/css/_leveling.css';
} elseif ($view === 'reaction-roles') {
    $pageTitle = 'Reaction Roles';
    $extraCssFiles[] = '/assets/css/_reaction_roles.css';
} elseif ($view === 'polls') {
    $pageTitle = 'Polls';
    $extraCssFiles[] = '/assets/css/_polls.css';
} elseif ($view === 'timed-messages') {
    $pageTitle = 'Timed Messages';
    $extraCssFiles[] = '/assets/css/_timed_messages.css';
    $extraCssFiles[] = '/assets/css/_invite_tracker.css';
} elseif ($view === 'autoresponds') {
    $pageTitle = 'Autoresponder';
    $extraCssFiles[] = '/assets/css/_autoresponder.css';
} elseif ($view === 'autoreact') {
    $pageTitle = 'Auto React';
    $extraCssFiles[] = '/assets/css/_auto_react.css';
} elseif ($view === 'sticky-messages') {
    $pageTitle = 'Sticky Messages';
    $extraCssFiles[] = '/assets/css/_sticky_messages.css';
} elseif ($view === 'statistic-channels') {
    $pageTitle = 'Statistic Channels';
    $extraCssFiles[] = '/assets/css/_statistic_channels.css';
} elseif ($view === 'data-storage') {
    $pageTitle = 'Data Storage';
    $extraCssFiles[] = '/assets/css/_data_storage.css';
} elseif ($view === 'discord-automod') {
    $pageTitle = 'Discord Automod';
    $extraCssFiles[] = '/assets/css/_discord_automod.css';
    $extraCssFiles[] = '/assets/css/_invite_tracker.css';
} elseif ($view === 'giveaways') {
    $pageTitle = 'Giveaways';
    $extraCssFiles[] = '/assets/css/_giveaways.css';
} elseif ($view === 'counting') {
    $pageTitle = 'Counting';
    $extraCssFiles[] = '/assets/css/_giveaways.css';
    $extraCssFiles[] = '/assets/css/_invite_tracker.css';
} elseif ($view === 'verification') {
    $pageTitle = 'Verification';
    $extraCssFiles[] = '/assets/css/_giveaways.css';
    $extraCssFiles[] = '/assets/css/_invite_tracker.css';
} elseif ($view === 'ai') {
    $pageTitle = 'AI Chat';
    $extraCssFiles[] = '/assets/css/_giveaways.css';
} elseif ($view === 'arcenciel') {
    $pageTitle = 'Arc en Ciel';
    $extraCssFiles[] = '/assets/css/_giveaways.css';
} elseif ($view === 'starboard') {
    $pageTitle = 'Starboard';
    $extraCssFiles[] = '/assets/css/_giveaways.css';
    $extraCssFiles[] = '/assets/css/_invite_tracker.css';
} elseif ($view === 'suggestion') {
    $pageTitle = 'Suggestion';
    $extraCssFiles[] = '/assets/css/_giveaways.css';
    $extraCssFiles[] = '/assets/css/_invite_tracker.css';
} elseif ($view === 'invite-tracker') {
    $pageTitle = 'Invite Tracker';
    $extraCssFiles[] = '/assets/css/_invite_tracker.css';
} elseif ($view === 'twitch-notification') {
    $pageTitle = 'Twitch Notifications';
    $extraCssFiles[] = '/assets/css/_twitch_notifications.css';
} elseif ($view === 'kick-notification') {
    $pageTitle = 'Kick Notifications';
    $extraCssFiles[] = '/assets/css/_kick_notifications.css';
} elseif ($view === 'youtube-notification') {
    $pageTitle = 'YouTube Notifications';
    $extraCssFiles[] = '/assets/css/_youtube_notifications.css';
} elseif ($view === 'free-games') {
    $pageTitle = 'Free Games';
    $extraCssFiles[] = '/assets/css/_free_games.css';
} elseif ($view === 'weather') {
    $pageTitle = 'Weather';
    $extraCssFiles[] = '/assets/css/_weather.css';
} elseif ($view === 'birthday') {
    $pageTitle = 'Birthday';
    $extraCssFiles[] = '/assets/css/_birthday.css';
} elseif ($view === 'tickets') {
    $pageTitle = 'Ticket System';
    $extraCssFiles[] = '/assets/css/_tickets.css';
    $extraCssFiles[] = '/assets/css/_invite_tracker.css';
} elseif ($view === 'temp-voice-channel') {
    $pageTitle = 'Temp Voice Channel';
    $extraCssFiles[] = '/assets/css/_temp_voice.css';
} elseif ($view === 'timed-events') {
    $pageTitle = 'Timed Events';
    $extraCssFiles[] = '/assets/css/_timed_events.css';
} elseif ($view === 'message-builder') {
    $pageTitle = 'Message Builder';
    $extraCssFiles[] = '/assets/css/_message_builder.css';
} elseif ($view === 'create-bot') {
    $pageTitle = 'Bot erstellen';
} else {
    $pageTitle = 'Dashboard';
$extraCssFiles = [];
}

ob_start();
?>
<main class="grow">
    <div class="px-4 sm:px-6 lg:px-8 py-8 w-full max-w-9xl mx-auto">

        <div class="mb-8">
            <h1 class="text-2xl md:text-3xl text-gray-800 dark:text-gray-100 font-bold">
                Willkommen <?= htmlspecialchars($displayName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> 👋
            </h1>
        </div>


        <?php if ($view === 'create-bot'): ?>

            <?php require __DIR__ . '/_add_bot.php'; ?>

        <?php elseif ($view === 'settings'): ?>

            <?php require __DIR__ . '/_page_settings.php'; ?>

        <?php elseif ($view === 'invite'): ?>

            <?php require __DIR__ . '/_invitebot.php'; ?>

        <?php elseif ($view === 'servers'): ?>

            <?php require __DIR__ . '/_serversbot.php'; ?>

        <?php elseif ($view === 'moderation'): ?>

            <?php require __DIR__ . '/_botmoderation.php'; ?>

        <?php elseif ($view === 'predefined-commands'): ?>

            <?php require __DIR__ . '/_predefined_commands.php'; ?>

        <?php elseif ($view === 'custom-commands'): ?>

            <?php require __DIR__ . '/_customcmd.php'; ?>

        <?php elseif ($view === 'custom-events'): ?>

            <?php require __DIR__ . '/_custom_events.php'; ?>

        <?php elseif ($view === 'plex'): ?>

            <?php require __DIR__ . '/_plex.php'; ?>

        <?php elseif ($view === 'webhooks'): ?>

            <?php require __DIR__ . '/_webhook.php'; ?>

        <?php elseif ($view === 'welcomer'): ?>

            <?php require __DIR__ . '/_welcomer.php'; ?>

        <?php elseif ($view === 'music'): ?>

            <?php require __DIR__ . '/_music.php'; ?>

        <?php elseif ($view === 'youtube-auth'): ?>

            <?php require __DIR__ . '/_youtube_auth.php'; ?>

        <?php elseif ($view === 'soundboard'): ?>

            <?php require __DIR__ . '/_soundboard.php'; ?>

        <?php elseif ($view === 'status'): ?>

            <?php require __DIR__ . '/_status.php'; ?>

        <?php elseif ($view === 'logs'): ?>

            <?php require __DIR__ . '/_logs.php'; ?>

        <?php elseif ($view === 'economy-minigames'): ?>

            <?php require __DIR__ . '/_economy_minigames.php'; ?>

        <?php elseif ($view === 'pokemia'): ?>

            <?php require __DIR__ . '/_pokemia.php'; ?>

        <?php elseif ($view === 'reaction-roles'): ?>

            <?php require __DIR__ . '/_reaction_roles.php'; ?>

        <?php elseif ($view === 'polls'): ?>

            <?php require __DIR__ . '/_polls.php'; ?>

        <?php elseif ($view === 'timed-messages'): ?>

            <?php require __DIR__ . '/_timed_messages.php'; ?>

        <?php elseif ($view === 'autoresponds'): ?>

            <?php require __DIR__ . '/_autoresponder.php'; ?>

        <?php elseif ($view === 'autoreact'): ?>

            <?php require __DIR__ . '/_auto_react.php'; ?>

        <?php elseif ($view === 'sticky-messages'): ?>

            <?php require __DIR__ . '/_sticky_messages.php'; ?>

        <?php elseif ($view === 'statistic-channels'): ?>

            <?php require __DIR__ . '/_statistic_channels.php'; ?>

        <?php elseif ($view === 'data-storage'): ?>

            <?php require __DIR__ . '/_data_storage.php'; ?>

        <?php elseif ($view === 'discord-automod'): ?>

            <?php require __DIR__ . '/_discord_automod.php'; ?>

        <?php elseif ($view === 'invite-tracker'): ?>

            <?php require __DIR__ . '/_invite_tracker.php'; ?>

        <?php elseif ($view === 'twitch-notification'): ?>

            <?php require __DIR__ . '/_twitch_notifications.php'; ?>

        <?php elseif ($view === 'kick-notification'): ?>

            <?php require __DIR__ . '/_kick_notifications.php'; ?>

        <?php elseif ($view === 'youtube-notification'): ?>

            <?php require __DIR__ . '/_youtube_notifications.php'; ?>

        <?php elseif ($view === 'free-games'): ?>

            <?php require __DIR__ . '/_free_games.php'; ?>

        <?php elseif ($view === 'weather'): ?>

            <?php require __DIR__ . '/_weather.php'; ?>

        <?php elseif ($view === 'birthday'): ?>

            <?php require __DIR__ . '/_birthday.php'; ?>

        <?php elseif ($view === 'tickets'): ?>

            <?php require __DIR__ . '/_tickets.php'; ?>

        <?php elseif ($view === 'temp-voice-channel'): ?>

            <?php require __DIR__ . '/_temp_voice.php'; ?>

        <?php elseif ($view === 'timed-events'): ?>

            <?php require __DIR__ . '/_timed_events.php'; ?>

        <?php elseif ($view === 'message-builder'): ?>

            <?php require __DIR__ . '/_message_builder.php'; ?>

        <?php elseif ($view !== ''): ?>

            <?php
            // Generic app view fallback: try _app_{view}.php
            $appViewFile = __DIR__ . '/_app_' . preg_replace('/[^a-z0-9_\-]/', '', strtolower($view)) . '.php';
            if (is_file($appViewFile)):
            ?>
                <?php require $appViewFile; ?>
            <?php else: ?>
                <div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl p-8 text-center text-gray-400 dark:text-gray-500">
                    <div class="text-4xl mb-3">📦</div>
                    <div class="font-semibold text-gray-600 dark:text-gray-300 mb-1">App-View nicht gefunden</div>
                    <div class="text-sm">Für die App <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded"><?= htmlspecialchars($view, ENT_QUOTES, 'UTF-8') ?></code> wurde keine Dashboard-Seite gefunden.<br>
                    Lege die Datei <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">dashboard/_app_<?= htmlspecialchars(preg_replace('/[^a-z0-9_\-]/', '', strtolower($view)), ENT_QUOTES, 'UTF-8') ?>.php</code> an.</div>
                </div>
            <?php endif; ?>

        <?php else: ?>

            <div class="grid grid-cols-12 gap-6">
                <div class="col-span-full sm:col-span-6 xl:col-span-4 bg-white dark:bg-gray-800 shadow-xs rounded-xl">
                    <div class="p-5">
                        <div class="flex items-center justify-between">
                            <header>
                                <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Bots</h2>
                                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Platzhalter (kommt als nächstes)</div>
                            </header>
                            <div class="w-10 h-10 rounded-full bg-violet-100 dark:bg-violet-500/20 flex items-center justify-center">
                                <svg class="w-5 h-5 fill-current text-violet-600 dark:text-violet-400" viewBox="0 0 24 24">
                                    <path d="M12 2a7 7 0 0 0-7 7v2a3 3 0 0 0 3 3h1v2H7a3 3 0 0 0-3 3v1h16v-1a3 3 0 0 0-3-3h-2v-2h1a3 3 0 0 0 3-3V9a7 7 0 0 0-7-7Zm-5 9V9a5 5 0 0 1 10 0v2a1 1 0 0 1-1 1h-1V9h-2v3h-2V9H9v3H8a1 1 0 0 1-1-1Z"/>
                                </svg>
                            </div>
                        </div>
                        <div class="mt-4">
                            <div class="text-3xl font-bold text-gray-800 dark:text-gray-100">—</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Zählt später deine Projekte/Bots</div>
                        </div>
                    </div>
                </div>

                <div class="col-span-full xl:col-span-8 bg-white dark:bg-gray-800 shadow-xs rounded-xl">
                    <div class="p-5 border-b border-gray-100 dark:border-gray-700/60">
                        <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Bot Aktivität</h2>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            Erreichbarkeit / Commands / Errors (5-Minuten Buckets)
                        </div>
                    </div>
                    <div class="p-5">
                        <div class="h-64">
                            <canvas id="dashboard-card-01"></canvas>
                        </div>
                    </div>
                </div>
            </div>

        <?php endif; ?>

    </div>
</main>
<?php
$contentHtml = (string)ob_get_clean();

require __DIR__ . '/_layout.php';