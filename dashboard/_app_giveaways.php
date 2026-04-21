<?php
declare(strict_types=1);

if (!isset($currentBotId) || $currentBotId <= 0) {
    echo '<p style="color:var(--bh-text-muted,#8b949e)">Kein Bot ausgewählt.</p>';
    return;
}

$botId = (int)$currentBotId;
require_once __DIR__ . '/../functions/custom_commands.php';
require_once __DIR__ . '/../functions/db_functions/commands.php';
require_once __DIR__ . '/../functions/module_toggle.php';
$pdo = bh_cc_get_pdo();

function gw_ensure_cmd(PDO $pdo, int $botId, string $key, string $name, string $desc): void {
    $pdo->prepare('INSERT IGNORE INTO commands (bot_id, command_key, command_type, name, description, is_enabled) VALUES (?, ?, ?, ?, ?, 1)')
        ->execute([$botId, $key, 'giveaway', $name, $desc]);
}

function gw_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ── AJAX ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bh_mod_handle_ajax($pdo, $botId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['gw_action'])) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');
    try {
        $action = (string)$_POST['gw_action'];

        if ($action === 'delete_giveaway') {
            $id = (int)($_POST['giveaway_id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Ungültige ID.');
            $pdo->prepare('DELETE FROM bot_giveaways WHERE id=? AND bot_id=?')->execute([$id, $botId]);
            echo json_encode(['ok' => true]); exit;
        }

        if ($action === 'save_settings') {
            $winnerMsg   = mb_substr(trim((string)($_POST['winner_message']    ?? '')), 0, 500);
            $noWinnerMsg = mb_substr(trim((string)($_POST['no_winner_message'] ?? '')), 0, 500);
            $pdo->prepare(
                'INSERT INTO bot_giveaway_settings (bot_id, winner_message, no_winner_message)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE winner_message=VALUES(winner_message), no_winner_message=VALUES(no_winner_message)'
            )->execute([$botId, $winnerMsg ?: null, $noWinnerMsg ?: null]);
            echo json_encode(['ok' => true]); exit;
        }

        if ($action === 'toggle_command') {
            $key = (string)($_POST['command_key'] ?? '');
            if (empty($key)) throw new RuntimeException('Command key missing.');
            bhcmd_set_module_enabled($pdo, $botId, $key, (int)($_POST['enabled'] ?? 0));
            bh_notify_slash_sync($botId);
            echo json_encode(['ok' => true]); exit;
        }

        throw new RuntimeException('Unbekannte Aktion.');
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]); exit;
    }
}

