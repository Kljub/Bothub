<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/functions/custom_events.php';

if (!isset($_SESSION) || !is_array($_SESSION)) {
    session_start();
}

$userId       = isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$currentBotId = isset($currentBotId) && is_int($currentBotId) ? $currentBotId : 0;

if (!function_exists('bh_ce_partial_redirect')) {
    function bh_ce_partial_redirect(string $url): void
    {
        header('Location: ' . $url, true, 302);
        exit;
    }
}

if (!function_exists('bh_ce_partial_url')) {
    function bh_ce_partial_url(int $botId): string
    {
        return '/dashboard?view=custom-events&bot_id=' . $botId;
    }
}

if (!isset($_SESSION['bh_ce_csrf']) || !is_string($_SESSION['bh_ce_csrf']) || $_SESSION['bh_ce_csrf'] === '') {
    $_SESSION['bh_ce_csrf'] = bin2hex(random_bytes(32));
}

$flashError   = null;
$flashSuccess = null;

if (isset($_SESSION['bh_ce_flash_error']) && is_string($_SESSION['bh_ce_flash_error'])) {
    $flashError = $_SESSION['bh_ce_flash_error'];
    unset($_SESSION['bh_ce_flash_error']);
}

if (isset($_SESSION['bh_ce_flash_success']) && is_string($_SESSION['bh_ce_flash_success'])) {
    $flashSuccess = $_SESSION['bh_ce_flash_success'];
    unset($_SESSION['bh_ce_flash_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bh_ce_action'])) {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)$_SESSION['bh_ce_csrf'], $csrf)) {
        $_SESSION['bh_ce_flash_error'] = 'Ungültiges CSRF-Token.';
        bh_ce_partial_redirect(bh_ce_partial_url($currentBotId));
    }

    $action = (string)($_POST['bh_ce_action'] ?? '');

    if ($action === 'delete_event') {
        $eventId = isset($_POST['event_id']) && is_numeric($_POST['event_id']) ? (int)$_POST['event_id'] : 0;
        $result  = bh_ce_delete_custom_event($userId, $eventId);

        if (!($result['ok'] ?? false)) {
            $_SESSION['bh_ce_flash_error'] = (string)($result['error'] ?? 'Das Event konnte nicht gelöscht werden.');
        } else {
            $_SESSION['bh_ce_flash_success'] = 'Custom Event wurde gelöscht.';
        }

        bh_ce_partial_redirect(bh_ce_partial_url($currentBotId));
    }
}

$events     = [];
$eventsError = null;

try {
    if ($userId > 0 && $currentBotId > 0) {
        $events = bh_ce_list_custom_events($userId, $currentBotId);
    }
} catch (Throwable $e) {
    $eventsError = $e->getMessage();
}

$eventsTableReady  = false;
$builderTableReady = false;

try {
    $eventsTableReady  = bh_ce_events_table_ready();
    $builderTableReady = bh_ce_builder_table_ready();
} catch (Throwable $e) {
    $eventsError = $e->getMessage();
}

// Group events
$grouped   = []; // ['Group A' => [...], '' => [...]]
$ungrouped = [];
foreach ($events as $evt) {
    $g = trim((string)($evt['group_name'] ?? ''));
    if ($g !== '') {
        $grouped[$g][] = $evt;
    } else {
        $ungrouped[] = $evt;
    }
}
ksort($grouped);
$csrfToken = h((string)$_SESSION['bh_ce_csrf']);

