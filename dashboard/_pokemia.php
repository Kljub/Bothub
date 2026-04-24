<?php
declare(strict_types=1);
/** @var int|null $currentBotId */

if (!isset($currentBotId) || !is_int($currentBotId) || $currentBotId <= 0) {
    echo '<p style="color:#fca5a5;padding:24px">Kein Bot ausgewählt.</p>';
    return;
}

$botId   = $currentBotId;
$pdo     = bh_get_pdo();
$guildId = trim((string)($_GET['guild_id'] ?? ''));

require_once dirname(__DIR__) . '/functions/db_functions/commands.php';
require_once dirname(__DIR__) . '/functions/custom_commands.php';

$flash  = [];
$errors = [];

// ── Command definitions ───────────────────────────────────────────────────────
$pkmCommands = [
    ['key' => 'pokemia', 'name' => '/pokemia', 'desc' => 'Pokémon fangen, trainieren und kämpfen. (Alle Subcommands)'],
];

$pkmSubCommands = [
    ['key' => 'pokemia-start',       'name' => '/pokemia start',            'desc' => 'Starter-Pokémon wählen (Bisasam, Glumanda, Schiggy)'],
    ['key' => 'pokemia-catch',       'name' => '/pokemia catch',            'desc' => 'Erschienenes wildes Pokémon fangen'],
    ['key' => 'pokemia-list',        'name' => '/pokemia list [seite]',     'desc' => 'Alle eigenen Pokémon auflisten'],
    ['key' => 'pokemia-info',        'name' => '/pokemia info [nummer]',    'desc' => 'Details zu einem Pokémon anzeigen'],
    ['key' => 'pokemia-select',      'name' => '/pokemia select <nummer>',  'desc' => 'Aktives Pokémon wechseln'],
    ['key' => 'pokemia-battle-bot',  'name' => '/pokemia battle bot [1-10]','desc' => 'Gegen den Bot kämpfen (Schwierigkeitsstufe 1–10)'],
    ['key' => 'pokemia-battle-user', 'name' => '/pokemia battle user @user','desc' => 'Gegen einen anderen User kämpfen'],
];

// ── Load guilds ───────────────────────────────────────────────────────────────
$guilds = [];
try {
    $gs = $pdo->prepare('SELECT guild_id, guild_name FROM bot_guilds WHERE bot_id = :bid ORDER BY guild_name ASC');
    $gs->execute([':bid' => $botId]);
    $guilds = $gs->fetchAll();
} catch (Throwable) {}
if ($guildId === '' && count($guilds) > 0) {
    $guildId = (string)$guilds[0]['guild_id'];
}

// ── Seed subcommands ─────────────────────────────────────────────────────────
try {
    $seedStmt = $pdo->prepare(
        "INSERT IGNORE INTO commands (bot_id, command_key, command_type, name, description, is_enabled, created_at, updated_at)
         VALUES (?, ?, 'module', ?, NULL, 1, NOW(), NOW())"
    );
    foreach ($pkmSubCommands as $sc) {
        $seedStmt->execute([$botId, $sc['key'], $sc['key']]);
    }
} catch (Throwable) {}

// ── Load command states ───────────────────────────────────────────────────────
$enabledCommands = [];
try {
    $cs = $pdo->prepare(
        "SELECT command_key, is_enabled FROM commands WHERE bot_id = :bid AND command_key = 'pokemia' LIMIT 1"
    );
    $cs->execute([':bid' => $botId]);
    foreach ($cs->fetchAll() as $row) {
        $enabledCommands[$row['command_key']] = (int)$row['is_enabled'];
    }
} catch (Throwable) {}

// ── Load subcommand states ────────────────────────────────────────────────────
$subCmdEnabled = [];
foreach ($pkmSubCommands as $sc) {
    try { $subCmdEnabled[$sc['key']] = bhcmd_is_enabled($pdo, $botId, $sc['key']); }
    catch (Throwable) { $subCmdEnabled[$sc['key']] = 1; }
}

