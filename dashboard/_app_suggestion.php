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

function sug_e(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ── Module toggle AJAX ────────────────────────────────────────────────────────
bh_mod_handle_ajax($pdo, $botId);

// ── AJAX ───────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['sug_action'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $action  = (string)$_POST['sug_action'];
        $guildId = (string)($_POST['guild_id'] ?? '');
        if ($guildId === '') {
            $gi = $pdo->prepare('SELECT guild_id FROM bot_guilds WHERE bot_id = ? ORDER BY id LIMIT 1');
            $gi->execute([$botId]);
            $guildId = (string)($gi->fetchColumn() ?? '');
        }
        if ($guildId === '') throw new RuntimeException('Keine Guild-ID gefunden.');

        if ($action === 'save_settings') {
            $channelId   = trim((string)($_POST['channel_id']    ?? ''));
            $buttonStyle = in_array($_POST['button_style'] ?? '', ['arrows', 'thumbs'], true)
                ? (string)$_POST['button_style'] : 'arrows';

            $pdo->prepare(
                'INSERT INTO bot_suggestion_settings
                    (bot_id, guild_id, channel_id, button_style)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    channel_id   = VALUES(channel_id),
                    button_style = VALUES(button_style),
                    updated_at   = NOW()'
            )->execute([$botId, $guildId, $channelId ?: null, $buttonStyle]);

            bh_notify_slash_sync($botId);
            bh_notify_bot_reload($botId);
            echo json_encode(['ok' => true]); exit;
        }

        if ($action === 'toggle_command') {
            $key     = (string)($_POST['command_key'] ?? '');
            $enabled = (int)($_POST['enabled'] ?? 0);
            $pdo->prepare(
                'UPDATE commands SET is_enabled = ?, updated_at = NOW() WHERE bot_id = ? AND command_key = ?'
            )->execute([$enabled, $botId, $key]);
            bh_notify_slash_sync($botId);
            echo json_encode(['ok' => true]); exit;
        }

        throw new RuntimeException('Unbekannte Aktion.');
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]); exit;
    }
}

// ── Auto-migrate ──────────────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `bot_suggestion_settings` (
        `bot_id`       BIGINT UNSIGNED NOT NULL,
        `guild_id`     VARCHAR(20)     NOT NULL,
        `channel_id`   VARCHAR(20)     NOT NULL DEFAULT '',
        `button_style` VARCHAR(10)     NOT NULL DEFAULT 'arrows',
        `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`bot_id`, `guild_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `bot_suggestion_votes` (
        `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `bot_id`     BIGINT UNSIGNED NOT NULL,
        `guild_id`   VARCHAR(20)     NOT NULL,
        `message_id` VARCHAR(20)     NOT NULL,
        `user_id`    VARCHAR(20)     NOT NULL,
        `vote`       TINYINT(1)      NOT NULL,
        `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_vote` (`bot_id`, `guild_id`, `message_id`, `user_id`),
        INDEX `idx_msg` (`bot_id`, `guild_id`, `message_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable) {}

