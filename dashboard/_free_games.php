<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/functions/html.php';
require_once dirname(__DIR__) . '/functions/module_toggle.php';
require_once dirname(__DIR__) . '/functions/db_functions/commands.php';

$pdo   = bh_get_pdo();
$botId = isset($currentBotId) && $currentBotId > 0 ? $currentBotId : (int)($_GET['bot_id'] ?? 0);

if ($botId <= 0) { ?>
<div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl p-5">
    <div class="text-sm text-rose-600 dark:text-rose-400">Bot nicht gefunden.</div>
</div>
<?php return; }

// ── Auto-migrate ──────────────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `free_games_settings` (
        `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `bot_id`           BIGINT UNSIGNED NOT NULL,
        `channel_id`       VARCHAR(30)     NOT NULL DEFAULT '',
        `ping_role_id`     VARCHAR(30)     NOT NULL DEFAULT '',
        `epic_enabled`     TINYINT(1)      NOT NULL DEFAULT 1,
        `steam_enabled`    TINYINT(1)      NOT NULL DEFAULT 1,
        `is_enabled`       TINYINT(1)      NOT NULL DEFAULT 1,
        `schedule_enabled` TINYINT(1)      NOT NULL DEFAULT 0,
        `schedule_time`    VARCHAR(5)      NOT NULL DEFAULT '09:00',
        `schedule_days`    VARCHAR(100)    NOT NULL DEFAULT 'Mon,Tue,Wed,Thu,Fri,Sat,Sun',
        `last_game_ids`    TEXT            NULL,
        `last_checked_at`  DATETIME        NULL,
        `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_fg_bot` (`bot_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable) {}
// Add schedule columns to existing tables
foreach ([
    "ALTER TABLE `free_games_settings` ADD COLUMN `schedule_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_enabled`",
    "ALTER TABLE `free_games_settings` ADD COLUMN `schedule_time`    VARCHAR(5)   NOT NULL DEFAULT '09:00' AFTER `schedule_enabled`",
    "ALTER TABLE `free_games_settings` ADD COLUMN `schedule_days`    VARCHAR(100) NOT NULL DEFAULT 'Mon,Tue,Wed,Thu,Fri,Sat,Sun' AFTER `schedule_time`",
] as $_fgMig) { try { $pdo->exec($_fgMig); } catch (Throwable) {} }

