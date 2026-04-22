<?php
declare(strict_types=1);
/** @var int $currentBotId */

if (!isset($currentBotId) || $currentBotId <= 0) {
    echo '<p style="color:#fca5a5;padding:24px">Kein Bot ausgewählt.</p>';
    return;
}

$botId = $currentBotId;

require_once dirname(__DIR__) . '/functions/module_toggle.php';

// ── DB setup ──────────────────────────────────────────────────────────────────
try {
    $pdo = bh_get_pdo();

    $pdo->exec("CREATE TABLE IF NOT EXISTS `bot_invite_tracker_settings` (
        `bot_id`       BIGINT UNSIGNED NOT NULL,
        `guild_id`     VARCHAR(20)     NOT NULL DEFAULT '',
        `enabled`      TINYINT(1)      NOT NULL DEFAULT 1,
        `channel_id`   VARCHAR(20)     NOT NULL DEFAULT '',
        `join_message` TEXT            NULL,
        `updated_at`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_bot_guild` (`bot_id`, `guild_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `bot_invite_stats` (
        `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `bot_id`        BIGINT UNSIGNED NOT NULL,
        `guild_id`      VARCHAR(20)     NOT NULL,
        `inviter_id`    VARCHAR(20)     NOT NULL,
        `inviter_name`  VARCHAR(100)    NOT NULL DEFAULT '',
        `invite_code`   VARCHAR(20)     NOT NULL,
        `uses`          INT UNSIGNED    NOT NULL DEFAULT 0,
        `last_used_at`  DATETIME        NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_bot_guild_code` (`bot_id`, `guild_id`, `invite_code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    echo '<p style="color:#fca5a5;padding:24px">DB Fehler: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    return;
}

// ── AJAX ──────────────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    bh_mod_handle_ajax($pdo, $botId);
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $raw    = (string)file_get_contents('php://input');
    $data   = json_decode($raw, true) ?? [];
    $action = (string)($data['action'] ?? '');

    if ($action === 'save_settings') {
        $guildId    = trim((string)($data['guild_id']    ?? ''));
        $enabled    = (int)(bool)($data['enabled']    ?? true);
        $channelId  = trim((string)($data['channel_id']  ?? ''));
        $joinMsg    = trim((string)($data['join_message'] ?? ''));

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO bot_invite_tracker_settings (bot_id, guild_id, enabled, channel_id, join_message)
                 VALUES (:b, :g, :en, :ch, :jm)
                 ON DUPLICATE KEY UPDATE enabled = :en2, channel_id = :ch2, join_message = :jm2"
            );
            $stmt->execute([
                ':b'   => $botId,
                ':g'   => $guildId,
                ':en'  => $enabled,
                ':ch'  => $channelId,
                ':jm'  => $joinMsg ?: null,
                ':en2' => $enabled,
                ':ch2' => $channelId,
                ':jm2' => $joinMsg ?: null,
            ]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'load_leaderboard') {
        $guildId = trim((string)($data['guild_id'] ?? ''));
        try {
            $stmt = $pdo->prepare(
                "SELECT inviter_id, inviter_name, SUM(uses) AS total_uses
                 FROM bot_invite_stats
                 WHERE bot_id = ? AND (guild_id = ? OR ? = '')
                 GROUP BY inviter_id, inviter_name
                 ORDER BY total_uses DESC
                 LIMIT 25"
            );
            $stmt->execute([$botId, $guildId, $guildId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'rows' => $rows]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'load_guild_settings') {
        $guildId = trim((string)($data['guild_id'] ?? ''));
        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM bot_invite_tracker_settings WHERE bot_id = ? AND guild_id = ? LIMIT 1'
            );
            $stmt->execute([$botId, $guildId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'settings' => $row ?: null]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'unknown_action']);
    exit;
}

// ── Load guilds from bot_guilds or a generic overview ────────────────────────
// We show a guild selector if the bot is in multiple guilds.
// For simplicity, load all guild_ids from invite stats to populate the dropdown.
$guildsWithStats = [];
try {
    $stmt = $pdo->prepare(
        'SELECT DISTINCT guild_id FROM bot_invite_stats WHERE bot_id = ? ORDER BY guild_id'
    );
    $stmt->execute([$botId]);
    $guildsWithStats = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'guild_id');
} catch (Throwable) {}

// Load settings for all guild_ids that have settings
$allSettings = [];
try {
    $stmt = $pdo->prepare(
        'SELECT * FROM bot_invite_tracker_settings WHERE bot_id = ? ORDER BY guild_id'
    );
    $stmt->execute([$botId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $allSettings[$row['guild_id']] = $row;
    }
} catch (Throwable) {}

$pageUrl = '/dashboard?view=invite-tracker&bot_id=' . $botId;
$modEnabled = bh_mod_is_enabled($pdo, $botId, 'module:invite-tracker');
?>
<?= bh_mod_render($modEnabled, $botId, 'module:invite-tracker', 'Invite Tracker', 'Einladungs-Tracking für diesen Bot ein- oder ausschalten.') ?>
<div id="bh-mod-body">
<div class="it-page">

    <div class="it-head">
        <div class="it-kicker">ANALYTICS</div>
        <h1 class="it-title">Invite Tracker</h1>
    </div>

    <div id="bh-alert" class="bh-alert"></div>

    <!-- ── Saved configurations overview ── -->
    <?php if (!empty($allSettings)): ?>
    <div class="bh-card">
        <div class="bh-card-title">Gespeicherte Konfigurationen</div>
        <div class="bh-card-desc">Klicke auf „Bearbeiten" um eine Konfiguration zu laden.</div>
        <table class="it-lb-table">
            <thead>
                <tr>
                    <th>Guild ID</th>
                    <th>Channel</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allSettings as $gId => $s): ?>
                <tr>
                    <td style="font-family:monospace;font-size:0.8125rem"><?= htmlspecialchars($gId, ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="font-family:monospace;font-size:0.8125rem;color:#94a3b8">
                        <?= $s['channel_id'] ? '#' . htmlspecialchars($s['channel_id'], ENT_QUOTES, 'UTF-8') : '<span style="color:#4b5563">—</span>' ?>
                    </td>
                    <td>
                        <?php if ((int)$s['enabled']): ?>
                            <span style="color:#86efac;font-size:0.75rem;font-weight:600">● Aktiv</span>
                        <?php else: ?>
                            <span style="color:#64748b;font-size:0.75rem;font-weight:600">● Inaktiv</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button" class="it-save-btn"
                                style="padding:5px 12px;font-size:0.75rem"
                                onclick="itLoadGuild(<?= htmlspecialchars(json_encode($gId), ENT_QUOTES, 'UTF-8') ?>)">
                            Bearbeiten
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ── Settings Card ── -->
    <div class="bh-card" id="it-settings-card">
        <div class="bh-card-title">Settings</div>
        <div class="bh-card-desc">
            Configure the invite tracker. The bot will detect which invite code a user used when joining and send a notification.
            Requires the <strong>Manage Server</strong> permission.
        </div>

        <div class="bh-field">
            <div class="bh-label">Server</div>
            <div class="it-sublabel">Wähle den Discord-Server oder gib die ID manuell ein.</div>
            <input type="hidden" id="it-guild-id" value="<?= htmlspecialchars(array_key_first($allSettings) ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <div class="it-picker-row" id="it-guild-box">
                <button type="button" class="it-picker-add" id="it-guild-btn">+</button>
            </div>
        </div>

        <!-- Enable toggle -->
        <div class="bh-toggle-row">
            <div class="bh-toggle-row__info">
                <div class="bh-toggle-row__title">Invite Tracker aktiv</div>
                <div class="bh-toggle-row__desc">Wenn aktiviert, werden Joins verfolgt und Nachrichten gesendet.</div>
            </div>
            <label class="bh-toggle">
                <input class="bh-toggle-input" type="checkbox" id="it-enabled" checked>
                <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
            </label>
        </div>

        <div class="bh-field">
            <div class="bh-label">Notification Channel</div>
            <div class="it-sublabel">Channel where join notifications are sent.</div>
            <input type="hidden" id="it-channel-val" value="">
            <div class="it-picker-row" id="it-channel-box">
                <button type="button" class="it-picker-add" id="it-channel-btn">+</button>
            </div>
        </div>

        <div class="bh-field">
            <div class="bh-label">Join Message</div>
            <div class="it-hint">
                Variables:
                <code>{user_name}</code>
                <code>{user}</code>
                <code>{inviter_name}</code>
                <code>{invite_code}</code>
                <code>{invite_uses}</code>
                <code>{server}</code>
                <code>{member_count}</code>
            </div>
            <textarea id="it-join-msg" class="it-textarea"
                      placeholder="👋 {user_name} joined using invite {invite_code} by {inviter_name} (total uses: {invite_uses})"></textarea>
        </div>

        <button type="button" class="it-save-btn" onclick="itSaveSettings()">
            Speichern
        </button>
    </div>

    <!-- ── Leaderboard Card ── -->
    <div class="bh-card">
        <div class="bh-card-title">Invite Leaderboard</div>
        <div class="bh-card-desc">Top inviters based on tracked invite uses for this bot.</div>

        <div id="it-lb-wrap">
            <div class="it-lb-loading">Leaderboard wird geladen…</div>
        </div>
    </div>

</div>
</div><!-- /bh-mod-body -->

<script>
(function () {
    const BOT_ID = <?= (int)$botId ?>;

    function escHtml(s) {
        return String(s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function flash(msg, ok) {
        var el = document.getElementById('bh-alert');
        el.className = 'bh-alert ' + (ok ? 'bh-alert--ok' : 'bh-alert--err');
        el.textContent = msg;
        el.style.display = 'block';
        clearTimeout(el._t);
        el._t = setTimeout(function () { el.style.display = 'none'; }, 4000);
    }

    // ── Generic single-select picker helper ──────────────────────────────────
    function setupSinglePicker(boxId, valId, btnId, prefix, type, onSelect) {
        var box = document.getElementById(boxId);
        var val = document.getElementById(valId);
        var btn = document.getElementById(btnId);
        if (!box || !val || !btn) return;

        function renderTag(id, name) {
            box.querySelectorAll('.it-ch-tag').forEach(function (t) { t.remove(); });
            if (!id) return;
            var tag = document.createElement('span');
            tag.className = 'it-ch-tag';
            tag.innerHTML = escHtml(prefix + (name || id))
                + '<button type="button" class="it-ch-tag-rm" title="Entfernen">×</button>';
            tag.querySelector('.it-ch-tag-rm').addEventListener('click', function () {
                val.value = '';
                tag.remove();
            });
            box.insertBefore(tag, btn);
        }

        // Init with existing value
        if (val.value) renderTag(val.value, val.value);

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            BhPerm.openPicker(this, BOT_ID, type, [], function (item) {
                val.value = item.id;
                renderTag(item.id, item.name || item.id);
                if (onSelect) onSelect(item);
            });
        });

        return { renderTag: renderTag };
    }

    // ── Guild picker ──────────────────────────────────────────────────────────
    var guildPicker = setupSinglePicker('it-guild-box', 'it-guild-id', 'it-guild-btn', '', 'guilds', function (item) {
        loadGuildSettings(item.id);
        loadLeaderboard(item.id);
    });

    // ── Channel picker ────────────────────────────────────────────────────────
    var channelVal = document.getElementById('it-channel-val');
    var channelPicker = setupSinglePicker('it-channel-box', 'it-channel-val', 'it-channel-btn', '#', 'channels', null);

    function renderChannelTag(id, name) {
        if (channelPicker) channelPicker.renderTag(id, name);
    }

    // ── Load settings for a guild ─────────────────────────────────────────────
    function loadGuildSettings(guildId) {
        if (!guildId) return;
        fetch(window.location.href, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'load_guild_settings', guild_id: guildId }),
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.ok || !d.settings) return;
            var s = d.settings;
            document.getElementById('it-enabled').checked = !!Number(s.enabled);
            channelVal.value = s.channel_id || '';
            renderChannelTag(s.channel_id, s.channel_id);
            document.getElementById('it-join-msg').value = s.join_message || '';
        })
        .catch(function () {});
    }

    // Expose for "Bearbeiten" buttons in the overview table
    window.itLoadGuild = function (guildId) {
        document.getElementById('it-guild-id').value = guildId;
        if (guildPicker) guildPicker.renderTag(guildId, guildId);
        loadGuildSettings(guildId);
        loadLeaderboard(guildId);
        document.getElementById('it-settings-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    // Initial load — pre-populate if a saved guild exists
    var initialGuild = document.getElementById('it-guild-id').value.trim();
    if (initialGuild) {
        if (guildPicker) guildPicker.renderTag(initialGuild, initialGuild);
        loadGuildSettings(initialGuild);
    }

    // ── Save ──────────────────────────────────────────────────────────────────
    window.itSaveSettings = function () {
        var guildId  = document.getElementById('it-guild-id').value.trim();
        var enabled  = document.getElementById('it-enabled').checked;
        var chId     = channelVal.value.trim();
        var joinMsg  = document.getElementById('it-join-msg').value.trim();

        if (!guildId) { flash('Bitte einen Server auswählen oder ID eingeben.', false); return; }

        fetch(window.location.href, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action:       'save_settings',
                guild_id:     guildId,
                enabled:      enabled,
                channel_id:   chId,
                join_message: joinMsg,
            }),
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.ok) {
                flash('Einstellungen gespeichert!', true);
                // Reload after short delay so overview table refreshes
                setTimeout(function () { window.location.reload(); }, 1200);
            } else {
                flash('Fehler: ' + (d.error || 'Unbekannt'), false);
            }
        })
        .catch(function () { flash('Netzwerkfehler.', false); });
    };

    // ── Leaderboard ───────────────────────────────────────────────────────────
    function loadLeaderboard(guildId) {
        var wrap = document.getElementById('it-lb-wrap');
        wrap.innerHTML = '<div class="it-lb-loading">Lade…</div>';

        fetch(window.location.href, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'load_leaderboard', guild_id: guildId || '' }),
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.ok || !Array.isArray(d.rows) || d.rows.length === 0) {
                wrap.innerHTML = '<div class="it-lb-empty">Noch keine Invite-Daten vorhanden.</div>';
                return;
            }

            var html = '<table class="it-lb-table">'
                + '<thead><tr>'
                + '<th class="it-lb-rank">#</th>'
                + '<th>Inviter</th>'
                + '<th>Invites</th>'
                + '</tr></thead><tbody>';

            d.rows.forEach(function (row, idx) {
                html += '<tr>'
                    + '<td class="it-lb-rank">' + (idx + 1) + '</td>'
                    + '<td>' + escHtml(row.inviter_name || row.inviter_id) + '</td>'
                    + '<td class="it-lb-uses">' + escHtml(String(row.total_uses || 0)) + '</td>'
                    + '</tr>';
            });

            html += '</tbody></table>';
            wrap.innerHTML = html;
        })
        .catch(function () {
            wrap.innerHTML = '<div class="it-lb-empty">Fehler beim Laden.</div>';
        });
    }

    loadLeaderboard(initialGuild);
}());
</script>