// ── Event type definitions ────────────────────────────────────────────────────
$ceEventTypes = [
    'reaction'       => ['reaction.add','reaction.remove','reaction.remove_all','reaction.remove_emoji'],
    'role'           => ['role.create','role.update','role.delete'],
    'scheduled_event'=> ['scheduled_event.create','scheduled_event.update','scheduled_event.delete','scheduled_event.user_add','scheduled_event.user_remove'],
    'stage'          => ['stage.create','stage.update','stage.delete'],
    'sticker'        => ['sticker.create','sticker.update','sticker.delete'],
    'thread'         => ['thread.create','thread.update','thread.delete','thread.member_add','thread.member_remove','thread.list_sync','thread.members_update'],
    'webhook'        => ['webhook.update'],
    'boost'          => ['boost.first','boost.remove','boost.level_up','boost.level_down'],
    'bot'            => ['bot.guild_join','bot.guild_leave','bot.ready','bot.variable_change','bot.dashboard_update'],
    'channel'        => ['channel.create','channel.update','channel.delete','channel.permissions_update','channel.topic_update','channel.pins_update'],
    'invite'         => ['invite.create','invite.delete'],
    'member'         => ['member.join','member.ban','member.unban','member.leave','member.role_add','member.role_remove','member.update','member.nickname_change','member.status_change'],
    'guild'          => ['guild.features_update','guild.ownership_change','guild.partner_add','guild.partner_remove','guild.name_change','guild.afk_set','guild.afk_remove','guild.banner_add','guild.banner_remove','guild.screening_pass','guild.integrations_update'],
    'message'        => ['message.create','message.update','message.delete','message.pin','message.typing'],
    'music'          => ['music.track_start','music.track_finish','music.track_skip','music.track_error','music.track_stuck','music.playback_start','music.playback_pause','music.playback_resume','music.playback_stop','music.queue_add','music.queue_remove','music.queue_finish','music.queue_shuffle','music.autoplay_toggle','music.autoleave_toggle','music.seek','music.filter_change','music.volume_change','music.loop_change','music.connect','music.disconnect','music.move','music.player_create','music.player_destroy','music.player_node_switch','music.player_ws_close','music.mute_change','music.deaf_change','music.user_join_vc','music.user_leave_vc'],
    'audit'          => ['audit.entry_create'],
    'automod'        => ['automod.action','automod.rule_create','automod.rule_delete','automod.rule_update'],
];

