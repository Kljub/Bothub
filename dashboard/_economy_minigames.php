<?php
declare(strict_types=1);
/** @var int|null $currentBotId */
/** @var int $userId */

if (!isset($currentBotId) || !is_int($currentBotId) || $currentBotId <= 0) {
    echo '<p style="color:#fca5a5;padding:24px">Kein Bot ausgewählt.</p>';
    return;
}

$botId   = $currentBotId;
$pdo     = bh_get_pdo();
$guildId = trim((string)($_GET['guild_id'] ?? ''));

$flash  = [];
$errors = [];

// ── Commands with configurable cooldowns (key => default in seconds) ──────────
$cooldownDefaults = [
    // Economy rewards
    'beg'       => 180,
    'hourly'    => 3600,
    'daily'     => 86400,
    'weekly'    => 604800,
    'monthly'   => 2592000,
    'present'   => 604800,
    'work'      => 3600,
    // Crime / steal
    'crime'     => 600,
    'rob'       => 600,
    'hunt'      => 60,
    // Minigames
    'fish'      => 1800,
    'hangman'   => 0,
    'coinflip'  => 0,
    'blackjack' => 0,
    'mines'     => 0,
    'crash'     => 0,
    'roulette'  => 0,
    'slots'     => 0,
];

// ── Economy commands definition ────────────────────────────────────────────────
$ecoCommandGroups = [
    'Economy' => [
        ['key' => 'balance',                'name' => '/balance',                'desc' => 'Zeigt dein aktuelles Guthaben an.'],
        ['key' => 'balance-manage-add',    'name' => '/balance-manage add',    'desc' => 'Fügt einem User Guthaben hinzu. (Admin)'],
        ['key' => 'balance-manage-remove', 'name' => '/balance-manage remove', 'desc' => 'Entfernt Guthaben von einem User. (Admin)'],
        ['key' => 'daily',    'name' => '/daily',    'desc' => 'Täglicher Bonus (1× pro Tag).'],
        ['key' => 'hourly',   'name' => '/hourly',   'desc' => 'Stündliche Belohnung abholen.'],
        ['key' => 'weekly',   'name' => '/weekly',   'desc' => 'Wöchentliche Belohnung abholen.'],
        ['key' => 'monthly',  'name' => '/monthly',  'desc' => 'Monatliche Belohnung abholen.'],
        ['key' => 'present',  'name' => '/present',  'desc' => 'Wöchentliches Zufallsgeschenk auspacken.'],
        ['key' => 'beg',      'name' => '/beg',      'desc' => 'Um Geld betteln.'],
        ['key' => 'work',     'name' => '/work',     'desc' => 'Arbeite und verdiene Coins.'],
        ['key' => 'crime',    'name' => '/crime',    'desc' => 'Ein Verbrechen begehen (40 % Erfolg).'],
        ['key' => 'rob',      'name' => '/rob',      'desc' => 'Einen anderen User ausrauben.'],
        ['key' => 'hunt',     'name' => '/hunt',     'desc' => 'Auf die Jagd gehen.'],
        ['key' => 'deposit',  'name' => '/deposit',  'desc' => 'Geld auf die Bank einzahlen.'],
        ['key' => 'withdraw', 'name' => '/withdraw', 'desc' => 'Geld von der Bank abheben.'],
        ['key' => 'transfer', 'name' => '/transfer', 'desc' => 'Geld an einen anderen User senden.'],
        ['key' => 'bank',     'name' => '/bank',     'desc' => 'Bankkonto und Zinsinformationen.'],
        ['key' => 'leaderboard', 'name' => '/leaderboard', 'desc' => 'Top Nutzer nach Gesamtvermögen.'],
    ],
    'Shop' => [
        ['key' => 'shop',      'name' => '/shop',      'desc' => 'Zeigt den Server-Shop an.'],
        ['key' => 'buy',       'name' => '/buy',        'desc' => 'Item aus dem Shop kaufen.'],
        ['key' => 'inventory', 'name' => '/inventory',  'desc' => 'Dein Inventar anzeigen.'],
        ['key' => 'use',       'name' => '/use',        'desc' => 'Item aus dem Inventar benutzen.'],
    ],
    'Jobs' => [
        ['key' => 'jobs', 'name' => '/jobs', 'desc' => 'Alle verfügbaren Jobs anzeigen.'],
        ['key' => 'job',  'name' => '/job',  'desc' => 'Job auswählen oder damit arbeiten.'],
    ],
    'Minigames & Fishing' => [
        ['key' => 'coinflip',  'name' => '/coinflip',  'desc' => 'Münzwurf: Kopf oder Zahl.'],
        ['key' => 'blackjack', 'name' => '/blackjack', 'desc' => 'Blackjack spielen.'],
        ['key' => 'mines',     'name' => '/mines',     'desc' => 'Minesweeper Minigame.'],
        ['key' => 'crash',     'name' => '/crash',     'desc' => 'Crash — steigender Multiplikator, stoppe rechtzeitig!'],
        ['key' => 'roulette',  'name' => '/roulette',  'desc' => 'Roulette — setze auf Schwarz, Rot oder Grün.'],
        ['key' => 'slots',     'name' => '/slots',     'desc' => 'Spielautomat — drehe die Walzen.'],
        ['key' => 'hangman',   'name' => '/hangman',   'desc' => 'Hangman: Errate das Wort und gewinne.'],
        ['key' => 'fish',      'name' => '/fish',      'desc' => 'Angeln mit Loot-System.'],
    ],
];