// ── AJAX ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bh_mod_handle_ajax($pdo, $botId);
    while (ob_get_level() > 0) ob_end_clean();
    $raw    = (string)file_get_contents('php://input');
    $data   = json_decode($raw, true) ?: [];
    $action = (string)($data['action'] ?? '');
    header('Content-Type: application/json; charset=utf-8');

    if ($action === 'save_settings') {
        $channelId       = preg_replace('/[^0-9]/', '', (string)($data['channel_id']   ?? ''));
        $pingRole        = preg_replace('/[^0-9]/', '', (string)($data['ping_role_id'] ?? ''));
        $epicEnabled     = isset($data['epic_enabled'])     && $data['epic_enabled']     ? 1 : 0;
        $steamEnabled    = isset($data['steam_enabled'])    && $data['steam_enabled']    ? 1 : 0;
        $isEnabled       = isset($data['is_enabled'])       && $data['is_enabled']       ? 1 : 0;
        $schedEnabled    = isset($data['schedule_enabled']) && $data['schedule_enabled'] ? 1 : 0;
        $schedTimeRaw    = preg_replace('/[^0-9:]/', '', (string)($data['schedule_time'] ?? '09:00'));
        $schedTime       = preg_match('/^\d{2}:\d{2}$/', $schedTimeRaw) ? $schedTimeRaw : '09:00';
        $allowedDays     = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
        $schedDays       = implode(',', array_filter(
            is_array($data['schedule_days'] ?? null) ? $data['schedule_days'] : [],
            fn($d) => in_array($d, $allowedDays, true)
        ));
        if ($schedDays === '') $schedDays = 'Mon,Tue,Wed,Thu,Fri,Sat,Sun';
        try {
            $pdo->prepare("
                INSERT INTO free_games_settings
                    (bot_id, channel_id, ping_role_id, epic_enabled, steam_enabled, is_enabled,
                     schedule_enabled, schedule_time, schedule_days)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    channel_id       = VALUES(channel_id),
                    ping_role_id     = VALUES(ping_role_id),
                    epic_enabled     = VALUES(epic_enabled),
                    steam_enabled    = VALUES(steam_enabled),
                    is_enabled       = VALUES(is_enabled),
                    schedule_enabled = VALUES(schedule_enabled),
                    schedule_time    = VALUES(schedule_time),
                    schedule_days    = VALUES(schedule_days),
                    updated_at       = NOW()
            ")->execute([$botId, $channelId, $pingRole, $epicEnabled, $steamEnabled, $isEnabled,
                         $schedEnabled, $schedTime, $schedDays]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'reset_cache') {
        try {
            $pdo->prepare("UPDATE free_games_settings SET last_game_ids = NULL WHERE bot_id = ?")
                ->execute([$botId]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'fetch_games') {
        $result = ['epic' => [], 'steam' => []];
        $ctx = stream_context_create(['http' => [
            'method'  => 'GET',
            'header'  => "Accept: application/json\r\nUser-Agent: BotHub/1.0\r\n",
            'timeout' => 10,
        ]]);

        // Epic Games
        try {
            $body = @file_get_contents('https://store-site-backend-static.ak.epicgames.com/freeGamesPromotions?locale=de&country=DE&allowCountries=DE', false, $ctx);
            if ($body) {
                $json     = json_decode($body, true);
                $elements = $json['data']['Catalog']['searchStore']['elements'] ?? [];
                $now      = time();
                foreach ($elements as $item) {
                    $promos = $item['promotions']['promotionalOffers'] ?? [];
                    if (empty($promos)) continue;
                    $free = false; $endDate = null;
                    foreach ($promos as $pg) {
                        foreach ($pg['promotionalOffers'] ?? [] as $offer) {
                            $start = strtotime($offer['startDate'] ?? '');
                            $end   = strtotime($offer['endDate']   ?? '');
                            $pct   = $offer['discountSetting']['discountPercentage'] ?? 100;
                            if ($pct === 0 && $now >= $start && $now <= $end) {
                                $free = true; $endDate = $offer['endDate']; break 2;
                            }
                        }
                    }
                    if (!$free) continue;
                    $images = $item['keyImages'] ?? [];
                    $thumb  = '';
                    foreach (['Thumbnail','DieselStoreFront','OfferImageWide'] as $type) {
                        foreach ($images as $img) {
                            if (($img['type'] ?? '') === $type) { $thumb = $img['url'] ?? ''; break 2; }
                        }
                    }
                    if (!$thumb && !empty($images)) $thumb = $images[0]['url'] ?? '';
                    $slug = $item['catalogNs']['mappings'][0]['pageSlug']
                         ?? $item['productSlug'] ?? $item['urlSlug'] ?? '';
                    $result['epic'][] = [
                        'title'       => h((string)($item['title'] ?? '')),
                        'description' => h(mb_substr((string)($item['description'] ?? ''), 0, 200)),
                        'image'       => $thumb,
                        'url'         => $slug ? 'https://store.epicgames.com/de/p/' . rawurlencode($slug) : 'https://store.epicgames.com/de/free-games',
                        'endDate'     => $endDate,
                    ];
                }
            }
        } catch (Throwable) {}

        // Steam via GamerPower
        try {
            $body = @file_get_contents('https://www.gamerpower.com/api/giveaways?platform=steam&type=game&sort-by=date', false, $ctx);
            if ($body) {
                $json = json_decode($body, true);
                if (is_array($json)) {
                    foreach (array_slice($json, 0, 8) as $item) {
                        $result['steam'][] = [
                            'title'       => h((string)($item['title'] ?? '')),
                            'description' => h(mb_substr((string)($item['description'] ?? ''), 0, 200)),
                            'image'       => (string)($item['thumbnail'] ?? $item['image'] ?? ''),
                            'url'         => (string)($item['open_giveaway_url'] ?? $item['giveaway_url'] ?? 'https://store.steampowered.com/'),
                            'endDate'     => ($item['end_date'] ?? 'N/A') !== 'N/A' ? $item['end_date'] : null,
                            'worth'       => h((string)($item['worth'] ?? '')),
                        ];
                    }
                }
            }
        } catch (Throwable) {}

        echo json_encode(['ok' => true, 'data' => $result]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unbekannte Aktion']); exit;
}

// ── Load settings ─────────────────────────────────────────────────────────────
$fgSettings = null;
try {
    $stmt = $pdo->prepare('SELECT * FROM free_games_settings WHERE bot_id = ? LIMIT 1');
    $stmt->execute([$botId]);
    $fgSettings = $stmt->fetch() ?: null;
} catch (Throwable) {}

$fgChannel      = (string)($fgSettings['channel_id']      ?? '');
$fgPingRole     = (string)($fgSettings['ping_role_id']    ?? '');
$fgEpic         = (int)($fgSettings['epic_enabled']       ?? 1) === 1;
$fgSteam        = (int)($fgSettings['steam_enabled']      ?? 1) === 1;
$fgEnabled      = (int)($fgSettings['is_enabled']         ?? 1) === 1;
$fgSchedEnabled = (int)($fgSettings['schedule_enabled']   ?? 0) === 1;
$fgSchedTime    = (string)($fgSettings['schedule_time']   ?? '09:00');
$fgSchedDays    = (string)($fgSettings['schedule_days']   ?? 'Mon,Tue,Wed,Thu,Fri,Sat,Sun');
$fgLastCheck    = $fgSettings['last_checked_at'] ?? null;
$modEnabled   = bh_mod_is_enabled($pdo, $botId, 'module:free-games');

// Ensure command row exists (preserves is_enabled across page loads)
try {
    bhcmd_ensure_command($pdo, $botId, 'free-games', 'predefined',
        'free-games', 'Zeigt aktuell kostenlose Spiele von den aktivierten Plattformen.', 1);
} catch (Throwable) {}

$cmdEnabled = bhcmd_is_enabled($pdo, $botId, 'free-games');
?>

<?= bh_mod_render($modEnabled, $botId, 'module:free-games', 'Free Games Notifications', 'Automatische Benachrichtigungen für kostenlose Spiele von Epic Games und Steam.') ?>
<div id="bh-mod-body">

<!-- Command card -->
<div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl" style="margin-bottom:24px">
    <div class="p-5 border-b border-gray-100 dark:border-gray-700/60" style="display:flex;align-items:center;gap:12px">
        <div style="width:32px;height:32px;border-radius:8px;background:#5865f2;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <svg style="width:16px;height:16px;color:#fff" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>
        </div>
        <div style="flex:1">
            <div class="text-sm font-semibold text-gray-800 dark:text-gray-100">Slash Command</div>
            <div class="text-xs text-gray-400" style="margin-top:1px">Command in Discord aktivieren oder deaktivieren</div>
        </div>
        <label class="bh-toggle">
            <input type="checkbox" id="fg-cmd-toggle" class="bh-toggle-input" <?= $cmdEnabled ? 'checked' : '' ?>>
            <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
        </label>
    </div>
    <div class="p-5" style="display:flex;flex-direction:column;gap:10px">
        <div style="display:flex;align-items:start;gap:12px;padding:12px;border-radius:8px" class="bg-gray-100 dark:bg-gray-900/50">
            <code class="text-sm font-semibold text-violet-600 dark:text-violet-400 shrink-0">/free-games</code>
            <span class="text-xs text-gray-500 dark:text-gray-400">
                Zeigt die aktuell kostenlosen Spiele der aktivierten Plattformen direkt im Discord-Channel an.
            </span>
        </div>
        <p class="text-xs text-gray-400">
            Der Command ist nur verfügbar wenn das Modul oben aktiviert ist und mindestens eine Plattform (Epic / Steam) eingeschaltet ist.
        </p>
    </div>
</div>

<div id="bh-alert" style="display:none"></div>

<!-- Header card -->
<div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl" style="margin-bottom:24px">
    <div class="p-5 border-b border-gray-100 dark:border-gray-700/60" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
        <div>
            <div class="fg-label-accent">Free Games</div>
            <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Free Games Notifications</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400" style="margin-top:2px">
                Automatische Discord-Benachrichtigungen für kostenlose Spiele von Epic Games und Steam.
            </p>
        </div>
        <button type="button" id="fg-reload-btn" class="inline-flex items-center gap-1.5 rounded-lg bg-violet-600 hover:bg-violet-700 text-white text-xs font-semibold px-4 py-2 transition-colors">
            <svg style="width:14px;height:14px" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            Spiele laden
        </button>
    </div>
</div>

<!-- Two-column layout -->
<div style="display:grid;grid-template-columns:1fr 2fr;gap:24px;align-items:start">

<!-- Settings Panel -->
<div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl" style="overflow:hidden">
    <div class="p-5 border-b border-gray-100 dark:border-gray-700/60">
        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Einstellungen</h3>
    </div>
    <div class="p-5" style="display:flex;flex-direction:column;gap:16px">

        <!-- Modul aktiv -->
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
            <div>
                <div class="text-sm font-medium text-gray-700 dark:text-gray-200">Modul aktiv</div>
                <div class="text-xs text-gray-400" style="margin-top:2px">Benachrichtigungen senden</div>
            </div>
            <label class="bh-toggle">
                <input type="checkbox" id="fg-is-enabled" class="bh-toggle-input" <?= $fgEnabled ? 'checked' : '' ?>>
                <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
            </label>
        </div>

        <hr class="border-gray-100 dark:border-gray-700/60">

        <!-- Channel -->
        <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                Discord Kanal <span class="text-rose-500">*</span>
            </label>
            <div class="fg-picker-box" id="fg-channel-box">
                <button type="button" class="fg-picker-add-btn" id="fg-channel-pick-btn" title="Kanal auswählen">+</button>
            </div>
            <p class="text-xs text-gray-400" style="margin-top:3px">Kanal wo Free Games gepostet werden</p>
        </div>

        <!-- Ping Role -->
        <div>
            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                Ping-Rolle <span class="text-gray-400">(optional)</span>
            </label>
            <div class="fg-picker-box" id="fg-role-box">
                <button type="button" class="fg-picker-add-btn" id="fg-role-pick-btn" title="Rolle auswählen">+</button>
            </div>
        </div>

        <hr class="border-gray-100 dark:border-gray-700/60">

        <!-- Scheduled Delivery -->
        <div>
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px">
                <div>
                    <div class="text-sm font-medium text-gray-700 dark:text-gray-200">Geplante Ausgabe</div>
                    <div class="text-xs text-gray-400" style="margin-top:2px">Free Games automatisch zu einer Uhrzeit posten <span class="text-gray-400">(optional)</span></div>
                </div>
                <label class="bh-toggle">
                    <input type="checkbox" id="fg-sched-enabled" class="bh-toggle-input" <?= $fgSchedEnabled ? 'checked' : '' ?>>
                    <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                </label>
            </div>

            <div id="fg-sched-body" style="<?= $fgSchedEnabled ? 'display:flex' : 'display:none' ?>;flex-direction:column;gap:12px">

                <!-- Time picker -->
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Uhrzeit (HH:MM)</label>
                    <input type="text" id="fg-sched-time"
                           value="<?= h($fgSchedTime) ?>"
                           placeholder="09:00"
                           class="fg-sched-time-input">
                </div>

                <!-- Weekday picker -->
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-2">Wochentage</label>
                    <div class="fg-sched-days" id="fg-sched-days">
                        <?php
                        $activeDays = array_flip(array_map('trim', explode(',', $fgSchedDays)));
                        foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $day):
                        ?>
                        <button type="button"
                                class="fg-sched-day-btn<?= isset($activeDays[$day]) ? ' is-active' : '' ?>"
                                data-day="<?= $day ?>"><?= $day ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>
        </div>

        <hr class="border-gray-100 dark:border-gray-700/60">

        <!-- Platforms -->
        <div>
            <div class="text-xs font-medium text-gray-700 dark:text-gray-200" style="margin-bottom:10px">Plattformen</div>
            <div style="display:flex;flex-direction:column;gap:8px">
                <div class="flex items-center gap-2.5 p-2.5 rounded-lg border border-gray-200 dark:border-gray-700/60">
                    <div style="width:28px;height:28px;border-radius:6px;background:#0078f2;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                        <svg style="width:14px;height:14px;color:#fff" viewBox="0 0 24 24" fill="currentColor"><path d="M11.94 2C6.47 2 2 6.47 2 11.94c0 5.48 4.47 9.95 9.94 9.95 5.48 0 9.95-4.47 9.95-9.95C21.89 6.47 17.42 2 11.94 2zm.06 3.69l4.39 2.53v5.07l-4.39 2.53-4.39-2.53V8.22l4.39-2.53z"/></svg>
                    </div>
                    <div style="flex:1">
                        <div class="text-sm font-medium text-gray-700 dark:text-gray-200">Epic Games</div>
                        <div class="text-xs text-gray-400">Wöchentliche Free Games</div>
                    </div>
                    <label class="bh-toggle">
                        <input type="checkbox" id="fg-epic-enabled" class="bh-toggle-input" <?= $fgEpic ? 'checked' : '' ?>>
                        <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                    </label>
                </div>
                <div class="flex items-center gap-2.5 p-2.5 rounded-lg border border-gray-200 dark:border-gray-700/60">
                    <div style="width:28px;height:28px;border-radius:6px;background:#1b2838;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                        <svg style="width:14px;height:14px;color:#fff" viewBox="0 0 24 24" fill="currentColor"><path d="M11.979 0C5.678 0 .511 4.86.022 11.037l6.432 2.658c.545-.371 1.203-.59 1.912-.59.063 0 .125.004.188.006l2.861-4.142V8.91c0-2.495 2.028-4.524 4.524-4.524 2.494 0 4.524 2.031 4.524 4.527s-2.03 4.525-4.524 4.525h-.105l-4.076 2.911c0 .052.004.105.004.159 0 1.875-1.515 3.396-3.39 3.396-1.635 0-3.016-1.173-3.331-2.727L.436 15.27C1.862 20.307 6.486 24 11.979 24c6.627 0 11.999-5.373 11.999-12S18.606 0 11.979 0z"/></svg>
                    </div>
                    <div style="flex:1">
                        <div class="text-sm font-medium text-gray-700 dark:text-gray-200">Steam</div>
                        <div class="text-xs text-gray-400">Aktuelle Steam-Giveaways</div>
                    </div>
                    <label class="bh-toggle">
                        <input type="checkbox" id="fg-steam-enabled" class="bh-toggle-input" <?= $fgSteam ? 'checked' : '' ?>>
                        <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                    </label>
                </div>
            </div>
        </div>

        <hr class="border-gray-100 dark:border-gray-700/60">

        <button type="button" id="fg-save-btn"
                class="inline-flex items-center justify-center rounded-lg bg-violet-600 hover:bg-violet-700 text-white text-xs font-semibold px-4 py-2 transition-colors" style="width:100%">
            Einstellungen speichern
        </button>

        <?php if ($fgLastCheck): ?>
        <p class="text-xs text-center text-gray-400">
            Zuletzt geprüft: <?= h(date('d.m.Y H:i', strtotime($fgLastCheck))) ?> Uhr
        </p>
        <?php endif; ?>

        <button type="button" id="fg-reset-cache-btn" class="fg-btn-secondary">
            Cache zurücksetzen
        </button>

    </div>
</div>

<!-- Preview Panel -->
<div>
    <!-- Epic -->
    <div style="margin-bottom:20px">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
            <div style="width:22px;height:22px;border-radius:5px;background:#0078f2;display:flex;align-items:center;justify-content:center">
                <svg style="width:12px;height:12px;color:#fff" viewBox="0 0 24 24" fill="currentColor"><path d="M11.94 2C6.47 2 2 6.47 2 11.94c0 5.48 4.47 9.95 9.94 9.95 5.48 0 9.95-4.47 9.95-9.95C21.89 6.47 17.42 2 11.94 2zm.06 3.69l4.39 2.53v5.07l-4.39 2.53-4.39-2.53V8.22l4.39-2.53z"/></svg>
            </div>
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Epic Games — Aktuelle Free Games</h3>
        </div>
        <div id="fg-epic-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700/60 text-sm text-gray-400 text-center p-8" style="grid-column:span 2">
                Klicke auf "Spiele laden" um die aktuellen Epic Games Free Games anzuzeigen.
            </div>
        </div>
    </div>

    <!-- Steam -->
    <div>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
            <div style="width:22px;height:22px;border-radius:5px;background:#1b2838;display:flex;align-items:center;justify-content:center">
                <svg style="width:12px;height:12px;color:#fff" viewBox="0 0 24 24" fill="currentColor"><path d="M11.979 0C5.678 0 .511 4.86.022 11.037l6.432 2.658c.545-.371 1.203-.59 1.912-.59.063 0 .125.004.188.006l2.861-4.142V8.91c0-2.495 2.028-4.524 4.524-4.524 2.494 0 4.524 2.031 4.524 4.527s-2.03 4.525-4.524 4.525h-.105l-4.076 2.911c0 .052.004.105.004.159 0 1.875-1.515 3.396-3.39 3.396-1.635 0-3.016-1.173-3.331-2.727L.436 15.27C1.862 20.307 6.486 24 11.979 24c6.627 0 11.999-5.373 11.999-12S18.606 0 11.979 0z"/></svg>
            </div>
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Steam — Aktuelle Giveaways</h3>
        </div>
        <div id="fg-steam-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700/60 text-sm text-gray-400 text-center p-8" style="grid-column:span 2">
                Klicke auf "Spiele laden" um aktuelle Steam-Giveaways anzuzeigen.
            </div>
        </div>
    </div>
</div>

</div><!-- /two-column -->

</div><!-- /bh-mod-body -->


<script>
(function () {
    const BOT_ID = <?= (int)$botId ?>;

    const state = {
        channel:  <?= $fgChannel  !== '' ? json_encode(['id' => $fgChannel,  'name' => '#' . $fgChannel])  : 'null' ?>,
        pingRole: <?= $fgPingRole !== '' ? json_encode(['id' => $fgPingRole, 'name' => '@' . $fgPingRole]) : 'null' ?>,
        schedDays: <?= json_encode(array_values(array_filter(array_map('trim', explode(',', $fgSchedDays))))) ?>,
    };

    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function flash(msg, ok) {
        const el = document.getElementById('bh-alert');
        el.className = ok ? 'bh-alert--ok' : 'bh-alert--err';
        el.textContent = msg;
        el.style.display = '';
        clearTimeout(el._t);
        el._t = setTimeout(() => { el.style.display = 'none'; }, 4500);
    }

    async function postJson(action, extra) {
        const res = await fetch(location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ action }, extra || {})),
        });
        return res.json();
    }

    // ── Single-item picker ────────────────────────────────────────────────────
    function renderPicker(boxId, pickBtnId, item, isChannel) {
        const box = document.getElementById(boxId);
        const btn = document.getElementById(pickBtnId);
        if (!box) return;
        Array.from(box.children).forEach(ch => { if (ch !== btn) ch.remove(); });
        if (item) {
            const tag = document.createElement('span');
            tag.className = 'bh-tag' + (isChannel ? ' bh-tag bh-tag--channel' : '');
            tag.innerHTML = esc(item.name || item.id)
                + '<button type="button" class="bh-tag-rm" title="Entfernen">×</button>';
            tag.querySelector('.bh-tag-rm').addEventListener('click', () => {
                if (isChannel) { state.channel  = null; renderPicker(boxId, pickBtnId, null, true); }
                else            { state.pingRole = null; renderPicker(boxId, pickBtnId, null, false); }
            });
            box.insertBefore(tag, btn);
            btn.style.display = 'none';
        } else {
            btn.style.display = '';
        }
    }

    function initPicker(boxId, pickBtnId, isChannel) {
        renderPicker(boxId, pickBtnId, isChannel ? state.channel : state.pingRole, isChannel);
        document.getElementById(pickBtnId).addEventListener('click', function (e) {
            e.stopPropagation();
            if (typeof BhPerm === 'undefined') return;
            BhPerm.openPicker(this, BOT_ID, isChannel ? 'channels' : 'roles', [], function (item) {
                if (isChannel) { state.channel  = { id: item.id, name: item.name }; }
                else            { state.pingRole = { id: item.id, name: item.name }; }
                renderPicker(boxId, pickBtnId, isChannel ? state.channel : state.pingRole, isChannel);
            });
        });
    }

    initPicker('fg-channel-box', 'fg-channel-pick-btn', true);
    initPicker('fg-role-box',    'fg-role-pick-btn',    false);

    // ── Schedule ──────────────────────────────────────────────────────────────
    if (typeof flatpickr !== 'undefined') {
        flatpickr('#fg-sched-time', {
            enableTime: true,
            noCalendar: true,
            dateFormat: 'H:i',
            time_24hr: true,
            defaultDate: <?= json_encode($fgSchedTime) ?>,
        });
    }

    const schedEnabled = document.getElementById('fg-sched-enabled');
    const schedBody    = document.getElementById('fg-sched-body');
    schedEnabled.addEventListener('change', function () {
        schedBody.style.display = this.checked ? '' : 'none';
    });

    document.getElementById('fg-sched-days').addEventListener('click', function (e) {
        const btn = e.target.closest('.fg-sched-day-btn');
        if (!btn) return;
        const day = btn.dataset.day;
        const idx = state.schedDays.indexOf(day);
        if (idx === -1) {
            state.schedDays.push(day);
            btn.classList.add('is-active');
        } else {
            state.schedDays.splice(idx, 1);
            btn.classList.remove('is-active');
        }
    });

    // ── Save ──────────────────────────────────────────────────────────────────
    document.getElementById('fg-save-btn').addEventListener('click', async function () {
        this.disabled = true;
        this.textContent = 'Speichert…';
        try {
            const schedTimeEl = document.getElementById('fg-sched-time');
            const schedTimeVal = (schedTimeEl._flatpickr ? schedTimeEl._flatpickr.input.value : schedTimeEl.value) || '09:00';
            const d = await postJson('save_settings', {
                channel_id:       state.channel  ? state.channel.id  : '',
                ping_role_id:     state.pingRole ? state.pingRole.id : '',
                epic_enabled:     document.getElementById('fg-epic-enabled').checked,
                steam_enabled:    document.getElementById('fg-steam-enabled').checked,
                is_enabled:       document.getElementById('fg-is-enabled').checked,
                schedule_enabled: document.getElementById('fg-sched-enabled').checked,
                schedule_time:    schedTimeVal,
                schedule_days:    state.schedDays,
            });
            if (d.ok) flash('Einstellungen gespeichert.', true);
            else flash(d.error || 'Fehler.', false);
        } catch (_) {
            flash('Netzwerkfehler.', false);
        } finally {
            this.disabled = false;
            this.textContent = 'Einstellungen speichern';
        }
    });

    // ── Reset cache ───────────────────────────────────────────────────────────
    document.getElementById('fg-reset-cache-btn').addEventListener('click', async function () {
        if (!confirm('Cache zurücksetzen? Die nächste Prüfung postet alle aktuellen Spiele neu.')) return;
        this.disabled = true;
        try {
            const d = await postJson('reset_cache');
            if (d.ok) flash('Cache zurückgesetzt.', true);
            else flash(d.error || 'Fehler.', false);
        } catch (_) {
            flash('Netzwerkfehler.', false);
        } finally {
            this.disabled = false;
        }
    });

    // ── Render ────────────────────────────────────────────────────────────────
    function renderSkeletons(id) {
        const el = document.getElementById(id);
        el.innerHTML =
            '<div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700/60 overflow-hidden">' +
                '<div class="fg-skeleton" style="aspect-ratio:16/9"></div>' +
                '<div class="p-3" style="display:flex;flex-direction:column;gap:6px">' +
                    '<div class="fg-skeleton" style="height:14px;width:75%"></div>' +
                    '<div class="fg-skeleton" style="height:11px;width:100%"></div>' +
                '</div>' +
            '</div>';
    }

    function renderGames(id, games, emptyMsg, platform) {
        const el = document.getElementById(id);
        if (!games || !games.length) {
            el.innerHTML = '<div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700/60 text-sm text-gray-400 text-center p-8" style="grid-column:span 2">' + esc(emptyMsg) + '</div>';
            return;
        }
        el.innerHTML = '';
        for (const g of games) {
            let endLabel = '';
            if (g.endDate) {
                try { endLabel = '⏳ bis ' + new Date(g.endDate).toLocaleDateString('de-DE', { day:'2-digit', month:'2-digit', year:'numeric' }); }
                catch (_) { endLabel = g.endDate; }
            }
            const worth = g.worth ? '<span class="fg-worth-badge">' + esc(g.worth) + '</span>' : '';
            const linkCls = platform === 'epic' ? 'fg-game-card-link--epic' : 'fg-game-card-link--steam';
            el.innerHTML +=
                '<div class="fg-game-card">' +
                (g.image ? '<img src="' + esc(g.image) + '" alt="' + esc(g.title) + '" class="fg-game-card-img" loading="lazy" onerror="this.style.display=\'none\'">' : '') +
                '<div class="fg-game-card-body">' +
                    '<div class="fg-game-card-title">' + esc(g.title) + '</div>' +
                    (g.description ? '<div class="fg-game-card-desc">' + esc(g.description) + '</div>' : '') +
                    '<div class="fg-game-card-footer">' +
                        '<div>' + (endLabel ? '<span class="fg-game-card-end">' + esc(endLabel) + '</span>' : '') + worth + '</div>' +
                        '<a href="' + esc(g.url) + '" target="_blank" rel="noopener" class="fg-game-card-link ' + linkCls + '">Holen →</a>' +
                    '</div>' +
                '</div>' +
                '</div>';
        }
    }

    async function loadGames() {
        const btn = document.getElementById('fg-reload-btn');
        btn.disabled = true;
        renderSkeletons('fg-epic-grid');
        renderSkeletons('fg-steam-grid');
        try {
            const d = await postJson('fetch_games');
            if (!d.ok) { flash(d.error || 'Fehler beim Laden.', false); return; }
            renderGames('fg-epic-grid',  d.data && d.data.epic  || [], 'Keine aktuellen Epic Games Free Games gefunden.', 'epic');
            renderGames('fg-steam-grid', d.data && d.data.steam || [], 'Keine aktuellen Steam-Giveaways gefunden.', 'steam');
        } catch (_) {
            flash('Netzwerkfehler beim Laden der Spiele.', false);
        } finally {
            btn.disabled = false;
        }
    }

    document.getElementById('fg-reload-btn').addEventListener('click', loadGames);
    loadGames();

    // ── Command toggle ────────────────────────────────────────────────────────
    const cmdToggle = document.getElementById('fg-cmd-toggle');
    if (cmdToggle) {
        cmdToggle.addEventListener('change', function () {
            const on = cmdToggle.checked;
            const fd = new URLSearchParams();
            fd.set('_bh_mod_action',  'toggle');
            fd.set('_bh_mod_key',     'free-games');
            fd.set('_bh_mod_enabled', on ? '1' : '0');
            fetch(location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: fd.toString(),
            })
            .then(r => r.json())
            .then(res => { if (!res.ok) cmdToggle.checked = !on; })
            .catch(() => { cmdToggle.checked = !on; });
        });
    }
}());
</script>