$ceEventLabels = [
    'reaction.add'                 => 'Reaction hinzugefügt',
    'reaction.remove'              => 'Reaction entfernt',
    'reaction.remove_all'          => 'Alle Reactions entfernt',
    'reaction.remove_emoji'        => 'Reactions für Emoji entfernt',
    'role.create'                  => 'Rolle erstellt',
    'role.update'                  => 'Rolle aktualisiert',
    'role.delete'                  => 'Rolle gelöscht',
    'scheduled_event.create'       => 'Termin erstellt',
    'scheduled_event.update'       => 'Termin aktualisiert',
    'scheduled_event.delete'       => 'Termin gelöscht',
    'scheduled_event.user_add'     => 'Nutzer tritt Termin bei',
    'scheduled_event.user_remove'  => 'Nutzer verlässt Termin',
    'stage.create'                 => 'Stage erstellt',
    'stage.update'                 => 'Stage aktualisiert',
    'stage.delete'                 => 'Stage gelöscht',
    'sticker.create'               => 'Sticker erstellt',
    'sticker.update'               => 'Sticker aktualisiert',
    'sticker.delete'               => 'Sticker gelöscht',
    'thread.create'                => 'Thread erstellt',
    'thread.update'                => 'Thread aktualisiert',
    'thread.delete'                => 'Thread gelöscht',
    'thread.member_add'            => 'Nutzer tritt Thread bei',
    'thread.member_remove'         => 'Nutzer verlässt Thread',
    'thread.list_sync'             => 'Thread-Liste synchronisiert',
    'thread.members_update'        => 'Thread-Mitglieder aktualisiert',
    'webhook.update'               => 'Webhook aktualisiert',
    'boost.first'                  => 'Erster Server-Boost',
    'boost.remove'                 => 'Boost entfernt',
    'boost.level_up'               => 'Boost-Level erhöht',
    'boost.level_down'             => 'Boost-Level verringert',
    'bot.guild_join'               => 'Bot tritt Server bei',
    'bot.guild_leave'              => 'Bot verlässt Server',
    'bot.ready'                    => 'Bot gestartet/neugestartet',
    'bot.variable_change'          => 'Variable geändert',
    'bot.dashboard_update'         => 'Dashboard-Update',
    'channel.create'               => 'Kanal erstellt',
    'channel.update'               => 'Kanal aktualisiert',
    'channel.delete'               => 'Kanal gelöscht',
    'channel.permissions_update'   => 'Kanal-Rechte aktualisiert',
    'channel.topic_update'         => 'Kanal-Thema aktualisiert',
    'channel.pins_update'          => 'Pins aktualisiert',
    'invite.create'                => 'Einladung erstellt',
    'invite.delete'                => 'Einladung gelöscht',
    'member.join'                  => 'Nutzer tritt bei',
    'member.ban'                   => 'Nutzer gebannt',
    'member.unban'                 => 'Nutzer entbannt',
    'member.leave'                 => 'Nutzer verlässt/gekickt',
    'member.role_add'              => 'Rolle hinzugefügt',
    'member.role_remove'           => 'Rolle entfernt',
    'member.update'                => 'Mitglied aktualisiert',
    'member.nickname_change'       => 'Spitzname geändert',
    'member.status_change'         => 'Status geändert',
    'guild.features_update'        => 'Server-Features aktualisiert',
    'guild.ownership_change'       => 'Server-Besitz geändert',
    'guild.partner_add'            => 'Server-Partnerschaft',
    'guild.partner_remove'         => 'Partnerschaft beendet',
    'guild.name_change'            => 'Server-Name geändert',
    'guild.afk_set'                => 'AFK-Kanal gesetzt',
    'guild.afk_remove'             => 'AFK-Kanal entfernt',
    'guild.banner_add'             => 'Banner hinzugefügt',
    'guild.banner_remove'          => 'Banner entfernt',
    'guild.screening_pass'         => 'Mitgliedschaftsprüfung bestanden',
    'guild.integrations_update'    => 'Integrationen aktualisiert',
    'message.create'               => 'Nachricht gesendet',
    'message.update'               => 'Nachricht bearbeitet',
    'message.delete'               => 'Nachricht gelöscht',
    'message.pin'                  => 'Nachricht angeheftet',
    'message.typing'               => 'Nutzer tippt',
    'music.track_start'            => 'Track startet',
    'music.track_finish'           => 'Track beendet',
    'music.track_skip'             => 'Track übersprungen',
    'music.track_error'            => 'Track-Fehler',
    'music.track_stuck'            => 'Track hängt',
    'music.playback_start'         => 'Wiedergabe gestartet',
    'music.playback_pause'         => 'Wiedergabe pausiert',
    'music.playback_resume'        => 'Wiedergabe fortgesetzt',
    'music.playback_stop'          => 'Wiedergabe gestoppt',
    'music.queue_add'              => 'Track zur Warteschlange',
    'music.queue_remove'           => 'Track aus Warteschlange',
    'music.queue_finish'           => 'Warteschlange beendet',
    'music.queue_shuffle'          => 'Warteschlange gemischt',
    'music.autoplay_toggle'        => 'AutoPlay umgeschaltet',
    'music.autoleave_toggle'       => 'AutoLeave umgeschaltet',
    'music.seek'                   => 'Suche',
    'music.filter_change'          => 'Filter geändert',
    'music.volume_change'          => 'Lautstärke geändert',
    'music.loop_change'            => 'Wiederholung geändert',
    'music.connect'                => 'Bot verbindet Voicekanal',
    'music.disconnect'             => 'Bot trennt Voicekanal',
    'music.move'                   => 'Bot bewegt sich',
    'music.player_create'          => 'Player erstellt',
    'music.player_destroy'         => 'Player zerstört',
    'music.player_node_switch'     => 'Node-Wechsel',
    'music.player_ws_close'        => 'WebSocket geschlossen',
    'music.mute_change'            => 'Stummschaltung geändert',
    'music.deaf_change'            => 'Taubschaltung geändert',
    'music.user_join_vc'           => 'Nutzer betritt Voice',
    'music.user_leave_vc'          => 'Nutzer verlässt Voice',
    'audit.entry_create'           => 'Audit-Eintrag erstellt',
    'automod.action'               => 'AutoMod-Aktion ausgeführt',
    'automod.rule_create'          => 'AutoMod-Regel erstellt',
    'automod.rule_delete'          => 'AutoMod-Regel gelöscht',
    'automod.rule_update'          => 'AutoMod-Regel aktualisiert',
];

