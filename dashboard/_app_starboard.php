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

function sb_e(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ── Module toggle AJAX (must run before other POST handling) ──────────────────
bh_mod_handle_ajax($pdo, $botId);

// ── AJAX ───────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['sb_action'])) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $action  = (string)$_POST['sb_action'];
        $guildId = (string)($_POST['guild_id'] ?? '');
        if ($guildId === '') {
            $gi = $pdo->prepare('SELECT guild_id FROM bot_guilds WHERE bot_id = ? ORDER BY id LIMIT 1');
            $gi->execute([$botId]);
            $guildId = (string)($gi->fetchColumn() ?? '');
        }
        if ($guildId === '') throw new RuntimeException('Keine Guild-ID gefunden.');

        if ($action === 'save_settings') {
            $channelId   = trim((string)($_POST['channel_id']    ?? ''));
            $emoji       = mb_substr(trim((string)($_POST['emoji'] ?? '⭐')), 0, 100) ?: '⭐';
            $threshold   = max(1, min(100, (int)($_POST['threshold']     ?? 3)));
            $selfStar    = !empty($_POST['allow_self_star']) ? 1 : 0;
            $ignoreBots  = !empty($_POST['ignore_bots'])     ? 1 : 0;

            $pdo->prepare(
                'INSERT INTO bot_starboard_settings
                    (bot_id, guild_id, channel_id, emoji, threshold, allow_self_star, ignore_bots)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    channel_id      = VALUES(channel_id),
                    emoji           = VALUES(emoji),
                    threshold       = VALUES(threshold),
                    allow_self_star = VALUES(allow_self_star),
                    ignore_bots     = VALUES(ignore_bots),
                    updated_at      = NOW()'
            )->execute([$botId, $guildId, $channelId ?: null, $emoji, $threshold, $selfStar, $ignoreBots]);

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
    $pdo->exec("CREATE TABLE IF NOT EXISTS `bot_starboard_settings` (
        `bot_id`          BIGINT UNSIGNED  NOT NULL,
        `guild_id`        VARCHAR(20)      NOT NULL,
        `channel_id`      VARCHAR(20)      NOT NULL DEFAULT '',
        `emoji`           VARCHAR(100)     NOT NULL DEFAULT '⭐',
        `threshold`       TINYINT UNSIGNED NOT NULL DEFAULT 3,
        `allow_self_star` TINYINT(1)       NOT NULL DEFAULT 0,
        `ignore_bots`     TINYINT(1)       NOT NULL DEFAULT 1,
        `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`bot_id`, `guild_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `bot_starboard_entries` (
        `id`                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `bot_id`                BIGINT UNSIGNED NOT NULL,
        `guild_id`              VARCHAR(20)     NOT NULL,
        `original_message_id`   VARCHAR(20)     NOT NULL,
        `starboard_message_id`  VARCHAR(20)     NOT NULL,
        `star_count`            SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        `created_at`            DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_original` (`bot_id`, `guild_id`, `original_message_id`),
        INDEX `idx_bot_guild` (`bot_id`, `guild_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Migration: add guild_id if table was created without it
    $cols = $pdo->query("SHOW COLUMNS FROM `bot_starboard_settings` LIKE 'guild_id'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE `bot_starboard_settings`
            ADD COLUMN `guild_id` VARCHAR(20) NOT NULL DEFAULT '' AFTER `bot_id`");
        // Fix primary key to include guild_id
        try {
            $pdo->exec("ALTER TABLE `bot_starboard_settings` DROP PRIMARY KEY");
        } catch (Throwable) {}
        $pdo->exec("ALTER TABLE `bot_starboard_settings` ADD PRIMARY KEY (`bot_id`, `guild_id`)");
    }
} catch (Throwable) {}

// ── Module toggle state ───────────────────────────────────────────────────────
$modEnabled = bh_mod_is_enabled($pdo, $botId, 'module:starboard');

// ── Guild context from global index.php selector ──────────────────────────────
$guildId = $currentGuildId ?? '';

$settings = [];
if ($guildId !== '') {
    try {
        $pdo->prepare('INSERT IGNORE INTO bot_starboard_settings (bot_id, guild_id) VALUES (?, ?)')->execute([$botId, $guildId]);
        $s = $pdo->prepare('SELECT * FROM bot_starboard_settings WHERE bot_id = ? AND guild_id = ? LIMIT 1');
        $s->execute([$botId, $guildId]);
        $settings = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {}
}

// ── Starboard stats ───────────────────────────────────────────────────────────
$totalStarred = 0;
try {
    $r = $pdo->prepare('SELECT COUNT(*) FROM bot_starboard_entries WHERE bot_id = ? AND guild_id = ?');
    $r->execute([$botId, $guildId]);
    $totalStarred = (int)$r->fetchColumn();
} catch (Throwable) {}

$sbChannel    = sb_e((string)($settings['channel_id']    ?? ''));
$sbEmoji      = sb_e((string)($settings['emoji']         ?? '⭐'));
$sbThreshold  = (int)($settings['threshold']    ?? 3);
$sbSelfStar   = !empty($settings['allow_self_star']);
$sbIgnoreBots = (bool)($settings['ignore_bots'] ?? 1);
$sbHasChannel = $sbChannel !== '';
?>

<style>
.sb-hero {
    position: relative; border-radius: 12px; overflow: hidden;
    padding: 28px 28px 24px; margin-bottom: 20px;
    background: linear-gradient(135deg, #f59e0b 0%, #f97316 60%, #ef4444 100%);
    color: #fff;
}
.sb-hero__bg {
    position: absolute; inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.06'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}
.sb-hero__inner { position: relative; display: flex; align-items: flex-start; gap: 18px; }
.sb-hero__icon {
    flex-shrink: 0; width: 52px; height: 52px;
    background: rgba(255,255,255,.18); border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 26px; backdrop-filter: blur(4px);
}
.sb-hero__text { flex: 1; min-width: 0; }
.sb-hero__kicker { font-size: 11px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; opacity: .75; margin-bottom: 4px; }
.sb-hero__title { font-size: 22px; font-weight: 700; margin: 0 0 6px; }
.sb-hero__sub { font-size: 13px; opacity: .82; line-height: 1.5; margin: 0; }
.sb-hero__badge {
    flex-shrink: 0; align-self: flex-start;
    padding: 3px 10px; border-radius: 99px; font-size: 11px; font-weight: 600;
    border: 1.5px solid rgba(255,255,255,.4); backdrop-filter: blur(4px);
}
.sb-hero__badge--on  { background: rgba(255,255,255,.18); color: #fff; }
.sb-hero__badge--off { background: rgba(0,0,0,.18); color: rgba(255,255,255,.65); }
</style>

<div class="lv-page">

    <!-- Hero -->
    <div class="sb-hero">
        <div class="sb-hero__bg"></div>
        <div class="sb-hero__inner">
            <div class="sb-hero__icon">⭐</div>
            <div class="sb-hero__text">
                <div class="sb-hero__kicker">Community · Highlights</div>
                <h1 class="sb-hero__title">Starboard</h1>
                <p class="sb-hero__sub">
                    Nachrichten die genug Reaktionen erhalten werden automatisch in einen dedizierten Starboard-Channel gepostet.
                </p>
            </div>
            <div class="sb-hero__badge" id="sb-hero-badge">
                <!--set by JS-->
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="lv-stats">
        <div class="lv-stat">
            <div class="lv-stat__val"><?= $totalStarred ?></div>
            <div class="lv-stat__lbl">Einträge gesamt</div>
        </div>
        <div class="lv-stat">
            <div class="lv-stat__val"><?= $sbEmoji ?></div>
            <div class="lv-stat__lbl">Emoji</div>
        </div>
        <div class="lv-stat">
            <div class="lv-stat__val"><?= $sbThreshold ?></div>
            <div class="lv-stat__lbl">Schwellenwert</div>
        </div>
        <div class="lv-stat">
            <div class="lv-stat__val"><?= $sbHasChannel ? '✓' : '—' ?></div>
            <div class="lv-stat__lbl">Channel gesetzt</div>
        </div>
    </div>

    <!-- Module toggle -->
    <?= bh_mod_render($modEnabled, $botId, 'module:starboard', 'Starboard', 'Starboard-Funktion für diesen Bot ein- oder ausschalten.') ?>

    <!-- ── Einstellungen (always accessible, outside disabled overlay) ──────── -->
    <div class="bh-card">
        <div class="bh-card-hdr lv-toggle-hdr" data-target="sb-settings-body">
            <div class="lv-card__hdr-left">
                <div class="lv-card__kicker">Konfiguration</div>
                <div class="bh-card-title">Starboard-Einstellungen</div>
            </div>
            <svg class="lv-chevron lv-chevron--open" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
            </svg>
        </div>
        <div class="lv-card__body" id="sb-settings-body">

            <!-- Channel picker -->
            <div class="bh-field">
                <label class="bh-label">Starboard-Channel</label>
                <input type="hidden" id="sb-channel-val" value="<?= $sbChannel ?>">
                <input type="hidden" id="sb-guild-val"   value="<?= sb_e($guildId) ?>">
                <div class="it-picker-row" id="sb-channel-box"
                     data-bh-val="sb-channel-val"
                     data-bh-guild="sb-guild-val"
                     data-bh-bot="<?= $botId ?>">
                    <button type="button" class="it-picker-add">+</button>
                </div>
                <div class="bh-hint">Der Channel in dem gestarrte Nachrichten erscheinen.</div>
            </div>

            <!-- Emoji + Schwellenwert -->
            <div class="bh-field">
                <div class="lv-grid2">
                    <div>
                        <label class="bh-label" for="sb-emoji">Reaktions-Emoji</label>
                        <input type="text" id="sb-emoji" class="bh-input" value="<?= $sbEmoji ?>"
                            placeholder="⭐" maxlength="100">
                        <div class="bh-hint">Standard-Emoji oder Custom-Emoji-Name</div>
                    </div>
                    <div>
                        <label class="bh-label" for="sb-threshold">Schwellenwert</label>
                        <input type="number" id="sb-threshold" class="bh-input" value="<?= $sbThreshold ?>"
                            min="1" max="100">
                        <div class="bh-hint">Mindestanzahl Reaktionen</div>
                    </div>
                </div>
            </div>

            <!-- Optionen -->
            <div class="lv-feature">
                <div class="lv-feature__left">
                    <div class="lv-feature__title">Selbst-Stars erlauben</div>
                    <div class="lv-feature__desc">Nutzer dürfen eigene Nachrichten auf den Starboard nominieren.</div>
                </div>
                <div class="lv-feature__right">
                    <label class="bh-toggle">
                        <input class="bh-toggle-input" type="checkbox" id="sb-self-star" <?= $sbSelfStar ? 'checked' : '' ?>>
                        <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                    </label>
                </div>
            </div>

            <div class="lv-feature">
                <div class="lv-feature__left">
                    <div class="lv-feature__title">Bot-Nachrichten ignorieren</div>
                    <div class="lv-feature__desc">Nachrichten von Bots werden nicht auf den Starboard gepostet.</div>
                </div>
                <div class="lv-feature__right">
                    <label class="bh-toggle">
                        <input class="bh-toggle-input" type="checkbox" id="sb-ignore-bots" <?= $sbIgnoreBots ? 'checked' : '' ?>>
                        <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                    </label>
                </div>
            </div>

            <div class="lv-btn-row">
                <button class="bh-btn bh-btn--primary" id="sb-save">Speichern</button>
                <span class="lv-save-msg" id="sb-msg"></span>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const BOT_ID      = <?= json_encode($botId) ?>;
    const GUILD_ID    = <?= json_encode($guildId) ?>;
    const SAVED_CHANNEL = <?= json_encode($sbChannel) ?>;
    let   modEnabled  = <?= json_encode((bool)$modEnabled) ?>;

    // ── Hero badge ────────────────────────────────────────────────────────────
    function updateBadge() {
        const badge = document.getElementById('sb-hero-badge');
        if (!badge) return;
        const channelSet = !!document.getElementById('sb-channel-val')?.value;
        const active = modEnabled && channelSet;
        badge.className = 'sb-hero__badge ' + (active ? 'sb-hero__badge--on' : 'sb-hero__badge--off');
        badge.textContent = active ? '● Aktiv' : '○ Inaktiv';
    }

    // Track module toggle state via the pill element
    const modPill = document.getElementById('bhmod_module_starboard_pill');
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

    // ── Channel picker ────────────────────────────────────────────────────────
    // ── Channel picker (initialised by channel-picker.js) ────────────────────
    document.getElementById('sb-channel-box')?.addEventListener('bh-picker-picked',  updateBadge);
    document.getElementById('sb-channel-box')?.addEventListener('bh-picker-cleared', updateBadge);

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

    document.getElementById('sb-save').addEventListener('click', () => {
        post({
            sb_action:       'save_settings',
            guild_id:        document.getElementById('sb-guild-val').value || GUILD_ID,
            channel_id:      document.getElementById('sb-channel-val').value,
            emoji:           document.getElementById('sb-emoji').value.trim() || '⭐',
            threshold:       document.getElementById('sb-threshold').value,
            allow_self_star: document.getElementById('sb-self-star').checked   ? '1' : '0',
            ignore_bots:     document.getElementById('sb-ignore-bots').checked ? '1' : '0',
        }).then(r => showMsg(document.getElementById('sb-msg'), r.ok, r.ok ? '✓ Gespeichert' : '✗ ' + (r.error || 'Fehler')));
    });

    updateBadge();
})();
</script>
