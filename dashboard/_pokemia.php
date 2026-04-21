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

$flash  = [];
$errors = [];

// ── Command definition ────────────────────────────────────────────────────────
$pkmCommands = [
    ['key' => 'pokemia', 'name' => '/pokemia', 'desc' => 'Pokémon fangen, trainieren und kämpfen. (Alle Subcommands)'],
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
            require_once dirname(__DIR__) . '/functions/custom_commands.php';
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

<div class="bh-eco-page">

    <div class="bh-eco-head">
        <div class="bh-eco-kicker">POKEMIA</div>
        <h1 class="bh-eco-title">Pokemia</h1>
        <p class="bh-eco-subtitle">Pokémon fangen, trainieren &amp; kämpfen auf deinem Discord-Server.</p>
    </div>

    <?php foreach ($flash as $msg): ?>
        <div class="bh-eco-alert bh-eco-alert--ok"><?= $esc($msg) ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $err): ?>
        <div class="bh-eco-alert bh-eco-alert--err"><?= $esc($err) ?></div>
    <?php endforeach; ?>

    <!-- ── Stats ──────────────────────────────────────────────────────────────── -->
    <div class="bh-eco-stats-row" style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:24px;">
        <div class="bh-eco-stat-box" style="flex:1;min-width:140px;background:var(--bh-card-bg,#1e2433);border-radius:10px;padding:18px 20px;text-align:center;">
            <div style="font-size:28px;font-weight:700;color:var(--bh-accent,#6c63ff)"><?= $stats['users'] ?></div>
            <div style="font-size:12px;color:var(--bh-text-muted,#8b949e);margin-top:4px;">Trainer</div>
        </div>
        <div class="bh-eco-stat-box" style="flex:1;min-width:140px;background:var(--bh-card-bg,#1e2433);border-radius:10px;padding:18px 20px;text-align:center;">
            <div style="font-size:28px;font-weight:700;color:var(--bh-accent,#6c63ff)"><?= $stats['pokemon'] ?></div>
            <div style="font-size:12px;color:var(--bh-text-muted,#8b949e);margin-top:4px;">Pokémon gefangen</div>
        </div>
        <div class="bh-eco-stat-box" style="flex:1;min-width:140px;background:var(--bh-card-bg,#1e2433);border-radius:10px;padding:18px 20px;text-align:center;">
            <div style="font-size:28px;font-weight:700;color:var(--bh-accent,#6c63ff)"><?= $stats['spawns_caught'] ?></div>
            <div style="font-size:12px;color:var(--bh-text-muted,#8b949e);margin-top:4px;">Wild gefangen</div>
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
                        <label class="bh-eco-toggle">
                            <input type="hidden" name="enabled[<?= $esc($key) ?>]" value="0">
                            <input type="checkbox" name="enabled[<?= $esc($key) ?>]" value="1" <?= $enabled ? 'checked' : '' ?>>
                            <span class="bh-eco-toggle__track"></span>
                        </label>
                    </div>
                </div>
                <?php endforeach; ?>
                <div style="padding:12px 16px;">
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
                <div class="bh-eco-settings-grid" style="padding:16px;">
                    <div class="bh-eco-field">
                        <label class="bh-eco-label">Spawn-Channel (Channel-ID)</label>
                        <input type="text" name="spawn_channel"
                               class="bh-eco-input"
                               value="<?= $esc((string)($spawnConfig['spawn_channel'] ?? '')) ?>"
                               placeholder="z. B. 123456789012345678"
                               pattern="[0-9]*" maxlength="25">
                        <div class="bh-eco-hint">Channel-ID des Channels, in dem wild Pokémon erscheinen. Leer lassen = alle Channels.</div>
                    </div>
                    <div class="bh-eco-field">
                        <label class="bh-eco-label">Spawn-Rate (Nachrichten)</label>
                        <input type="number" name="spawn_rate"
                               class="bh-eco-input"
                               value="<?= (int)($spawnConfig['spawn_rate'] ?? 20) ?>"
                               min="1" max="500">
                        <div class="bh-eco-hint">Alle wie vielen Nachrichten ein wildes Pokémon erscheint (Standard: 20).</div>
                    </div>
                </div>
                <div style="padding:0 16px 16px;">
                    <button type="submit" class="bh-eco-btn">Einstellungen speichern</button>
                </div>
            </form>
            <?php else: ?>
                <div style="padding:24px;color:var(--bh-text-muted,#8b949e);">Kein Server ausgewählt.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Info ───────────────────────────────────────────────────────────────── -->
    <div class="bh-eco-card">
        <div class="bh-eco-card__header">
            <div class="bh-eco-card__header-left">
                <div class="bh-eco-card__kicker">INFO</div>
                <div class="bh-eco-card__title">Verfügbare Subcommands</div>
            </div>
        </div>
        <div style="padding:16px;">
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead>
                    <tr style="color:var(--bh-text-muted,#8b949e);text-align:left;border-bottom:1px solid var(--bh-border,#2d3346);">
                        <th style="padding:6px 10px;">Command</th>
                        <th style="padding:6px 10px;">Beschreibung</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ([
                        ['/pokemia start',           'Starter-Pokémon wählen (Bisasam, Glumanda, Schiggy)'],
                        ['/pokemia catch',            'Erschienenes wildes Pokémon fangen'],
                        ['/pokemia list [seite]',     'Alle eigenen Pokémon auflisten'],
                        ['/pokemia info [nummer]',    'Details zu einem Pokémon anzeigen'],
                        ['/pokemia select <nummer>',  'Aktives Pokémon wechseln'],
                        ['/pokemia battle bot [1-10]','Gegen den Bot kämpfen (Schwierigkeitsstufe 1–10)'],
                        ['/pokemia battle user @user','Gegen einen anderen User kämpfen'],
                    ] as [$cmd, $desc]):
                    ?>
                    <tr style="border-bottom:1px solid var(--bh-border,#2d3346);">
                        <td style="padding:8px 10px;"><code style="background:var(--bh-code-bg,#161b27);padding:2px 6px;border-radius:4px;font-size:12px;"><?= $esc($cmd) ?></code></td>
                        <td style="padding:8px 10px;color:var(--bh-text-muted,#8b949e);"><?= $esc($desc) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
(function () {
    // Collapsible card headers
    document.querySelectorAll('.bh-eco-card__header--toggle').forEach(function (hdr) {
        hdr.addEventListener('click', function () {
            var bodyId = hdr.id.replace('-toggle', '-body');
            var body   = document.getElementById(bodyId);
            if (body) body.classList.toggle('bh-eco-collapsed');
            hdr.querySelector('.bh-eco-collapse-chevron')?.classList.toggle('bh-eco-collapse-chevron--open');
        });
    });
}());
</script>