// ── Auto-migrate ──────────────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `bot_giveaways` (
        `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `bot_id` BIGINT UNSIGNED NOT NULL,
        `guild_id` VARCHAR(20) NOT NULL,
        `channel_id` VARCHAR(20) NOT NULL,
        `message_id` VARCHAR(20) DEFAULT NULL,
        `prize` VARCHAR(255) NOT NULL,
        `winner_count` INT NOT NULL DEFAULT 1,
        `ends_at` DATETIME NOT NULL,
        `host_id` VARCHAR(20) NOT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_bot_id` (`bot_id`),
        INDEX `idx_guild_id` (`guild_id`),
        INDEX `idx_ends_at` (`ends_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `bot_giveaway_participants` (
        `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `giveaway_id` BIGINT UNSIGNED NOT NULL,
        `user_id` VARCHAR(20) NOT NULL,
        `joined_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `idx_giveaway_user` (`giveaway_id`, `user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `bot_giveaway_settings` (
        `bot_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        `winner_message` TEXT DEFAULT NULL,
        `no_winner_message` TEXT DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable) {}

// ── Load settings ─────────────────────────────────────────────────────────────
$gwSettings = [];
try {
    $s = $pdo->prepare('SELECT * FROM bot_giveaway_settings WHERE bot_id = ? LIMIT 1');
    $s->execute([$botId]);
    $gwSettings = $s->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) {}

$defaultWinnerMsg   = 'Herzlichen Glückwunsch {winners}! 🎉 Du hast **{prize}** gewonnen!';
$defaultNoWinnerMsg = 'Das Giveaway für **{prize}** ist beendet — leider gab es keine Teilnehmer.';
$winnerMsg   = (string)($gwSettings['winner_message']    ?? '');
$noWinnerMsg = (string)($gwSettings['no_winner_message'] ?? '');

// ── Data ──────────────────────────────────────────────────────────────────────
$giveaways = [];
try {
    $stmt = $pdo->prepare(
        'SELECT g.*, (SELECT COUNT(*) FROM bot_giveaway_participants WHERE giveaway_id = g.id) AS participant_count
         FROM bot_giveaways g WHERE g.bot_id = ? ORDER BY g.is_active DESC, g.ends_at DESC'
    );
    $stmt->execute([$botId]);
    $giveaways = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {}

$totalGiveaways    = count($giveaways);
$activeGiveaways   = count(array_filter($giveaways, fn($g) => (bool)$g['is_active']));
$totalParticipants = array_sum(array_column($giveaways, 'participant_count'));

// ── Commands ──────────────────────────────────────────────────────────────────
$giveawayCommands = [
    ['key' => 'giveaway-create', 'name' => '/giveaway create', 'desc' => 'Startet ein neues Giveaway in diesem Channel.'],
    ['key' => 'giveaway-end',    'name' => '/giveaway end',    'desc' => 'Beendet ein aktives Giveaway vorzeitig.'],
    ['key' => 'giveaway-list',   'name' => '/giveaway list',   'desc' => 'Zeigt alle aktiven Giveaways auf dem Server.'],
];

foreach ($giveawayCommands as $cmd) {
    gw_ensure_cmd($pdo, $botId, $cmd['key'], $cmd['name'], $cmd['desc']);
}

$cmdKeys = array_column($giveawayCommands, 'key');
$cmdStmt = $pdo->prepare(
    'SELECT command_key, is_enabled FROM commands WHERE bot_id=? AND command_key IN (' .
    implode(',', array_fill(0, count($cmdKeys), '?')) . ')'
);
$cmdStmt->execute(array_merge([$botId], $cmdKeys));
$cmdMap = [];
foreach ($cmdStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $cmdMap[$r['command_key']] = (bool)$r['is_enabled'];
}

$modEnabled = bh_mod_is_enabled($pdo, $botId, 'module:giveaways');
?>

<div class="lv-page">

    <!-- ── Header ──────────────────────────────────────────────────────── -->
    <div class="lv-head">
        <div class="lv-kicker">Engagement</div>
        <h1 class="lv-title">Giveaway Management</h1>
        <p class="lv-subtitle">Erstelle und verwalte Giveaways — Teilnehmer können per Button-Klick mitmachen.</p>
    </div>

    <!-- ── Stats ───────────────────────────────────────────────────────── -->
    <div class="lv-stats">
        <div class="lv-stat">
            <div class="lv-stat__val"><?= $totalGiveaways ?></div>
            <div class="lv-stat__lbl">Giveaways gesamt</div>
        </div>
        <div class="lv-stat">
            <div class="lv-stat__val" style="color:#4caf7d"><?= $activeGiveaways ?></div>
            <div class="lv-stat__lbl">Aktiv</div>
        </div>
        <div class="lv-stat">
            <div class="lv-stat__val"><?= number_format($totalParticipants) ?></div>
            <div class="lv-stat__lbl">Teilnahmen gesamt</div>
        </div>
    </div>

    <?= bh_mod_render($modEnabled, $botId, 'module:giveaways', 'Giveaways', 'Alle Giveaway-Commands für diesen Bot ein- oder ausschalten.') ?>
    <div id="bh-mod-body">

    <!-- ── Gewinner-Nachrichten ──────────────────────────────────────────── -->
    <div class="lv-card">
        <div class="lv-card__hdr lv-toggle-hdr" data-target="gw-settings-body">
            <div class="lv-card__hdr-left">
                <div class="lv-card__kicker">Einstellungen</div>
                <div class="lv-card__title">Gewinner-Nachrichten</div>
            </div>
            <svg class="lv-chevron lv-chevron--open" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
            </svg>
        </div>
        <div class="lv-card__body" id="gw-settings-body">

            <!-- Variables hint -->
            <div style="padding:14px 20px 0; display:flex; flex-wrap:wrap; gap:6px; border-bottom:1px solid var(--bh-border,#2d3346); padding-bottom:14px;">
                <span style="font-size:11px;color:var(--bh-text-muted,#8b949e);margin-right:4px;line-height:22px">Variablen:</span>
                <?php foreach (['{winners}' => 'Erwähnt die Gewinner', '{prize}' => 'Name des Preises', '{winner_count}' => 'Anzahl Gewinner'] as $var => $tip): ?>
                <span class="gw-var-badge" title="<?= gw_h($tip) ?>" data-var="<?= gw_h($var) ?>"><?= gw_h($var) ?></span>
                <?php endforeach; ?>
            </div>

            <!-- Winner message -->
            <div class="lv-field">
                <label class="lv-label" for="gw-winner-msg">Nachricht bei Gewinner(n)</label>
                <textarea id="gw-winner-msg" class="lv-input" rows="3" placeholder="<?= gw_h($defaultWinnerMsg) ?>" style="resize:vertical"><?= gw_h($winnerMsg) ?></textarea>
                <div class="lv-hint">Wird gesendet wenn mindestens ein Teilnehmer vorhanden ist. Leer lassen für Standard.</div>
            </div>

            <!-- No winner message -->
            <div class="lv-field">
                <label class="lv-label" for="gw-no-winner-msg">Nachricht ohne Teilnehmer</label>
                <textarea id="gw-no-winner-msg" class="lv-input" rows="2" placeholder="<?= gw_h($defaultNoWinnerMsg) ?>" style="resize:vertical"><?= gw_h($noWinnerMsg) ?></textarea>
                <div class="lv-hint">Wird gesendet wenn niemand am Giveaway teilgenommen hat.</div>
            </div>

            <div class="lv-btn-row">
                <button class="lv-btn" id="gw-save-settings-btn" onclick="gwSaveSettings()">Speichern</button>
                <span id="gw-save-msg" class="lv-save-msg" style="display:none"></span>
            </div>
        </div>
    </div>

    <!-- ── Giveaway List ────────────────────────────────────────────────── -->
    <div class="lv-card">
        <div class="lv-card__hdr lv-toggle-hdr" data-target="gw-list-body">
            <div class="lv-card__hdr-left">
                <div class="lv-card__kicker">Übersicht</div>
                <div class="lv-card__title">Alle Giveaways</div>
            </div>
            <svg class="lv-chevron lv-chevron--open" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
            </svg>
        </div>
        <div class="lv-card__body" id="gw-list-body">
            <?php if (empty($giveaways)): ?>
            <div style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:40px 20px;text-align:center">
                <div style="font-size:32px">🎁</div>
                <div class="lv-feature__title">Noch keine Giveaways</div>
                <div class="lv-feature__desc">Starte dein erstes Giveaway mit <code style="background:var(--bh-input-bg,#161b27);padding:1px 6px;border-radius:4px">/giveaway create</code> in Discord.</div>
            </div>
            <?php else: ?>
            <div style="overflow-x:auto">
                <table class="gw-table">
                    <thead>
                        <tr>
                            <th>Preis</th>
                            <th>Status</th>
                            <th>Gewinner</th>
                            <th>Teilnehmer</th>
                            <th>Endet (UTC)</th>
                            <th>Channel</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($giveaways as $gw):
                            $active = (bool)$gw['is_active'];
                            $ends   = new DateTime($gw['ends_at']);
                            $isPast = $ends < new DateTime();
                        ?>
                        <tr id="gw-row-<?= (int)$gw['id'] ?>">
                            <td>
                                <div style="font-weight:600;color:var(--bh-text,#e6edf3)"><?= gw_h($gw['prize']) ?></div>
                                <div style="font-size:11px;color:var(--bh-text-muted,#8b949e);margin-top:2px">
                                    ID #<?= (int)$gw['id'] ?> &middot; Host: <code style="color:#a5b4fc"><?= gw_h($gw['host_id']) ?></code>
                                </div>
                            </td>
                            <td>
                                <?php if ($active && !$isPast): ?>
                                    <span class="badge badge--active">Aktiv</span>
                                <?php else: ?>
                                    <span class="badge badge--ended">Beendet</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center"><?= (int)$gw['winner_count'] ?></td>
                            <td style="text-align:center">
                                <span style="font-weight:600;color:var(--bh-accent,#6c63ff)"><?= (int)($gw['participant_count'] ?? 0) ?></span>
                            </td>
                            <td style="font-size:12px;color:var(--bh-text-muted,#8b949e)"><?= $ends->format('d.m.Y H:i') ?></td>
                            <td>
                                <code style="font-size:11px;color:#a5b4fc;background:var(--bh-input-bg,#161b27);padding:2px 6px;border-radius:4px"><?= gw_h($gw['channel_id']) ?></code>
                            </td>
                            <td>
                                <button class="lv-btn lv-btn--danger lv-btn--sm gw-delete-btn" data-id="<?= (int)$gw['id'] ?>">Löschen</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Commands ─────────────────────────────────────────────────────── -->
    <div style="margin-top:8px">
        <div class="lv-kicker">Modul</div>
        <div class="lv-title" style="font-size:20px;margin-bottom:12px">Commands</div>
    </div>

    <div class="lv-cmds-grid">
        <?php foreach ($giveawayCommands as $cmd):
            $on = $cmdMap[$cmd['key']] ?? true;
        ?>
        <div class="lv-cmd-card">
            <div>
                <div class="lv-cmd__name"><?= gw_h($cmd['name']) ?></div>
                <div class="lv-cmd__desc"><?= gw_h($cmd['desc']) ?></div>
            </div>
            <label class="lv-toggle">
                <input type="checkbox" class="gwCmdToggle" data-key="<?= gw_h($cmd['key']) ?>" <?= $on ? 'checked' : '' ?>>
                <span class="lv-toggle__track"></span>
            </label>
        </div>
        <?php endforeach; ?>
    </div>

    </div><!-- /bh-mod-body -->
</div>

<script>
(function () {
    function post(data) {
        const fd = new FormData();
        for (const [k, v] of Object.entries(data)) fd.append(k, v);
        return fetch(window.location.href, { method: 'POST', body: fd }).then(r => r.json());
    }

    // ── Save settings ─────────────────────────────────────────────────────
    window.gwSaveSettings = async function () {
        const btn = document.getElementById('gw-save-settings-btn');
        const msg = document.getElementById('gw-save-msg');
        btn.disabled = true;
        msg.style.display = 'none';

        const res = await post({
            gw_action:         'save_settings',
            winner_message:    document.getElementById('gw-winner-msg').value.trim(),
            no_winner_message: document.getElementById('gw-no-winner-msg').value.trim(),
        });

        msg.textContent  = res.ok ? '✓ Gespeichert' : ('Fehler: ' + (res.error || 'Unbekannt'));
        msg.className    = 'lv-save-msg ' + (res.ok ? 'lv-save-msg--ok' : 'lv-save-msg--err');
        msg.style.display = '';
        btn.disabled = false;
        if (res.ok) setTimeout(() => { msg.style.display = 'none'; }, 3000);
    };

    // ── Einzelne Command-Toggles ─────────────────────────────────��────────
    document.querySelectorAll('.gwCmdToggle').forEach(toggle => {
        toggle.addEventListener('change', async (e) => {
            const res = await post({ gw_action: 'toggle_command', command_key: toggle.dataset.key, enabled: e.target.checked ? '1' : '0' });
            if (!res.ok) { alert('Fehler beim Speichern.'); e.target.checked = !e.target.checked; }
        });
    });

    // ── Delete ────────────────────────────────────────────────────────────
    document.querySelectorAll('.gw-delete-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('Giveaway wirklich löschen? (Discord-Nachricht bleibt bestehen)')) return;
            const res = await post({ gw_action: 'delete_giveaway', giveaway_id: btn.dataset.id });
            if (res.ok) {
                const row = document.getElementById('gw-row-' + btn.dataset.id);
                if (row) row.remove();
            } else {
                alert('Fehler: ' + (res.error || 'Unbekannt'));
            }
        });
    });

    // ── Variable badges → copy to clipboard ──────────────────────────────
    document.querySelectorAll('.gw-var-badge').forEach(badge => {
        badge.addEventListener('click', () => {
            navigator.clipboard.writeText(badge.dataset.var).then(() => {
                badge.classList.add('gw-var-badge--copied');
                const orig = badge.textContent;
                badge.textContent = '✓ Kopiert';
                setTimeout(() => {
                    badge.classList.remove('gw-var-badge--copied');
                    badge.textContent = orig;
                }, 1500);
            }).catch(() => {});
        });
    });

    // ── Collapsible cards ─────────────────────────────────────────────────
    document.querySelectorAll('.lv-toggle-hdr').forEach(hdr => {
        hdr.addEventListener('click', () => {
            const body    = document.getElementById(hdr.dataset.target);
            const chevron = hdr.querySelector('.lv-chevron');
            if (!body) return;
            const collapsed = body.classList.toggle('lv-collapsed');
            chevron?.classList.toggle('lv-chevron--open', !collapsed);
        });
    });
})();
</script>