// ── Load spawn config per guild ───────────────────────────────────────────────
$spawnConfig = ['spawn_channel' => '', 'spawn_rate' => 20];
if ($guildId !== '') {
    try {
        $sq = $pdo->prepare('SELECT spawn_channel, spawn_rate FROM pokemia_guild_config WHERE bot_id = ? AND guild_id = ? LIMIT 1');
        $sq->execute([$botId, $guildId]);
        $row = $sq->fetch();
        if ($row) $spawnConfig = array_merge($spawnConfig, $row);
    } catch (Throwable) {}
}

// ── Load stats ────────────────────────────────────────────────────────────────
$stats = ['users' => 0, 'pokemon' => 0, 'spawns_caught' => 0];
try {
    $stats['users']   = (int)$pdo->query("SELECT COUNT(*) FROM pokemia_users WHERE bot_id = $botId")->fetchColumn();
    $stats['pokemon'] = (int)$pdo->query("SELECT COUNT(*) FROM pokemia_pokemon WHERE bot_id = $botId")->fetchColumn();
    $stats['spawns_caught'] = (int)$pdo->query("SELECT COUNT(*) FROM pokemia_spawn WHERE bot_id = $botId AND caught = 1")->fetchColumn();
} catch (Throwable) {}

// ── AJAX toggle handler ───────────────────────────────────────────────────────
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $field = trim((string)($_POST['field'] ?? ''));
    $val   = ($_POST['value'] ?? '') === '1' ? 1 : 0;
    $allowedKeys = array_column($pkmSubCommands, 'key');
    if (in_array($field, $allowedKeys, true)) {
        bhcmd_set_module_enabled($pdo, $botId, $field, $val);
        try { bh_notify_slash_sync($botId); } catch (Throwable) {}
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'invalid_field']);
    }
    exit;
}

// ── Handle POST ───────────────────────────────────────────────────────────────
$post       = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : [];
$postAction = trim((string)($post['pkm_action'] ?? ''));

if ($postAction !== '') {
    try {
        if ($postAction === 'save_commands') {
            $enabled = (($post['enabled']['pokemia'] ?? '0') === '1') ? 1 : 0;
            $pdo->prepare(
                "INSERT INTO commands (bot_id, command_key, command_type, name, description, is_enabled, created_at, updated_at)
                 VALUES (:bid, 'pokemia', 'predefined', '/pokemia', 'Pokémon fangen, trainieren und kämpfen.', :enabled, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), updated_at = NOW()"
            )->execute([':bid' => $botId, ':enabled' => $enabled]);
            $enabledCommands['pokemia'] = $enabled;
            $flash[] = 'Command gespeichert.';
            bh_notify_slash_sync($botId);
        }

        if ($postAction === 'save_spawn' && $guildId !== '') {
            // SECURITY: Verify bot is actually a member of this guild
            $guildCheck = $pdo->prepare('SELECT id FROM bot_guilds WHERE bot_id = ? AND guild_id = ? LIMIT 1');
            $guildCheck->execute([$botId, $guildId]);
            if (!$guildCheck->fetch()) {
                throw new RuntimeException('Fehler: Der Bot ist nicht auf diesem Server aktiv.');
            }

            $channel = trim((string)($post['spawn_channel'] ?? ''));
            $rate    = max(1, min(500, (int)($post['spawn_rate'] ?? 20)));
            $pdo->prepare(
                "INSERT INTO pokemia_guild_config (bot_id, guild_id, spawn_channel, spawn_rate)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE spawn_channel = VALUES(spawn_channel), spawn_rate = VALUES(spawn_rate)"
            )->execute([$botId, $guildId, $channel ?: null, $rate]);
            $spawnConfig = ['spawn_channel' => $channel, 'spawn_rate' => $rate];
            $flash[] = 'Spawn-Einstellungen gespeichert.';
        }

    } catch (Throwable $e) {
        $errors[] = 'Fehler: ' . $e->getMessage();
    }
}