// Load guilds
$guilds = [];
try {
    $gs = $pdo->prepare('SELECT guild_id, guild_name FROM bot_guilds WHERE bot_id = :bid ORDER BY guild_name ASC');
    $gs->execute([':bid' => $botId]);
    $guilds = $gs->fetchAll();
} catch (Throwable) {}

if ($guildId === '' && count($guilds) > 0) {
    $guildId = (string)$guilds[0]['guild_id'];
}

// ── Load command states ────────────────────────────────────────────────────────
$enabledCommands  = [];
$settingsCommands = [];
try {
    $cs = $pdo->prepare(
        "SELECT command_key, is_enabled, settings_json FROM commands WHERE bot_id = :bid AND command_type = 'predefined'"
    );
    $cs->execute([':bid' => $botId]);
    foreach ($cs->fetchAll() as $row) {
        $k = (string)$row['command_key'];
        $enabledCommands[$k] = (int)$row['is_enabled'];
        $raw = $row['settings_json'];
        $settingsCommands[$k] = ($raw !== null) ? (json_decode($raw, true) ?: []) : [];
    }
} catch (Throwable) {}

$post       = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : [];
$postAction = trim((string)($post['eco_action'] ?? ''));

// ── Handle POST ────────────────────────────────────────────────────────────────
if ($postAction !== '') {
    try {
        // SECURITY: Verify bot belongs to the user before doing anything
        $ownerCheck = $pdo->prepare('SELECT id FROM bot_instances WHERE id = :bid AND owner_user_id = :uid LIMIT 1');
        $ownerCheck->execute([':bid' => $botId, ':uid' => $userId]);
        if (!$ownerCheck->fetch()) {
            throw new RuntimeException('Berechtigung verweigert: Dieser Bot gehört dir nicht.');
        }

        $pdo->beginTransaction();

        // Save command toggles
        if ($postAction === 'save_commands') {
            $allKeys = [];
            foreach ($ecoCommandGroups as $cmds) {
                foreach ($cmds as $cmd) { $allKeys[] = $cmd['key']; }
            }
            $stmt = $pdo->prepare(
                "INSERT INTO commands (bot_id, command_key, command_type, name, description, is_enabled, settings_json, created_at, updated_at)
                 VALUES (:bid, :key, 'predefined', :name, :desc, :enabled, :sjson, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), settings_json = VALUES(settings_json), updated_at = NOW()"
            );
            foreach ($allKeys as $key) {
                $enabled = (($post['enabled'][$key] ?? '0') === '1') ? 1 : 0;
                $sRaw  = $post['settings_json'][$key] ?? null;
                $sJson = null;
                if ($sRaw !== null) {
                    $dec = json_decode($sRaw, true);
                    if (is_array($dec)) { $sJson = json_encode($dec, JSON_UNESCAPED_UNICODE); }
                }
                $cmdInfo = null;
                foreach ($ecoCommandGroups as $cmds) {
                    foreach ($cmds as $c) { if ($c['key'] === $key) { $cmdInfo = $c; break 2; } }
                }
                $stmt->execute([
                    ':bid'     => $botId,
                    ':key'     => $key,
                    ':name'    => $cmdInfo['name'] ?? ('/' . $key),
                    ':desc'    => $cmdInfo['desc'] ?? '',
                    ':enabled' => $enabled,
                    ':sjson'   => $sJson,
                ]);
                $enabledCommands[$key] = $enabled;
                if ($sJson !== null) { $settingsCommands[$key] = json_decode($sJson, true) ?: []; }
            }
            $flash[] = 'Commands gespeichert.';
            require_once dirname(__DIR__) . '/functions/custom_commands.php';
            bh_notify_slash_sync($botId);
        }

        if ($postAction === 'save_settings' && $guildId !== '') {
            $sym   = trim((string)($post['currency_symbol']    ?? '🪙'));
            $name  = trim((string)($post['currency_name']      ?? 'Coins'));
            $daily = max(1, (int)($post['daily_amount']        ?? 200));
            $wmin  = max(1, (int)($post['work_min']            ?? 50));
            $wmax  = max($wmin, (int)($post['work_max']        ?? 150));
            $rate  = max(0.0, min(100.0, (float)($post['bank_interest_rate'] ?? 0)));
            $pdo->prepare(
                'INSERT INTO eco_settings (bot_id, guild_id, currency_symbol, currency_name, daily_amount, work_min, work_max, bank_interest_rate)
                 VALUES (:bid, :gid, :sym, :name, :daily, :wmin, :wmax, :rate)
                 ON DUPLICATE KEY UPDATE
                   currency_symbol = VALUES(currency_symbol), currency_name = VALUES(currency_name),
                   daily_amount = VALUES(daily_amount), work_min = VALUES(work_min),
                   work_max = VALUES(work_max), bank_interest_rate = VALUES(bank_interest_rate)'
            )->execute([':bid' => $botId, ':gid' => $guildId, ':sym' => $sym ?: '🪙', ':name' => $name ?: 'Coins',
                        ':daily' => $daily, ':wmin' => $wmin, ':wmax' => $wmax, ':rate' => $rate]);
            $flash[] = 'Einstellungen gespeichert.';
        }

        if ($postAction === 'add_shop_item' && $guildId !== '') {
            $iname  = trim((string)($post['item_name']        ?? ''));
            $idesc  = trim((string)($post['item_description'] ?? ''));
            $iprice = max(1, (int)($post['item_price']        ?? 100));
            $iemoji = trim((string)($post['item_emoji']       ?? '🎁')) ?: '🎁';
            $istock = (int)($post['item_stock']               ?? -1);
            if ($iname === '') throw new RuntimeException('Item-Name darf nicht leer sein.');
            $pdo->prepare(
                'INSERT INTO eco_shop_items (bot_id, guild_id, name, description, price, emoji, stock) VALUES (?,?,?,?,?,?,?)'
            )->execute([$botId, $guildId, $iname, $idesc ?: null, $iprice, $iemoji, $istock]);
            $flash[] = 'Item "' . htmlspecialchars($iname, ENT_QUOTES, 'UTF-8') . '" hinzugefügt.';
        }

        if ($postAction === 'delete_shop_item' && $guildId !== '') {
            $itemId = (int)($post['item_id'] ?? 0);
            if ($itemId > 0) {
                $pdo->prepare('DELETE FROM eco_shop_items WHERE id = ? AND bot_id = ? AND guild_id = ?')->execute([$itemId, $botId, $guildId]);
                $flash[] = 'Item gelöscht.';
            }
        }

        if ($postAction === 'add_job' && $guildId !== '') {
            $jname  = trim((string)($post['job_name']        ?? ''));
            $jdesc  = trim((string)($post['job_description'] ?? ''));
            $jmin   = max(1, (int)($post['job_min_wage']     ?? 100));
            $jmax   = max($jmin, (int)($post['job_max_wage'] ?? 200));
            $jcd    = max(60, (int)($post['job_cooldown']    ?? 3600));
            $jemoji = trim((string)($post['job_emoji']       ?? '💼')) ?: '💼';
            if ($jname === '') throw new RuntimeException('Job-Name darf nicht leer sein.');
            $pdo->prepare(
                'INSERT INTO eco_jobs (bot_id, guild_id, name, description, min_wage, max_wage, cooldown_seconds, emoji) VALUES (?,?,?,?,?,?,?,?)'
            )->execute([$botId, $guildId, $jname, $jdesc ?: null, $jmin, $jmax, $jcd, $jemoji]);
            $flash[] = 'Job "' . htmlspecialchars($jname, ENT_QUOTES, 'UTF-8') . '" hinzugefügt.';
        }

        if ($postAction === 'delete_job' && $guildId !== '') {
            $jobId = (int)($post['job_id'] ?? 0);
            if ($jobId > 0) {
                $pdo->prepare('DELETE FROM eco_jobs WHERE id = ? AND bot_id = ? AND guild_id = ?')->execute([$jobId, $botId, $guildId]);
                $flash[] = 'Job gelöscht.';
            }
        }

        if ($postAction === 'add_hangman_word') {
            $hw = mb_strtoupper(trim((string)($post['hangman_word'] ?? '')));
            if ($hw === '') throw new RuntimeException('Wort darf nicht leer sein.');
            if (mb_strlen($hw) < 2 || mb_strlen($hw) > 64) throw new RuntimeException('Wort muss zwischen 2 und 64 Zeichen lang sein.');
            if (!preg_match('/^[A-ZÄÖÜ\-]+$/u', $hw)) throw new RuntimeException('Wort darf nur Buchstaben (A–Z, Ä, Ö, Ü) und Bindestriche enthalten.');
            $pdo->prepare('INSERT INTO eco_hangman_words (bot_id, word) VALUES (?, ?)')->execute([$botId, $hw]);
            $flash[] = 'Wort "' . htmlspecialchars($hw, ENT_QUOTES, 'UTF-8') . '" hinzugefügt.';
        }

        if ($postAction === 'delete_hangman_word') {
            $wid = (int)($post['word_id'] ?? 0);
            if ($wid > 0) {
                $pdo->prepare('DELETE FROM eco_hangman_words WHERE id = ? AND bot_id = ?')->execute([$wid, $botId]);
                $flash[] = 'Wort gelöscht.';
            }
        }

        if ($postAction === 'add_currency' && $guildId !== '') {
            $csym  = trim((string)($post['currency_symbol'] ?? '')) ?: '🪙';
            $cname = trim((string)($post['currency_name']   ?? '')) ?: 'Coins';
            $cdef  = (int)(($post['currency_default'] ?? '0') === '1');
            if ($cdef) {
                $pdo->prepare('UPDATE eco_currencies SET is_default = 0 WHERE bot_id = ? AND guild_id = ?')
                    ->execute([$botId, $guildId]);
            }
            $pdo->prepare(
                'INSERT INTO eco_currencies (bot_id, guild_id, symbol, name, is_default) VALUES (?,?,?,?,?)'
            )->execute([$botId, $guildId, $csym, $cname, $cdef]);
            $flash[] = 'Währung "' . htmlspecialchars($cname, ENT_QUOTES, 'UTF-8') . '" hinzugefügt.';
        }

        if ($postAction === 'delete_currency' && $guildId !== '') {
            $cid = (int)($post['currency_id'] ?? 0);
            if ($cid > 0) {
                $pdo->prepare('DELETE FROM eco_currencies WHERE id = ? AND bot_id = ? AND guild_id = ?')
                    ->execute([$cid, $botId, $guildId]);
                $flash[] = 'Währung gelöscht.';
            }
        }

        if ($postAction === 'set_default_currency' && $guildId !== '') {
            $cid = (int)($post['currency_id'] ?? 0);
            if ($cid > 0) {
                $pdo->prepare('UPDATE eco_currencies SET is_default = 0 WHERE bot_id = ? AND guild_id = ?')
                    ->execute([$botId, $guildId]);
                $pdo->prepare('UPDATE eco_currencies SET is_default = 1 WHERE id = ? AND bot_id = ? AND guild_id = ?')
                    ->execute([$cid, $botId, $guildId]);
                $flash[] = 'Standardwährung gesetzt.';
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = 'Fehler: ' . $e->getMessage();
    }
}

// ── Load data ──────────────────────────────────────────────────────────────────
$ecoSettings   = ['currency_symbol' => '🪙', 'currency_name' => 'Coins', 'daily_amount' => 200, 'work_min' => 50, 'work_max' => 150, 'bank_interest_rate' => 0];
$shopItems     = [];
$jobs          = [];
$currencies    = [];
$hangmanWords  = [];

// Hangman words are per bot_id (not per guild)
try {
    $hw = $pdo->prepare('SELECT id, word FROM eco_hangman_words WHERE bot_id = ? ORDER BY word ASC');
    $hw->execute([$botId]);
    $hangmanWords = $hw->fetchAll();
} catch (Throwable) {}

if ($guildId !== '') {
    try {
        $rs = $pdo->prepare('SELECT * FROM eco_settings WHERE bot_id = ? AND guild_id = ? LIMIT 1');
        $rs->execute([$botId, $guildId]);
        $row = $rs->fetch();
        if ($row) $ecoSettings = array_merge($ecoSettings, $row);
    } catch (Throwable) {}
    try {
        $si = $pdo->prepare('SELECT * FROM eco_shop_items WHERE bot_id = ? AND guild_id = ? AND is_active = 1 ORDER BY price ASC');
        $si->execute([$botId, $guildId]);
        $shopItems = $si->fetchAll();
    } catch (Throwable) {}
    try {
        $jq = $pdo->prepare('SELECT * FROM eco_jobs WHERE bot_id = ? AND guild_id = ? AND is_active = 1 ORDER BY name ASC');
        $jq->execute([$botId, $guildId]);
        $jobs = $jq->fetchAll();
    } catch (Throwable) {}
    try {
        $cq = $pdo->prepare('SELECT * FROM eco_currencies WHERE bot_id = ? AND guild_id = ? ORDER BY is_default DESC, sort_order ASC, id ASC');
        $cq->execute([$botId, $guildId]);
        $currencies = $cq->fetchAll();
    } catch (Throwable) {}
}

$baseUrl = '/dashboard/economy-minigames?bot_id=' . $botId . ($guildId !== '' ? '&guild_id=' . urlencode($guildId) : '');
$esc = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// JS data for BhPerm (command-accordion.js)
$jsCmdData = ['botId' => $botId, 'commands' => []];
foreach ($ecoCommandGroups as $cmds) {
    foreach ($cmds as $cmd) {
        $k = $cmd['key'];
        $jsCmdData['commands'][$k] = ['settings' => $settingsCommands[$k] ?? []];
    }
}
?>
<link rel="stylesheet" href="/assets/css/_economy_minigames.css?v=1">

<div class="bh-eco-page">

    <div class="bh-eco-head">
        <div class="bh-eco-kicker">ECONOMY & MINIGAMES</div>
        <h1 class="bh-eco-title">Economy & Minigames</h1>
    </div>

    <?php foreach ($flash as $msg): ?>
        <div class="bh-alert bh-alert--ok"><?= $esc($msg) ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $err): ?>
        <div class="bh-alert bh-alert--err"><?= $esc($err) ?></div>
    <?php endforeach; ?>

    <!-- ── Commands ──────────────────────────────────────────────────────────── -->
    <div class="bh-eco-card" id="bh-eco-cmds-card">
        <div class="bh-eco-card__header bh-eco-card__header--toggle" id="bh-eco-cmds-toggle">
            <div class="bh-eco-card__header-left">
                <div class="bh-eco-card__kicker">COMMANDS</div>
                <div class="bh-eco-card__title">Commands aktivieren</div>
            </div>
            <svg class="bh-eco-collapse-chevron" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
            </svg>
        </div>
        <div class="bh-eco-card__collapsible" id="bh-eco-cmds-body">
            <form method="post" action="<?= $esc($baseUrl) ?>" data-autosave id="bh-eco-cmds-form">
                <input type="hidden" name="eco_action" value="save_commands">
                <?php foreach ($ecoCommandGroups as $groupName => $cmds): ?>
                    <div class="bh-eco-cmd-section">
                        <div class="bh-eco-cmd-section__title"><?= $esc($groupName) ?></div>
                        <?php foreach ($cmds as $cmd): ?>
                            <?php
                            $key             = $cmd['key'];
                            $enabled         = !empty($enabledCommands[$key]);
                            $settingsData    = $settingsCommands[$key] ?? [];
                            $settingsJsonStr = json_encode($settingsData, JSON_UNESCAPED_UNICODE);
                            ?>
                            <div class="bh-eco-cmd-accordion">
                                <div class="bh-eco-feature bh-eco-feature--cmd">
                                    <div class="bh-eco-feature__left">
                                        <div class="bh-eco-feature__title"><?= $esc($cmd['name']) ?></div>
                                        <div class="bh-eco-feature__desc"><?= $esc($cmd['desc']) ?></div>
                                    </div>
                                    <div class="bh-eco-feature__right">
                                        <label class="bh-toggle">
                                            <input class="bh-toggle-input" type="hidden" name="enabled[<?= $esc($key) ?>]" value="0">
                                            <input class="bh-toggle-input" type="checkbox" name="enabled[<?= $esc($key) ?>]" value="1" <?= $enabled ? 'checked' : '' ?>>
                                            <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                                        </label>
                                        <svg class="bh-eco-cmd-chevron" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/>
                                        </svg>
                                    </div>
                                </div>
                                <div class="bh-eco-cmd-panel">
                                    <input type="hidden"
                                           name="settings_json[<?= $esc($key) ?>]"
                                           class="bh-settings-json"
                                           data-command-key="<?= $esc($key) ?>"
                                           value="<?= $esc($settingsJsonStr) ?>">
                                    <div class="bh-perm-panel" data-command-key="<?= $esc($key) ?>"></div>
                                    <?php if (isset($cooldownDefaults[$key])): ?>
                                        <?php $cdVal = (int)($settingsData['cooldown'] ?? $cooldownDefaults[$key]); ?>
                                        <div class="bh-eco-cd-row">
                                            <label class="bh-eco-cd-label" for="cd_<?= $esc($key) ?>">⏳ Cooldown</label>
                                            <input type="number"
                                                   id="cd_<?= $esc($key) ?>"
                                                   class="bh-input bh-eco-cd-input"
                                                   data-cmd-key="<?= $esc($key) ?>"
                                                   value="<?= $cdVal ?>"
                                                   min="0" max="604800" step="1"
                                                   style="width:110px;">
                                            <span class="bh-eco-cd-hint">Sekunden &nbsp;·&nbsp; 0 = kein Cooldown &nbsp;·&nbsp; aktuell: <strong><?php
                                                if ($cdVal === 0) echo 'aus';
                                                elseif ($cdVal < 60) echo $cdVal . 's';
                                                elseif ($cdVal < 3600) echo round($cdVal/60) . ' min';
                                                else echo round($cdVal/3600, 1) . ' h';
                                            ?></strong></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </form>
        </div>
    </div>

    <!-- ── Guild Selector ────────────────────────────────────────────────────── -->
    <div class="bh-eco-card">
        <div class="bh-eco-card__header">
            <div class="bh-eco-card__header-left">
                <div class="bh-eco-card__kicker">KONFIGURATION</div>
                <div class="bh-eco-card__title">Server-Einstellungen</div>
            </div>
        </div>
        <div class="bh-eco-guild-bar">
            <?php if (count($guilds) === 0): ?>
                <div class="bh-eco-guild-empty">Keine Server synchronisiert. Starte den Core und warte bis die Guilds geladen werden.</div>
            <?php else: ?>
                <div class="bh-eco-guild-label">Server auswählen</div>
                <div class="bh-eco-guild-list">
                    <?php foreach ($guilds as $g): ?>
                        <?php $gid = (string)$g['guild_id']; ?>
                        <a href="<?= $esc('/dashboard/economy-minigames?bot_id=' . $botId . '&guild_id=' . urlencode($gid)) ?>"
                           class="bh-eco-guild-btn <?= $gid === $guildId ? 'bh-eco-guild-btn--active' : '' ?>">
                            <?= $esc((string)($g['guild_name'] ?? $gid)) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($guildId !== ''): ?>

    <!-- ── Currencies ────────────────────────────────────────────────────────── -->
    <div class="bh-eco-card">
        <div class="bh-eco-card__header">
            <div class="bh-eco-card__header-left">
                <div class="bh-eco-card__kicker">WÄHRUNGEN</div>
                <div class="bh-eco-card__title">💰 Currencies</div>
            </div>
            <span class="bh-eco-card__badge"><?= count($currencies) ?> Währungen</span>
        </div>

        <?php if (count($currencies) > 0): ?>
            <div class="bh-eco-table-wrap">
                <table class="bh-eco-table">
                    <thead><tr><th>Symbol</th><th>Name</th><th>Standard</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($currencies as $cur): ?>
                            <tr>
                                <td><span class="bh-eco-emoji-badge"><?= $esc((string)$cur['symbol']) ?></span></td>
                                <td><span class="bh-eco-table__name"><?= $esc((string)$cur['name']) ?></span></td>
                                <td>
                                    <?php if ($cur['is_default']): ?>
                                        <span style="color:#86efac;font-size:12px;font-weight:700;">✓ Standard</span>
                                    <?php else: ?>
                                        <form method="post" action="<?= $esc($baseUrl) ?>" style="display:inline">
                                            <input type="hidden" name="eco_action" value="set_default_currency">
                                            <input type="hidden" name="currency_id" value="<?= (int)$cur['id'] ?>">
                                            <button type="submit" class="bh-eco-btn bh-eco-btn--sm" style="background:transparent;border:1px solid #2e3547;color:#94a3b8;">Als Standard</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td class="bh-eco-table__actions">
                                    <form method="post" action="<?= $esc($baseUrl) ?>" onsubmit="return confirm('Währung löschen?')" style="display:inline">
                                        <input type="hidden" name="eco_action" value="delete_currency">
                                        <input type="hidden" name="currency_id" value="<?= (int)$cur['id'] ?>">
                                        <button type="submit" class="bh-eco-btn bh-eco-btn--sm bh-eco-btn--danger">Löschen</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="bh-eco-empty">Noch keine Währungen. Füge unten eine Währung hinzu.</div>
        <?php endif; ?>

        <form method="post" action="<?= $esc($baseUrl) ?>">
            <input type="hidden" name="eco_action" value="add_currency">
            <div class="bh-eco-add-form">
                <div class="bh-eco-add-form__title">+ Neue Währung</div>
                <div class="bh-eco-add-grid" style="grid-template-columns:1fr 2fr 1fr;max-width:520px;">
                    <div class="bh-eco-add-field">
                        <label class="bh-eco-add-field__label">Symbol / Emoji</label>
                        <input type="text" name="currency_symbol" class="bh-input" maxlength="16" value="🪙" placeholder="🪙">
                    </div>
                    <div class="bh-eco-add-field">
                        <label class="bh-eco-add-field__label">Name *</label>
                        <input type="text" name="currency_name" class="bh-input" maxlength="50" placeholder="Coins" required>
                    </div>
                    <div class="bh-eco-add-field">
                        <label class="bh-eco-add-field__label">Standard?</label>
                        <label class="bh-toggle" style="margin-top:4px;">
                            <input class="bh-toggle-input" type="hidden" name="currency_default" value="0">
                            <input class="bh-toggle-input" type="checkbox" name="currency_default" value="1">
                            <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                        </label>
                    </div>
                </div>
                <div style="display:flex;justify-content:flex-end;margin-top:12px;">
                    <button type="submit" class="bh-eco-btn">+ Hinzufügen</button>
                </div>
            </div>
        </form>
    </div>

    <!-- ── Economy Settings ──────────────────────────────────────────────────── -->
    <div class="bh-eco-card">
        <div class="bh-eco-card__header">
            <div class="bh-eco-card__header-left">
                <div class="bh-eco-card__kicker">EINSTELLUNGEN</div>
                <div class="bh-eco-card__title">⚙️ Economy Einstellungen</div>
            </div>
        </div>
        <form method="post" action="<?= $esc($baseUrl) ?>">
            <input type="hidden" name="eco_action" value="save_settings">
            <div class="bh-eco-settings-grid">
                <div class="bh-eco-field">
                    <label class="bh-eco-field__label">Währungssymbol</label>
                    <input type="text" name="currency_symbol" class="bh-input"
                           value="<?= $esc((string)$ecoSettings['currency_symbol']) ?>" maxlength="8" placeholder="🪙">
                </div>
                <div class="bh-eco-field">
                    <label class="bh-eco-field__label">Währungsname</label>
                    <input type="text" name="currency_name" class="bh-input"
                           value="<?= $esc((string)$ecoSettings['currency_name']) ?>" maxlength="30" placeholder="Coins">
                </div>
                <div class="bh-eco-field">
                    <label class="bh-eco-field__label">Daily Bonus</label>
                    <input type="number" name="daily_amount" class="bh-input"
                           value="<?= (int)$ecoSettings['daily_amount'] ?>" min="1" max="99999">
                </div>
                <div class="bh-eco-field">
                    <label class="bh-eco-field__label">Work Min</label>
                    <input type="number" name="work_min" class="bh-input"
                           value="<?= (int)$ecoSettings['work_min'] ?>" min="1" max="99999">
                </div>
                <div class="bh-eco-field">
                    <label class="bh-eco-field__label">Work Max</label>
                    <input type="number" name="work_max" class="bh-input"
                           value="<?= (int)$ecoSettings['work_max'] ?>" min="1" max="99999">
                </div>
                <div class="bh-eco-field">
                    <label class="bh-eco-field__label">Bank Zinsen (%)</label>
                    <input type="number" name="bank_interest_rate" class="bh-input"
                           value="<?= number_format((float)$ecoSettings['bank_interest_rate'], 2) ?>"
                           min="0" max="100" step="0.01">
                    <span class="bh-eco-field__hint">0 = deaktiviert</span>
                </div>
            </div>
            <div class="bh-eco-save-row">
                <button type="submit" class="bh-eco-btn">Speichern</button>
            </div>
        </form>
    </div>

    <!-- ── Shop Items ────────────────────────────────────────────────────────── -->
    <div class="bh-eco-card">
        <div class="bh-eco-card__header">
            <div class="bh-eco-card__header-left">
                <div class="bh-eco-card__kicker">SHOP</div>
                <div class="bh-eco-card__title">🛒 Shop Items</div>
            </div>
            <span class="bh-eco-card__badge"><?= count($shopItems) ?> Items</span>
        </div>

        <?php if (count($shopItems) > 0): ?>
            <div class="bh-eco-table-wrap">
                <table class="bh-eco-table">
                    <thead><tr><th>Item</th><th>Preis</th><th>Bestand</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($shopItems as $item): ?>
                            <tr>
                                <td>
                                    <span class="bh-eco-emoji-badge"><?= $esc((string)$item['emoji']) ?></span>
                                    <span class="bh-eco-table__name"><?= $esc((string)$item['name']) ?></span>
                                    <?php if (!empty($item['description'])): ?>
                                        <div class="bh-eco-table__desc"><?= $esc((string)$item['description']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= (int)$item['price'] ?> <?= $esc((string)$ecoSettings['currency_symbol']) ?></td>
                                <td><?= (int)$item['stock'] < 0 ? '∞' : (int)$item['stock'] ?></td>
                                <td class="bh-eco-table__actions">
                                    <form method="post" action="<?= $esc($baseUrl) ?>" onsubmit="return confirm('Item löschen?')">
                                        <input type="hidden" name="eco_action" value="delete_shop_item">
                                        <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                                        <button type="submit" class="bh-eco-btn bh-eco-btn--sm bh-eco-btn--danger">Löschen</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="bh-eco-empty">Noch keine Items. Füge unten ein Item hinzu.</div>
        <?php endif; ?>

        <form method="post" action="<?= $esc($baseUrl) ?>">
            <input type="hidden" name="eco_action" value="add_shop_item">
            <div class="bh-eco-add-form">
                <div class="bh-eco-add-form__title">+ Neues Item</div>
                <div class="bh-eco-add-grid">
                    <div class="bh-eco-add-field">
                        <label class="bh-eco-add-field__label">Name *</label>
                        <input type="text" name="item_name" class="bh-input" maxlength="80" placeholder="VIP Rolle" required>
                    </div>
                    <div class="bh-eco-add-field">
                        <label class="bh-eco-add-field__label">Beschreibung</label>
                        <input type="text" name="item_description" class="bh-input" maxlength="255" placeholder="Optional">
                    </div>
                    <div class="bh-eco-add-field">
                        <label class="bh-eco-add-field__label">Preis</label>
                        <input type="number" name="item_price" class="bh-input" min="1" value="100">
                    </div>
                    <div class="bh-eco-add-field">
                        <label class="bh-eco-add-field__label">Emoji</label>
                        <input type="text" name="item_emoji" class="bh-input" maxlength="16" value="🎁">
                    </div>
                    <div class="bh-eco-add-field">
                        <label class="bh-eco-add-field__label">Bestand (-1=∞)</label>
                        <input type="number" name="item_stock" class="bh-input" value="-1">
                    </div>
                </div>
                <div style="display:flex;justify-content:flex-end;margin-top:12px;">
                    <button type="submit" class="bh-eco-btn">+ Hinzufügen</button>
                </div>
            </div>
        </form>
    </div>

    <!-- ── Jobs ──────────────────────────────────────────────────────────────── -->
    <div class="bh-eco-card">
        <div class="bh-eco-card__header">
            <div class="bh-eco-card__header-left">
                <div class="bh-eco-card__kicker">JOBS</div>
                <div class="bh-eco-card__title">💼 Jobs</div>
            </div>
            <span class="bh-eco-card__badge"><?= count($jobs) ?> Jobs</span>
        </div>

        <?php if (count($jobs) > 0): ?>
            <div class="bh-eco-table-wrap">
                <table class="bh-eco-table">
                    <thead><tr><th>Job</th><th>Lohn</th><th>Cooldown</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($jobs as $job): ?>
                            <tr>
                                <td>
                                    <span class="bh-eco-emoji-badge"><?= $esc((string)$job['emoji']) ?></span>
                                    <span class="bh-eco-table__name"><?= $esc((string)$job['name']) ?></span>
                                    <span style="font-size:11px;color:#64748b;margin-left:6px;">(ID: <?= (int)$job['id'] ?>)</span>
                                    <?php if (!empty($job['description'])): ?>
                                        <div class="bh-eco-table__desc"><?= $esc((string)$job['description']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= (int)$job['min_wage'] ?>–<?= (int)$job['max_wage'] ?> <?= $esc((string)$ecoSettings['currency_symbol']) ?></td>
                                <td><?php $cd = (int)$job['cooldown_seconds']; echo $cd >= 3600 ? round($cd/3600, 1).'h' : round($cd/60).'min'; ?></td>
                                <td class="bh-eco-table__actions">
                                    <form method="post" action="<?= $esc($baseUrl) ?>" onsubmit="return confirm('Job löschen?')">
                                        <input type="hidden" name="eco_action" value="delete_job">
                                        <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                                        <button type="submit" class="bh-eco-btn bh-eco-btn--sm bh-eco-btn--danger">Löschen</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="bh-eco-empty">Noch keine Jobs. Füge unten einen Job hinzu.</div>
        <?php endif; ?>

        <form method="post" action="<?= $esc($baseUrl) ?>">
            <input type="hidden" name="eco_action" value="add_job">
            <div class="bh-eco-add-form">
                <div class="bh-eco-add-form__title">+ Neuen Job</div>
                <div class="bh-eco-add-grid bh-eco-add-grid--jobs">
                    <div class="bh-eco-add-field">
                        <label class="bh-eco-add-field__label">Name *</label>
                        <input type="text" name="job_name" class="bh-input" maxlength="80" placeholder="Programmierer" required>
                    </div>
                    <div class="bh-eco-add-field">
                        <label class="bh-eco-add-field__label">Beschreibung</label>
                        <input type="text" name="job_description" class="bh-input" maxlength="255" placeholder="Optional">
                    </div>
                    <div class="bh-eco-add-field">
                        <label class="bh-eco-add-field__label">Min. Lohn</label>
                        <input type="number" name="job_min_wage" class="bh-input" min="1" value="100">
                    </div>
                    <div class="bh-eco-add-field">
                        <label class="bh-eco-add-field__label">Max. Lohn</label>
                        <input type="number" name="job_max_wage" class="bh-input" min="1" value="200">
                    </div>
                    <div class="bh-eco-add-field">
                        <label class="bh-eco-add-field__label">Cooldown (s)</label>
                        <input type="number" name="job_cooldown" class="bh-input" min="60" value="3600">
                    </div>
                    <div class="bh-eco-add-field">
                        <label class="bh-eco-add-field__label">Emoji</label>
                        <input type="text" name="job_emoji" class="bh-input" maxlength="16" value="💼">
                    </div>
                </div>
                <div style="display:flex;justify-content:flex-end;margin-top:12px;">
                    <button type="submit" class="bh-eco-btn">+ Hinzufügen</button>
                </div>
            </div>
        </form>
    </div>

    <?php endif; ?>

    <!-- ── Hangman Word Pool ──────────────────────────────────────────────────── -->
    <div class="bh-eco-card" style="margin-top:24px;">
        <div class="bh-eco-card__header">
            <div class="bh-eco-card__header-left">
                <div class="bh-eco-card__kicker">🪢 HANGMAN</div>
                <div class="bh-eco-card__title">Wort-Pool</div>
                <div class="bh-eco-card__sub">Wörter die der Bot beim /hangman Command verwendet. Gilt für alle Server dieses Bots.</div>
            </div>
        </div>
        <div class="bh-eco-card__body">

            <?php if (count($hangmanWords) > 0): ?>
                <table class="bh-eco-table" style="margin-bottom:20px;">
                    <thead>
                        <tr>
                            <th>Wort</th>
                            <th style="width:80px;text-align:center;">Länge</th>
                            <th style="width:90px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($hangmanWords as $hw): ?>
                            <tr>
                                <td><strong><?= $esc((string)$hw['word']) ?></strong></td>
                                <td style="text-align:center;"><?= mb_strlen((string)$hw['word']) ?></td>
                                <td style="text-align:right;">
                                    <form method="post" action="<?= $esc($baseUrl) ?>" onsubmit="return confirm('Wort löschen?')" style="display:inline;">
                                        <input type="hidden" name="eco_action" value="delete_hangman_word">
                                        <input type="hidden" name="word_id" value="<?= (int)$hw['id'] ?>">
                                        <button type="submit" class="bh-eco-btn bh-eco-btn--sm bh-eco-btn--danger">Löschen</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="bh-eco-empty" style="margin-bottom:20px;">
                    Noch keine Wörter. Der Bot verwendet eingebaute Standardwörter bis du eigene hinzufügst.
                </div>
            <?php endif; ?>

            <form method="post" action="<?= $esc($baseUrl) ?>">
                <input type="hidden" name="eco_action" value="add_hangman_word">
                <div class="bh-eco-add-form">
                    <div class="bh-eco-add-form__title">+ Neues Wort</div>
                    <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                        <div class="bh-eco-add-field" style="flex:1;min-width:200px;">
                            <label class="bh-eco-add-field__label">Wort (wird automatisch in Großbuchstaben umgewandelt) *</label>
                            <input type="text" name="hangman_word" class="bh-input"
                                   maxlength="64" placeholder="z.B. REGENBOGEN" required
                                   pattern="[A-Za-zÄÖÜäöü\-]+" title="Nur Buchstaben und Bindestriche erlaubt">
                        </div>
                        <div>
                            <button type="submit" class="bh-eco-btn">+ Hinzufügen</button>
                        </div>
                    </div>
                    <div style="margin-top:8px;font-size:0.78rem;color:#94a3b8;">
                        Erlaubt: A–Z, Ä, Ö, Ü, Bindestriche. Mindestens 2 Zeichen.
                        <?php if (count($hangmanWords) > 0): ?>
                            &nbsp;·&nbsp; <strong><?= count($hangmanWords) ?></strong> Wörter im Pool.
                        <?php endif; ?>
                    </div>
                </div>
            </form>

        </div>
    </div>

</div>

<script>
window.BhCmdData = <?= json_encode($jsCmdData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
(function () {
    // ── Card-level collapse (Commands header) ────────────────────────────────
    var toggle = document.getElementById('bh-eco-cmds-toggle');
    var card   = document.getElementById('bh-eco-cmds-card');
    if (toggle && card) {
        toggle.addEventListener('click', function () {
            card.classList.toggle('bh-eco-card--collapsed');
        });
    }

    // ── Per-command accordion ────────────────────────────────────────────────
    document.querySelectorAll('.bh-eco-cmd-accordion').forEach(function (acc) {
        var header = acc.querySelector('.bh-eco-feature--cmd');
        var panel  = acc.querySelector('.bh-eco-cmd-panel');
        if (!header || !panel) return;

        header.addEventListener('click', function (e) {
            if (e.target.closest('.bh-toggle')) return;
            var isOpen = acc.classList.contains('is-open');
            document.querySelectorAll('.bh-eco-cmd-accordion.is-open').forEach(function (o) {
                if (o !== acc) o.classList.remove('is-open');
            });
            acc.classList.toggle('is-open', !isOpen);
        });
    });

    // ── Auto-save on toggle change ───────────────────────────────────────────
    var ecoForm = document.getElementById('bh-eco-cmds-form');
    if (ecoForm) {
        ecoForm.addEventListener('change', function (e) {
            if (e.target && e.target.type === 'checkbox') {
                if (typeof BhAutoSave !== 'undefined') BhAutoSave.trigger();
            }
        });
    }

    // ── Sync cooldown inputs into hidden settings_json ───────────────────────
    document.querySelectorAll('.bh-eco-cd-input').forEach(function (input) {
        // Show human-readable current value on load
        function updateHint() {
            var secs = parseInt(input.value, 10) || 0;
            var hint = input.closest('.bh-eco-cd-row')
                           ? input.closest('.bh-eco-cd-row').querySelector('.bh-eco-cd-hint strong')
                           : null;
            if (!hint) return;
            if (secs === 0) { hint.textContent = 'kein Cooldown'; return; }
            var h = Math.floor(secs / 3600);
            var m = Math.floor((secs % 3600) / 60);
            var s = secs % 60;
            var parts = [];
            if (h) parts.push(h + ' h');
            if (m) parts.push(m + ' min');
            if (s) parts.push(s + ' s');
            hint.textContent = parts.join(' ');
        }
        updateHint();

        input.addEventListener('input', updateHint);

        input.addEventListener('change', function () {
            var key = this.getAttribute('data-cmd-key');
            var hidden = document.querySelector('.bh-settings-json[data-command-key="' + key + '"]');
            if (!hidden) return;
            var data = {};
            try { data = JSON.parse(hidden.value) || {}; } catch (_) {}
            data.cooldown = parseInt(this.value, 10) || 0;
            hidden.value = JSON.stringify(data);
            if (typeof BhAutoSave !== 'undefined') BhAutoSave.trigger();
        });
    });
}());
</script>