// ── Module toggle state ───────────────────────────────────────────────────────
// ── Seed suggest command ──────────────────────────────────────────────────────
try {
    $pdo->prepare(
        'INSERT INTO commands (bot_id, command_key, command_type, name, description, is_enabled)
         VALUES (?, ?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
             command_type = VALUES(command_type),
             name         = VALUES(name),
             description  = VALUES(description),
             updated_at   = NOW()'
    )->execute([$botId, 'suggest', 'module', '/suggest', 'Sendet einen Vorschlag in den Suggestion-Channel.']);
} catch (Throwable) {}

$modEnabled = bh_mod_is_enabled($pdo, $botId, 'module:suggestion');

// ── Guild context from global index.php selector ──────────────────────────────
$guildId = $currentGuildId ?? '';

$settings = [];
if ($guildId !== '') {
    try {
        $pdo->prepare('INSERT IGNORE INTO bot_suggestion_settings (bot_id, guild_id) VALUES (?, ?)')->execute([$botId, $guildId]);
        $s = $pdo->prepare('SELECT * FROM bot_suggestion_settings WHERE bot_id = ? AND guild_id = ? LIMIT 1');
        $s->execute([$botId, $guildId]);
        $settings = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {}
}

// ── Stats ─────────────────────────────────────────────────────────────────────
$totalVotes = 0;
try {
    $r = $pdo->prepare('SELECT COUNT(*) FROM bot_suggestion_votes WHERE bot_id = ? AND guild_id = ?');
    $r->execute([$botId, $guildId]);
    $totalVotes = (int)$r->fetchColumn();
} catch (Throwable) {}

$sugChannel     = sug_e((string)($settings['channel_id']   ?? ''));
$sugButtonStyle = (string)($settings['button_style'] ?? 'arrows');
$sugHasChannel  = $sugChannel !== '';
?>

<style>
.sug-hero {
    position: relative; border-radius: 12px; overflow: hidden;
    padding: 28px 28px 24px; margin-bottom: 20px;
    background: linear-gradient(135deg, #6c63ff 0%, #8b5cf6 60%, #a855f7 100%);
    color: #fff;
}
.sug-hero__bg {
    position: absolute; inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.06'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}
.sug-hero__inner { position: relative; display: flex; align-items: flex-start; gap: 18px; }
.sug-hero__icon {
    flex-shrink: 0; width: 52px; height: 52px;
    background: rgba(255,255,255,.18); border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 26px; backdrop-filter: blur(4px);
}
.sug-hero__text { flex: 1; min-width: 0; }
.sug-hero__kicker { font-size: 11px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; opacity: .75; margin-bottom: 4px; }
.sug-hero__title  { font-size: 22px; font-weight: 700; margin: 0 0 6px; }
.sug-hero__sub    { font-size: 13px; opacity: .82; line-height: 1.5; margin: 0; }
.sug-hero__badge  {
    flex-shrink: 0; align-self: flex-start;
    padding: 3px 10px; border-radius: 99px; font-size: 11px; font-weight: 600;
    border: 1.5px solid rgba(255,255,255,.4); backdrop-filter: blur(4px);
}
.sug-hero__badge--on  { background: rgba(255,255,255,.18); color: #fff; }
.sug-hero__badge--off { background: rgba(0,0,0,.18); color: rgba(255,255,255,.65); }
</style>

<div class="lv-page">

    <!-- Hero -->
    <div class="sug-hero">
        <div class="sug-hero__bg"></div>
        <div class="sug-hero__inner">
            <div class="sug-hero__icon">💡</div>
            <div class="sug-hero__text">
                <div class="sug-hero__kicker">Community · Feedback</div>
                <h1 class="sug-hero__title">Suggestion</h1>
                <p class="sug-hero__sub">
                    Mitglieder können mit <code style="background:rgba(255,255,255,.18);padding:1px 5px;border-radius:4px">/suggest</code> Vorschläge einreichen, die mit Abstimmungs-Buttons im Suggestion-Channel erscheinen.
                </p>
            </div>
            <div class="sug-hero__badge" id="sug-hero-badge"><!--set by JS--></div>
        </div>
    </div>

    <!-- Stats -->
    <div class="lv-stats">
        <div class="lv-stat">
            <div class="lv-stat__val"><?= $totalVotes ?></div>
            <div class="lv-stat__lbl">Stimmen gesamt</div>
        </div>
        <div class="lv-stat">
            <div class="lv-stat__val"><?= $sugButtonStyle === 'thumbs' ? '👍 👎' : '⬆️ ⬇️' ?></div>
            <div class="lv-stat__lbl">Button-Stil</div>
        </div>
        <div class="lv-stat">
            <div class="lv-stat__val"><?= $sugHasChannel ? '✓' : '—' ?></div>
            <div class="lv-stat__lbl">Channel gesetzt</div>
        </div>
    </div>

    <!-- Module toggle -->
    <?= bh_mod_render($modEnabled, $botId, 'module:suggestion', 'Suggestion', 'Suggestion-Funktion für diesen Bot ein- oder ausschalten.') ?>

    <!-- ── Einstellungen (always accessible, outside disabled overlay) ──────── -->
    <div class="bh-card">
        <div class="bh-card-hdr lv-toggle-hdr" data-target="sug-settings-body">
            <div class="lv-card__hdr-left">
                <div class="lv-card__kicker">Konfiguration</div>
                <div class="bh-card-title">Suggestion-Einstellungen</div>
            </div>
            <svg class="lv-chevron lv-chevron--open" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
            </svg>
        </div>
        <div class="lv-card__body" id="sug-settings-body">

            <!-- Channel picker -->
            <div class="bh-field">
                <label class="bh-label">Suggestion-Channel</label>
                <input type="hidden" id="sug-channel-val" value="<?= $sugChannel ?>">
                <input type="hidden" id="sug-guild-val"   value="<?= sug_e($guildId) ?>">
                <div class="it-picker-row" id="sug-channel-box"
                     data-bh-val="sug-channel-val"
                     data-bh-guild="sug-guild-val"
                     data-bh-bot="<?= $botId ?>">
                    <button type="button" class="it-picker-add">+</button>
                </div>
                <div class="bh-hint">Der Channel, in dem Vorschläge erscheinen.</div>
            </div>

            <!-- Button style -->
            <div class="bh-field">
                <label class="bh-label">Abstimmungs-Stil</label>
                <div style="display:flex;gap:12px;margin-top:4px">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;color:var(--bh-text,#e6edf3)">
                        <input type="radio" name="sug-btn-style" id="sug-style-arrows" value="arrows" <?= $sugButtonStyle !== 'thumbs' ? 'checked' : '' ?>>
                        ⬆️ ⬇️ Pfeile
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;color:var(--bh-text,#e6edf3)">
                        <input type="radio" name="sug-btn-style" id="sug-style-thumbs" value="thumbs" <?= $sugButtonStyle === 'thumbs' ? 'checked' : '' ?>>
                        👍 👎 Daumen
                    </label>
                </div>
                <div class="bh-hint">Welche Buttons unter Vorschlägen erscheinen.</div>
            </div>

            <div class="lv-btn-row">
                <button class="bh-btn bh-btn--primary" id="sug-save">Speichern</button>
                <span class="lv-save-msg" id="sug-msg"></span>
            </div>
        </div>
    </div>
</div>

<?php
$sugCmdEnabled = true;
try {
    $r = $pdo->prepare('SELECT is_enabled FROM commands WHERE bot_id = ? AND command_key = ? LIMIT 1');
    $r->execute([$botId, 'suggest']);
    $row = $r->fetch(PDO::FETCH_ASSOC);
    if ($row) $sugCmdEnabled = (bool)$row['is_enabled'];
} catch (Throwable) {}
?>

<div style="margin-top:8px">
    <div class="lv-kicker">Modul</div>
    <div class="lv-title" style="font-size:20px;margin-bottom:12px">Commands</div>
</div>
<div class="bh-cmd-grid">
    <div class="bh-cmd-card">
        <div>
            <div class="bh-cmd-name">/suggest</div>
            <div class="bh-cmd-desc">Sendet einen Vorschlag in den Suggestion-Channel.</div>
        </div>
        <label class="bh-toggle">
            <input type="checkbox" class="sugCmdToggle bh-toggle-input" data-key="suggest" <?= $sugCmdEnabled ? 'checked' : '' ?>>
            <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
        </label>
    </div>
</div>

<script>
(function () {
    const BOT_ID      = <?= json_encode($botId) ?>;
    const GUILD_ID    = <?= json_encode($guildId) ?>;
    let   modEnabled  = <?= json_encode((bool)$modEnabled) ?>;

    // ── Hero badge ────────────────────────────────────────────────────────────
    function updateBadge() {
        const badge = document.getElementById('sug-hero-badge');
        if (!badge) return;
        const channelSet = !!document.getElementById('sug-channel-val')?.value;
        const active = modEnabled && channelSet;
        badge.className = 'sug-hero__badge ' + (active ? 'sug-hero__badge--on' : 'sug-hero__badge--off');
        badge.textContent = active ? '● Aktiv' : '○ Inaktiv';
    }

    // Track module toggle state via the module-toggle.js pill element
    const modPill = document.getElementById('bhmod_module_suggestion_pill');
    if (modPill) {
        new MutationObserver(() => {
            modEnabled = modPill.classList.contains('bh-mod-pill--on');
            updateBadge();
        }).observe(modPill, { attributes: true, attributeFilter: ['class'] });
    }

    // Collapsible cards
    document.querySelectorAll('.lv-toggle-hdr').forEach(hdr => {
        hdr.addEventListener('click', () => {
            const t = document.getElementById(hdr.dataset.target);
            if (!t) return;
            const collapsed = t.classList.toggle('lv-collapsed');
            hdr.querySelector('.lv-chevron')?.classList.toggle('lv-chevron--open', !collapsed);
        });
    });

    // ── Channel picker (initialised by channel-picker.js) ────────────────────
    document.getElementById('sug-channel-box')?.addEventListener('bh-picker-picked',  updateBadge);
    document.getElementById('sug-channel-box')?.addEventListener('bh-picker-cleared', updateBadge);
    if (document.getElementById('sug-channel-val')?.value) updateBadge();

    // ── Save ──────────────────────────────────────────────────────────────────
    function post(data) {
        return fetch('', {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    new URLSearchParams({ ...data, bot_id: BOT_ID }).toString(),
        }).then(r => r.json());
    }

    function showMsg(el, ok, text) {
        el.textContent = text;
        el.className = 'lv-save-msg ' + (ok ? 'lv-save-msg--ok' : 'lv-save-msg--err');
        setTimeout(() => { el.textContent = ''; el.className = 'lv-save-msg'; }, 3000);
    }

    function getButtonStyle() {
        return document.querySelector('input[name="sug-btn-style"]:checked')?.value ?? 'arrows';
    }

    document.getElementById('sug-save').addEventListener('click', () => {
        post({
            sug_action:   'save_settings',
            channel_id:   document.getElementById('sug-channel-val').value,
            guild_id:     document.getElementById('sug-guild-val').value || GUILD_ID,
            button_style: getButtonStyle(),
        }).then(r => showMsg(document.getElementById('sug-msg'), r.ok, r.ok ? '✓ Gespeichert' : '✗ ' + (r.error || 'Fehler')));
    });

    updateBadge();

    // ── Command toggles ───────────────────────────────────────────────────────
    document.querySelectorAll('.sugCmdToggle').forEach(toggle => {
        toggle.addEventListener('change', async (e) => {
            const r = await post({
                sug_action:  'toggle_command',
                command_key: toggle.dataset.key,
                enabled:     e.target.checked ? '1' : '0',
            });
            if (!r.ok) { alert('Fehler beim Speichern.'); e.target.checked = !e.target.checked; }
        });
    });
})();
</script>