// Category color map (Tailwind classes: bg + text)
$ceCategoryColors = [
    'message'        => ['bg' => 'bg-blue-100 dark:bg-blue-500/20',    'text' => 'text-blue-700 dark:text-blue-300'],
    'member'         => ['bg' => 'bg-green-100 dark:bg-green-500/20',   'text' => 'text-green-700 dark:text-green-300'],
    'reaction'       => ['bg' => 'bg-purple-100 dark:bg-purple-500/20', 'text' => 'text-purple-700 dark:text-purple-300'],
    'role'           => ['bg' => 'bg-yellow-100 dark:bg-yellow-500/20', 'text' => 'text-yellow-700 dark:text-yellow-300'],
    'channel'        => ['bg' => 'bg-sky-100 dark:bg-sky-500/20',       'text' => 'text-sky-700 dark:text-sky-300'],
    'guild'          => ['bg' => 'bg-orange-100 dark:bg-orange-500/20', 'text' => 'text-orange-700 dark:text-orange-300'],
    'boost'          => ['bg' => 'bg-pink-100 dark:bg-pink-500/20',     'text' => 'text-pink-700 dark:text-pink-300'],
    'bot'            => ['bg' => 'bg-violet-100 dark:bg-violet-500/20', 'text' => 'text-violet-700 dark:text-violet-300'],
    'music'          => ['bg' => 'bg-teal-100 dark:bg-teal-500/20',     'text' => 'text-teal-700 dark:text-teal-300'],
    'invite'         => ['bg' => 'bg-indigo-100 dark:bg-indigo-500/20', 'text' => 'text-indigo-700 dark:text-indigo-300'],
    'thread'         => ['bg' => 'bg-amber-100 dark:bg-amber-500/20',   'text' => 'text-amber-700 dark:text-amber-300'],
    'webhook'        => ['bg' => 'bg-rose-100 dark:bg-rose-500/20',     'text' => 'text-rose-700 dark:text-rose-300'],
    'sticker'        => ['bg' => 'bg-emerald-100 dark:bg-emerald-500/20','text'=> 'text-emerald-700 dark:text-emerald-300'],
    'stage'          => ['bg' => 'bg-cyan-100 dark:bg-cyan-500/20',     'text' => 'text-cyan-700 dark:text-cyan-300'],
    'scheduled_event'=> ['bg' => 'bg-fuchsia-100 dark:bg-fuchsia-500/20','text'=> 'text-fuchsia-700 dark:text-fuchsia-300'],
    'audit'          => ['bg' => 'bg-slate-100 dark:bg-slate-500/20',   'text' => 'text-slate-700 dark:text-slate-300'],
    'automod'        => ['bg' => 'bg-red-100 dark:bg-red-500/20',       'text' => 'text-red-700 dark:text-red-300'],
];

function ceCategoryFromType(string $eventType): string
{
    $dot = strpos($eventType, '.');
    return $dot !== false ? substr($eventType, 0, $dot) : $eventType;
}