$baseUrl = '/dashboard/pokemia?bot_id=' . $botId . ($guildId !== '' ? '&guild_id=' . urlencode($guildId) : '');
$esc = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<link rel="stylesheet" href="/assets/css/_economy_minigames.css?v=1">
<link rel="stylesheet" href="/assets/css/_music.css?v=1">

<div class="bh-eco-page">

    <div class="bh-eco-head">
        <div class="bh-eco-kicker">POKEMIA</div>
        <h1 class="bh-eco-title">Pokemia</h1>
        <p class="bh-eco-subtitle">Pokémon fangen, trainieren &amp; kämpfen auf deinem Discord-Server.</p>
    </div>

    <?php foreach ($flash as $msg): ?>
        <div class="bh-alert bh-alert--ok"><?= $esc($msg) ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $err): ?>
        <div class="bh-alert bh-alert--err"><?= $esc($err) ?></div>
    <?php endforeach; ?>

    <!-- ── Stats ──────────────────────────────────────────────────────────────── -->
    <div class="bh-eco-stats-row">
        <div class="bh-eco-stat-box">
            <div class="bh-eco-stat-num"><?= $stats['users'] ?></div>
            <div class="bh-eco-stat-label">Trainer</div>
        </div>
        <div class="bh-eco-stat-box">
            <div class="bh-eco-stat-num"><?= $stats['pokemon'] ?></div>
            <div class="bh-eco-stat-label">Pokémon gefangen</div>
        </div>
        <div class="bh-eco-stat-box">
            <div class="bh-eco-stat-num"><?= $stats['spawns_caught'] ?></div>
            <div class="bh-eco-stat-label">Wild gefangen</div>
        </div>
    </div>

    <!-- ── Command toggle ─────────────────────────────────────────────────────── -->
    <div class="bh-eco-card" id="bh-pkm-cmds-card">
        <div class="bh-eco-card__header bh-eco-card__header--toggle" id="bh-pkm-cmds-toggle">
            <div class="bh-eco-card__header-left">
                <div class="bh-eco-card__kicker">COMMAND</div>
                <div class="bh-eco-card__title">Command aktivieren</div>
            </div>
            <svg class="bh-eco-collapse-chevron" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
            </svg>
        </div>
        <div class="bh-eco-card__collapsible" id="bh-pkm-cmds-body">
            <form method="post" action="<?= $esc($baseUrl) ?>" data-autosave id="bh-pkm-cmds-form">
                <input type="hidden" name="pkm_action" value="save_commands">
                <?php foreach ($pkmCommands as $cmd):
                    $key     = $cmd['key'];
                    $enabled = !empty($enabledCommands[$key]);
                ?>
                <div class="bh-eco-feature bh-eco-feature--cmd" style="border-bottom:none;">
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
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="bh-eco-save-row">
                    <button type="submit" class="bh-eco-btn">Speichern</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Spawn configuration ────────────────────────────────────────────────── -->
    <div class="bh-eco-card">
        <div class="bh-eco-card__header bh-eco-card__header--toggle" id="bh-pkm-spawn-toggle">
            <div class="bh-eco-card__header-left">
                <div class="bh-eco-card__kicker">SPAWN</div>
                <div class="bh-eco-card__title">Spawn-Einstellungen</div>
            </div>
            <svg class="bh-eco-collapse-chevron" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
            </svg>
        </div>
        <div class="bh-eco-card__collapsible" id="bh-pkm-spawn-body">
            <!-- Guild selector -->
            <?php if (count($guilds) > 1): ?>
            <div class="bh-eco-guild-bar">
                <span class="bh-eco-guild-bar__label">Server:</span>
                <?php foreach ($guilds as $g):
                    $gid    = (string)$g['guild_id'];
                    $active = $gid === $guildId;
                    $href   = '/dashboard/pokemia?bot_id=' . $botId . '&guild_id=' . urlencode($gid);
                ?>
                <a href="<?= $esc($href) ?>" class="bh-eco-guild-btn <?= $active ? 'bh-eco-guild-btn--active' : '' ?>">
                    <?= $esc((string)$g['guild_name']) ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($guildId !== ''): ?>
            <form method="post" action="<?= $esc($baseUrl) ?>" data-autosave>
                <input type="hidden" name="pkm_action" value="save_spawn">
                <div class="bh-eco-settings-grid">
                    <div class="bh-eco-field">
                        <label class="bh-eco-label">Spawn-Channel (Channel-ID)</label>
                        <input type="hidden" name="spawn_channel" id="pkm-channel-val"
                               value="<?= $esc((string)($spawnConfig['spawn_channel'] ?? '')) ?>">
                        <div class="it-picker-row" id="pkm-channel-box">
                            <button type="button" class="it-picker-add" id="pkm-channel-btn">+</button>
                        </div>
                        <div class="bh-eco-hint">Channel, in dem wild Pokémon erscheinen. Leer lassen = alle Channels.</div>
                    </div>
                    <div class="bh-eco-field">
                        <label class="bh-eco-label">Spawn-Rate (Nachrichten)</label>
                        <input type="number" name="spawn_rate"
                               class="bh-input"
                               value="<?= (int)($spawnConfig['spawn_rate'] ?? 20) ?>"
                               min="1" max="500">
                        <div class="bh-eco-hint">Alle wie vielen Nachrichten ein wildes Pokémon erscheint (Standard: 20).</div>
                    </div>
                </div>
                <div class="bh-eco-save-row">
                    <button type="submit" class="bh-eco-btn">Einstellungen speichern</button>
                </div>
            </form>
            <?php else: ?>
                <div class="bh-eco-empty">Kein Server ausgewählt.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Subcommands ───────────────────────────────────────────────────────── -->
    <div class="bh-eco-card">
        <div class="bh-eco-card__header">
            <div class="bh-eco-card__header-left">
                <div class="bh-eco-card__kicker">Slash Commands</div>
                <div class="bh-eco-card__title">Subcommands</div>
            </div>
        </div>
        <div class="bh-music-cmd-grid">
            <?php foreach ($pkmSubCommands as $sc): ?>
            <div class="bh-music-cmd-row">
                <div class="bh-music-cmd-row__info">
                    <div class="bh-music-cmd-row__name"><?= $esc($sc['name']) ?></div>
                    <div class="bh-music-cmd-row__desc"><?= $esc($sc['desc']) ?></div>
                </div>
                <label class="bh-toggle">
                    <input class="bh-toggle-input" type="checkbox"
                        <?= ($subCmdEnabled[$sc['key']] ?? 1) ? 'checked' : '' ?>
                        data-field="<?= $esc($sc['key']) ?>"
                        onchange="pkmToggle(this)">
                    <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                </label>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<script>
(function () {
    var url = window.location.pathname + window.location.search;
    var BOT_ID = <?= (int)$botId ?>;

    bhSetupChannelPicker('pkm-channel-box', 'pkm-channel-val', 'pkm-channel-btn', BOT_ID);

    // Collapsible card headers
    document.querySelectorAll('.bh-eco-card__header--toggle').forEach(function (hdr) {
        hdr.addEventListener('click', function () {
            var bodyId = hdr.id.replace('-toggle', '-body');
            var body   = document.getElementById(bodyId);
            if (body) body.classList.toggle('bh-eco-collapsed');
            hdr.querySelector('.bh-eco-collapse-chevron')?.classList.toggle('bh-eco-collapse-chevron--open');
        });
    });

    // Subcommand toggles
    function pkmToggle(el) {
        var field = el.dataset.field;
        var val   = el.checked ? '1' : '0';
        var fd    = new FormData();
        fd.append('field', field);
        fd.append('value', val);
        fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (!d.ok) { el.checked = !el.checked; } })
        .catch(function () { el.checked = !el.checked; });
    }

    window.pkmToggle = pkmToggle;
}());
</script>