function ceBadgeClasses(string $category, array $colorMap): string
{
    if (isset($colorMap[$category])) {
        return $colorMap[$category]['bg'] . ' ' . $colorMap[$category]['text'];
    }
    return 'bg-gray-100 dark:bg-gray-700/60 text-gray-600 dark:text-gray-300';
}
?>
<div class="grid grid-cols-12 gap-6">
    <div class="col-span-full">

        <!-- Header card -->
        <div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl mb-6">
            <div class="px-5 py-5 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <div class="text-xs uppercase font-semibold tracking-wide text-teal-500 mb-1">Custom Events</div>
                    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Event Builder</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Erstelle und verwalte eigene Event-Handler mit dem visuellen Builder.
                    </p>
                </div>
                <?php if ($currentBotId > 0): ?>
                    <div class="flex items-center gap-2">
                        <button type="button" id="ce-new-event-btn"
                                class="btn bg-teal-500 hover:bg-teal-600 text-white whitespace-nowrap"
                                data-bot-id="<?= $currentBotId ?>">
                            + Neues Event
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($flashSuccess !== null): ?>
            <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
                <?= h($flashSuccess) ?>
            </div>
        <?php endif; ?>
        <?php if ($flashError !== null): ?>
            <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                <?= h($flashError) ?>
            </div>
        <?php endif; ?>
        <?php if ($eventsError !== null): ?>
            <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                <?= h($eventsError) ?>
            </div>
        <?php endif; ?>
        <?php if (!$eventsTableReady || !$builderTableReady): ?>
            <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-4 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                <strong>DB-Hinweis:</strong> Fehlende Tabellen —
                <?= !$eventsTableReady ? 'bot_custom_events' : '' ?>
                <?= (!$eventsTableReady && !$builderTableReady) ? ' und ' : '' ?>
                <?= !$builderTableReady ? 'bot_custom_event_builders' : '' ?>.
                Außerdem bitte ausführen: <code>ALTER TABLE bot_custom_events ADD COLUMN group_name VARCHAR(80) NULL DEFAULT NULL AFTER is_enabled;</code>
            </div>
        <?php endif; ?>

        <?php if ($currentBotId <= 0): ?>
            <div class="rounded-xl border border-gray-200 dark:border-gray-700/60 px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800">
                Bitte zuerst einen Bot auswählen.
            </div>

        <?php elseif ($events === []): ?>
            <div class="rounded-xl border border-gray-200 dark:border-gray-700/60 px-4 py-12 text-center bg-white dark:bg-gray-800">
                <div class="text-4xl mb-3">⚡</div>
                <div class="text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Noch keine Custom Events</div>
                <div class="text-sm text-gray-400 dark:text-gray-500 mb-5">Klicke auf "+ Neues Event" um loszulegen.</div>
                <button type="button" id="ce-new-event-btn-empty"
                        class="btn bg-teal-500 hover:bg-teal-600 text-white text-sm"
                        data-bot-id="<?= $currentBotId ?>">
                    + Neues Event
                </button>
            </div>

        <?php else: ?>

            <!-- Stats bar -->
            <div class="flex items-center gap-4 mb-4 text-sm text-gray-500 dark:text-gray-400">
                <span><strong class="text-gray-700 dark:text-gray-200"><?= count($events) ?></strong> Events</span>
                <span><strong class="text-gray-700 dark:text-gray-200"><?= count(array_filter($events, fn($e) => (int)($e['is_enabled'] ?? 0) === 1)) ?></strong> Aktiv</span>
                <span><strong class="text-gray-700 dark:text-gray-200"><?= count($grouped) ?></strong> Gruppen</span>
            </div>

            <?php
            function renderEventSection(array $evts, string $sectionLabel, bool $isGroup, int $currentBotId, string $csrfToken, array $colorMap, array $labels): void
            {
            ?>
            <div class="ce-event-section mb-4 bg-white dark:bg-gray-800 rounded-xl shadow-xs border border-gray-200 dark:border-gray-700/60 overflow-hidden"
                 data-section="<?= h($sectionLabel) ?>">

                <!-- Section header -->
                <div class="ce-event-section-head flex items-center gap-3 px-5 py-3 border-b border-gray-100 dark:border-gray-700/60 cursor-pointer select-none"
                     onclick="this.closest('.ce-event-section').classList.toggle('is-collapsed')">
                    <svg class="ce-collapse-icon w-4 h-4 text-gray-400 transition-transform" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>

                    <?php if ($isGroup): ?>
                        <svg class="w-4 h-4 text-teal-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                        <span class="font-semibold text-sm text-gray-700 dark:text-gray-200"><?= h($sectionLabel) ?></span>
                    <?php else: ?>
                        <span class="font-medium text-sm text-gray-500 dark:text-gray-400 italic">Ohne Gruppe</span>
                    <?php endif; ?>

                    <span class="ml-auto text-xs text-gray-400"><?= count($evts) ?> Events</span>
                </div>

                <!-- Events in this section -->
                <div class="ce-event-section-body divide-y divide-gray-100 dark:divide-gray-700/60">
                    <?php foreach ($evts as $evt):
                        $eventId    = (int)($evt['id'] ?? 0);
                        $eventName  = trim((string)($evt['name'] ?? ''));
                        $eventType  = trim((string)($evt['event_type'] ?? ''));
                        $description= trim((string)($evt['description'] ?? ''));
                        $isEnabled  = ((int)($evt['is_enabled'] ?? 0) === 1);
                        $groupName  = trim((string)($evt['group_name'] ?? ''));
                        $category   = ceCategoryFromType($eventType);
                        $badgeCls   = ceBadgeClasses($category, $colorMap);
                        $typeLabel  = $labels[$eventType] ?? $eventType;
                    ?>
                    <div class="ce-event-row flex items-center gap-4 px-5 py-4" data-event-id="<?= $eventId ?>">

                        <!-- Enable toggle -->
                        <label class="bh-toggle flex-shrink-0" title="<?= $isEnabled ? 'Deaktivieren' : 'Aktivieren' ?>">
                            <input type="checkbox"
                                   class="bh-toggle-input"
                                   <?= $isEnabled ? 'checked' : '' ?>
                                   data-event-id="<?= $eventId ?>">
                            <span class="bh-toggle-track">
                                <span class="bh-toggle-thumb"></span>
                            </span>
                        </label>

                        <!-- Event type badge -->
                        <div class="flex-shrink-0">
                            <span class="inline-flex items-center text-xs font-semibold rounded-full px-2.5 py-1 <?= $badgeCls ?>">
                                <?= h($typeLabel) ?>
                            </span>
                        </div>

                        <!-- Info -->
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-semibold text-sm text-gray-800 dark:text-gray-100 truncate">
                                    <?= h($eventName !== '' ? $eventName : 'Event #' . $eventId) ?>
                                </span>
                                <span class="text-xs rounded-full px-2 py-0.5 font-medium <?= $isEnabled ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' : 'bg-gray-100 text-gray-500 dark:bg-gray-700/60 dark:text-gray-400' ?> ce-event-status-badge">
                                    <?= $isEnabled ? 'Aktiv' : 'Inaktiv' ?>
                                </span>
                            </div>
                            <?php if ($description !== ''): ?>
                                <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5 truncate"><?= h($description) ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Group inline editor -->
                        <div class="flex-shrink-0 hidden sm:flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                            <input type="text"
                                   class="ce-event-group-input text-xs border border-gray-200 dark:border-gray-600 rounded-lg px-2 py-1 bg-transparent text-gray-600 dark:text-gray-300 placeholder-gray-400 focus:outline-none focus:border-teal-400 w-28"
                                   placeholder="Gruppe…"
                                   maxlength="80"
                                   value="<?= h($groupName) ?>"
                                   data-event-id="<?= $eventId ?>"
                                   data-original="<?= h($groupName) ?>">
                        </div>

                        <!-- Actions -->
                        <div class="flex-shrink-0 flex items-center gap-2">
                            <a href="/dashboard/custom-events/builder?event_id=<?= $eventId ?>&amp;bot_id=<?= $currentBotId ?>"
                               class="btn-sm bg-teal-500 hover:bg-teal-600 text-white text-xs">
                                Builder
                            </a>
                            <button type="button"
                                    class="btn-sm border border-gray-200 hover:border-rose-300 hover:text-rose-600 dark:border-gray-700 dark:hover:border-rose-400 dark:hover:text-rose-300 text-xs ce-event-delete-btn"
                                    data-event-id="<?= $eventId ?>">
                                Löschen
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php } // end renderEventSection ?>

            <?php foreach ($grouped as $groupName => $groupEvts): ?>
                <?php renderEventSection($groupEvts, $groupName, true, $currentBotId, $csrfToken, $ceCategoryColors, $ceEventLabels); ?>
            <?php endforeach; ?>

            <?php if ($ungrouped !== []): ?>
                <?php renderEventSection($ungrouped, '', false, $currentBotId, $csrfToken, $ceCategoryColors, $ceEventLabels); ?>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

<!-- New Event Modal -->
<div id="ce-new-event-modal" class="ce-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="ce-modal-title">
    <div class="ce-modal">
        <div class="ce-modal-header">
            <span id="ce-modal-title">Neues Event erstellen</span>
            <button type="button" class="ce-modal-close" id="ce-modal-close-btn" aria-label="Schließen">✕</button>
        </div>

        <div class="ce-modal-body">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5" for="ce-event-name-input">
                    Name <span class="text-rose-500">*</span>
                </label>
                <input type="text"
                       id="ce-event-name-input"
                       placeholder="z.B. Willkommen Nachricht"
                       maxlength="100"
                       autocomplete="off"
                       class="w-full bg-white dark:bg-gray-900 border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:border-teal-400">
                <div id="ce-name-error" class="mt-1 text-xs text-rose-500" style="display:none;">Bitte einen Namen eingeben.</div>
            </div>
            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5" for="ce-event-desc-input">
                    Beschreibung <span class="text-gray-400 text-xs">(optional)</span>
                </label>
                <input type="text"
                       id="ce-event-desc-input"
                       placeholder="Kurze Beschreibung…"
                       maxlength="255"
                       class="w-full bg-white dark:bg-gray-900 border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:border-teal-400">
            </div>
            <p class="text-xs text-gray-400 dark:text-gray-500 mb-4">Das Event-Typ wird direkt im Builder im Trigger-Block festgelegt.</p>
            <button type="button" id="ce-open-builder-btn"
                    class="btn bg-teal-500 hover:bg-teal-600 text-white w-full">
                Builder öffnen
            </button>
        </div>
    </div>
</div>


<script>
(function () {
    const CSRF    = <?= json_encode((string)$_SESSION['bh_ce_csrf']) ?>;
    const BOT_ID  = <?= (int)$currentBotId ?>;

    async function ceApi(action, payload) {
        const res = await fetch('/api/v1/custom_events.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CE-CSRF': CSRF,
            },
            body: JSON.stringify({ action, ...payload }),
        });
        return res.json();
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Toggle enable/disable ─────────────────────────────────────────────────
    document.querySelectorAll('.bh-toggle-input').forEach((input) => {
        input.addEventListener('change', async function () {
            const eventId = parseInt(this.dataset.eventId, 10);
            const enabled = this.checked;
            const row     = this.closest('.ce-event-row');

            applyEnabledState(row, enabled);

            try {
                const data = await ceApi('toggle_enabled', { event_id: eventId, enabled });
                if (!data.ok) {
                    this.checked = !enabled;
                    applyEnabledState(row, !enabled);
                    alert(data.error || 'Fehler beim Speichern.');
                }
            } catch {
                this.checked = !enabled;
                applyEnabledState(row, !enabled);
            }
        });
    });

    function applyEnabledState(row, enabled) {
        if (!row) return;
        const badge = row.querySelector('.ce-event-status-badge');
        if (badge) {
            badge.textContent = enabled ? 'Aktiv' : 'Inaktiv';
            badge.className = badge.className
                .replace(/\b(bg-emerald-100|text-emerald-700|dark:bg-emerald-500\/20|dark:text-emerald-300|bg-gray-100|text-gray-500|dark:bg-gray-700\/60|dark:text-gray-400)\b/g, '')
                .trim();
            badge.classList.add(...(enabled
                ? ['bg-emerald-100','text-emerald-700','dark:bg-emerald-500/20','dark:text-emerald-300']
                : ['bg-gray-100','text-gray-500','dark:bg-gray-700/60','dark:text-gray-400']
            ));
        }
    }

    // ── Delete event ──────────────────────────────────────────────────────────
    document.querySelectorAll('.ce-event-delete-btn').forEach((btn) => {
        btn.addEventListener('click', async function () {
            if (!confirm('Dieses Event wirklich löschen?')) return;

            const eventId = parseInt(this.dataset.eventId, 10);
            const row = this.closest('.ce-event-row');

            btn.disabled = true;
            btn.textContent = '…';

            try {
                const data = await ceApi('delete_event', { event_id: eventId });
                if (data.ok) {
                    if (row) {
                        row.style.transition = 'opacity 0.2s';
                        row.style.opacity = '0';
                        setTimeout(() => row.remove(), 200);
                    }
                } else {
                    alert(data.error || 'Fehler beim Löschen.');
                    btn.disabled = false;
                    btn.textContent = 'Löschen';
                }
            } catch {
                alert('Netzwerkfehler beim Löschen.');
                btn.disabled = false;
                btn.textContent = 'Löschen';
            }
        });
    });

    // ── Group inline editor ───────────────────────────────────────────────────
    document.querySelectorAll('.ce-event-group-input').forEach((input) => {
        async function saveGroup() {
            const eventId  = parseInt(input.dataset.eventId, 10);
            const newGroup = input.value.trim();
            if (newGroup === input.dataset.original) return;

            input.disabled = true;
            try {
                const data = await ceApi('set_group', { event_id: eventId, group_name: newGroup });
                if (data.ok) {
                    input.dataset.original = newGroup;
                    window.location.reload();
                } else {
                    alert(data.error || 'Fehler beim Speichern der Gruppe.');
                    input.value = input.dataset.original;
                }
            } catch {
                input.value = input.dataset.original;
            } finally {
                input.disabled = false;
            }
        }

        input.addEventListener('blur', saveGroup);
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter')  { e.preventDefault(); input.blur(); }
            if (e.key === 'Escape') { input.value = input.dataset.original; input.blur(); }
        });
    });

    // ── New Event Modal ───────────────────────────────────────────────────────
    const modal       = document.getElementById('ce-new-event-modal');
    const closeBtn    = document.getElementById('ce-modal-close-btn');
    const nameInput   = document.getElementById('ce-event-name-input');
    const descInput   = document.getElementById('ce-event-desc-input');
    const nameError   = document.getElementById('ce-name-error');
    const openBuilder = document.getElementById('ce-open-builder-btn');

    function openModal() {
        nameInput.value = '';
        descInput.value = '';
        nameError.style.display = 'none';
        modal.classList.add('is-open');
        nameInput.focus();
    }

    function closeModal() {
        modal.classList.remove('is-open');
    }

    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

    nameInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); openBuilder.click(); }
    });

    openBuilder.addEventListener('click', () => {
        const name = nameInput.value.trim();
        if (!name) {
            nameError.style.display = 'block';
            nameInput.focus();
            return;
        }
        nameError.style.display = 'none';
        const desc = descInput.value.trim();
        window.location.href = '/dashboard/custom-events/builder'
            + '?bot_id=' + encodeURIComponent(BOT_ID)
            + '&event_name=' + encodeURIComponent(name)
            + (desc ? '&event_description=' + encodeURIComponent(desc) : '');
    });

    // Wire up all "+ Neues Event" buttons
    document.querySelectorAll('#ce-new-event-btn, #ce-new-event-btn-empty').forEach((btn) => {
        btn.addEventListener('click', openModal);
    });
}());
</script>
