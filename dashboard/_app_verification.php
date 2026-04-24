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

function vfy_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function vfy_ensure_cmd(PDO $pdo, int $botId, string $key, string $name, string $desc): void {
    $pdo->prepare('INSERT IGNORE INTO commands (bot_id, command_key, command_type, name, description, is_enabled) VALUES (?, ?, ?, ?, ?, 1)')
        ->execute([$botId, $key, 'verification', $name, $desc]);
}

// ── AJAX ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bh_mod_handle_ajax($pdo, $botId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['vfy_action'])) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');
    try {
        $action = (string)$_POST['vfy_action'];

        if ($action === 'save_settings') {
            $guildId = (string)($_POST['guild_id'] ?? '');
            if (empty($guildId)) throw new RuntimeException('guild_id fehlt.');

            $vType         = in_array($_POST['verification_type'] ?? '', ['button', 'captcha'], true)
                                 ? (string)$_POST['verification_type'] : 'captcha';
            $channelId     = mb_substr(trim((string)($_POST['channel_id']     ?? '')), 0, 20)  ?: null;
            $verifiedRole  = mb_substr(trim((string)($_POST['verified_role_id'] ?? '')), 0, 20) ?: null;
            $embedAuthor   = mb_substr(trim((string)($_POST['embed_author']   ?? '')), 0, 256) ?: null;
            $embedTitle    = mb_substr(trim((string)($_POST['embed_title']    ?? '')), 0, 256) ?: null;
            $embedBody     = mb_substr(trim((string)($_POST['embed_body']     ?? '')), 0, 4096) ?: null;
            $embedImage    = mb_substr(trim((string)($_POST['embed_image']    ?? '')), 0, 512) ?: null;
            $embedFooter   = mb_substr(trim((string)($_POST['embed_footer']   ?? '')), 0, 256) ?: null;
            $embedColor    = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($_POST['embed_color'] ?? ''))
                                 ? (string)$_POST['embed_color'] : '#5ba9e4';
            $embedUrl      = mb_substr(trim((string)($_POST['embed_url']      ?? '')), 0, 512) ?: null;
            $buttonName    = mb_substr(trim((string)($_POST['button_name']    ?? '')), 0, 80)  ?: 'Start Verification';
            $logChannelId  = mb_substr(trim((string)($_POST['log_channel_id'] ?? '')), 0, 20)  ?: null;
            $successMsg    = mb_substr(trim((string)($_POST['success_message'] ?? '')), 0, 2000) ?: null;
            $maxAttempts   = (int)($_POST['max_attempts'] ?? 3);
            $timeLimitSec  = max(0, (int)($_POST['time_limit_sec'] ?? 0));

            $pdo->prepare(
                'INSERT INTO bot_verification_settings
                    (bot_id, guild_id, verification_type, channel_id, verified_role_id,
                     embed_author, embed_title, embed_body, embed_image, embed_footer,
                     embed_color, embed_url, button_name, log_channel_id, success_message,
                     max_attempts, time_limit_sec)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    verification_type = VALUES(verification_type),
                    channel_id        = VALUES(channel_id),
                    verified_role_id  = VALUES(verified_role_id),
                    embed_author      = VALUES(embed_author),
                    embed_title       = VALUES(embed_title),
                    embed_body        = VALUES(embed_body),
                    embed_image       = VALUES(embed_image),
                    embed_footer      = VALUES(embed_footer),
                    embed_color       = VALUES(embed_color),
                    embed_url         = VALUES(embed_url),
                    button_name       = VALUES(button_name),
                    log_channel_id    = VALUES(log_channel_id),
                    success_message   = VALUES(success_message),
                    max_attempts      = VALUES(max_attempts),
                    time_limit_sec    = VALUES(time_limit_sec)'
            )->execute([
                $botId, $guildId, $vType, $channelId, $verifiedRole,
                $embedAuthor, $embedTitle, $embedBody, $embedImage, $embedFooter,
                $embedColor, $embedUrl, $buttonName, $logChannelId, $successMsg,
                $maxAttempts, $timeLimitSec,
            ]);

            echo json_encode(['ok' => true]); exit;
        }

        if ($action === 'toggle_command') {
            $key = (string)($_POST['command_key'] ?? '');
            if (empty($key)) throw new RuntimeException('Command key fehlt.');
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS `bot_verification_settings` (
        `bot_id`            BIGINT UNSIGNED NOT NULL,
        `guild_id`          VARCHAR(20) NOT NULL,
        `verification_type` ENUM('button','captcha') NOT NULL DEFAULT 'captcha',
        `channel_id`        VARCHAR(20) NULL DEFAULT NULL,
        `verified_role_id`  VARCHAR(20) NULL DEFAULT NULL,
        `embed_author`      VARCHAR(256) NULL DEFAULT NULL,
        `embed_title`       VARCHAR(256) NULL DEFAULT NULL,
        `embed_body`        TEXT NULL DEFAULT NULL,
        `embed_image`       VARCHAR(512) NULL DEFAULT NULL,
        `embed_footer`      VARCHAR(256) NULL DEFAULT NULL,
        `embed_color`       VARCHAR(7) NOT NULL DEFAULT '#5ba9e4',
        `embed_url`         VARCHAR(512) NULL DEFAULT NULL,
        `button_name`       VARCHAR(80) NOT NULL DEFAULT 'Start Verification',
        `log_channel_id`    VARCHAR(20) NULL DEFAULT NULL,
        `success_message`   TEXT NULL DEFAULT NULL,
        `max_attempts`      INT NOT NULL DEFAULT 3,
        `time_limit_sec`    INT NOT NULL DEFAULT 0,
        UNIQUE KEY `uq_bot_guild` (`bot_id`, `guild_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `bot_verification_pending` (
        `bot_id`      BIGINT UNSIGNED NOT NULL,
        `guild_id`    VARCHAR(20) NOT NULL,
        `user_id`     VARCHAR(20) NOT NULL,
        `joined_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `kick_after`  DATETIME NULL DEFAULT NULL,
        `attempts`    INT NOT NULL DEFAULT 0,
        UNIQUE KEY `uq_bot_guild_user` (`bot_id`, `guild_id`, `user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable) {}

// ── Lade Settings ─────────────────────────────────────────────────────────────
// Wir brauchen eine Guild-ID — nutze den ersten Eintrag oder Dummy
$vfySettings = [];
$guildIdForForm = '';
try {
    $s = $pdo->prepare('SELECT * FROM bot_verification_settings WHERE bot_id = ? ORDER BY guild_id ASC LIMIT 1');
    $s->execute([$botId]);
    $vfySettings = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    $guildIdForForm = (string)($vfySettings['guild_id'] ?? '');
} catch (Throwable) {}

// Helper to get setting value with fallback
$vGet = fn(string $k, mixed $def = '') => $vfySettings[$k] ?? $def;

$vType        = (string)$vGet('verification_type', 'captcha');
$channelId    = (string)$vGet('channel_id', '');
$verifiedRole = (string)$vGet('verified_role_id', '');
$embedAuthor  = (string)$vGet('embed_author', '');
$embedTitle   = (string)$vGet('embed_title', '');
$embedBody    = (string)$vGet('embed_body', '');
$embedImage   = (string)$vGet('embed_image', '');
$embedFooter  = (string)$vGet('embed_footer', '');
$embedColor   = (string)$vGet('embed_color', '#5ba9e4');
$embedUrl     = (string)$vGet('embed_url', '');
$buttonName   = (string)$vGet('button_name', 'Start Verification');
$logChannelId = (string)$vGet('log_channel_id', '');
$successMsg   = (string)$vGet('success_message', '');
$maxAttempts  = (int)$vGet('max_attempts', 3);
$timeLimitSec = (int)$vGet('time_limit_sec', 0);

// ── Commands ──────────────────────────────────────────────────────────────────
$vfyCommands = [
    ['key' => 'verification-setup', 'name' => '/verification setup', 'desc' => 'Sendet das Verification-Embed in den konfigurierten Channel.'],
    ['key' => 'verification-add',   'name' => '/verification add',   'desc' => 'Verifiziert einen User manuell und vergibt die Verified-Rolle.'],
];

foreach ($vfyCommands as $cmd) {
    vfy_ensure_cmd($pdo, $botId, $cmd['key'], $cmd['name'], $cmd['desc']);
}

$cmdKeys = array_column($vfyCommands, 'key');
$cmdStmt = $pdo->prepare(
    'SELECT command_key, is_enabled FROM commands WHERE bot_id=? AND command_key IN (' .
    implode(',', array_fill(0, count($cmdKeys), '?')) . ')'
);
$cmdStmt->execute(array_merge([$botId], $cmdKeys));
$cmdMap = [];
foreach ($cmdStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $cmdMap[$r['command_key']] = (bool)$r['is_enabled'];
}
$modEnabled = bh_mod_is_enabled($pdo, $botId, 'module:verification');
?>

<div class="lv-page">

    <!-- ── Header ──────────────────────────────────────────────────────── -->
    <div class="lv-head">
        <div class="lv-kicker">Sicherheit</div>
        <h1 class="lv-title">Verification</h1>
        <p class="lv-subtitle">Schütze deinen Server mit Button- oder Captcha-Verifikation — neue Mitglieder erhalten erst nach erfolgreicher Prüfung ihre Rolle.</p>
    </div>

    <?= bh_mod_render($modEnabled, $botId, 'module:verification', 'Verification', 'Verifikationssystem und alle Verification-Commands für diesen Bot ein- oder ausschalten.') ?>
    <div id="bh-mod-body">

    <!-- ── Guild-ID Hinweis ────────────────────────────────────────────── -->
    <div class="bh-card" style="margin-bottom:16px">
        <div class="bh-field" style="border-bottom:none">
            <label class="bh-label" for="vfy-guild-id">Server (Guild) ID <span style="color:#e05252">*</span></label>
            <input type="text" id="vfy-guild-id" class="bh-input" placeholder="z.B. 123456789012345678"
                   value="<?= vfy_h($guildIdForForm) ?>" maxlength="20">
            <div class="bh-hint">Die Discord-Server-ID für die diese Einstellungen gelten. Zu finden unter Server-Einstellungen → Widget oder per Rechtsklick auf den Server.</div>
        </div>
    </div>

    <!-- ── Grundeinstellungen ───────────────────────────────────────────── -->
    <div class="bh-card">
        <div class="bh-card-hdr lv-toggle-hdr" data-target="vfy-basic-body">
            <div class="lv-card__hdr-left">
                <div class="lv-card__kicker">Einstellungen</div>
                <div class="bh-card-title">Grundeinstellungen</div>
            </div>
            <svg class="lv-chevron lv-chevron--open" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
            </svg>
        </div>
        <div class="lv-card__body" id="vfy-basic-body">

            <!-- Verification Type -->
            <div class="bh-field">
                <label class="bh-label" for="vfy-type">Verification Type</label>
                <select id="vfy-type" class="bh-select">
                    <option value="button"  <?= $vType === 'button'  ? 'selected' : '' ?>>Nur Button — Klick auf Button vergibt direkt die Rolle</option>
                    <option value="captcha" <?= $vType === 'captcha' ? 'selected' : '' ?>>Captcha Text Input — User muss Code aus Modal eingeben</option>
                </select>
                <div class="bh-hint">Bei <strong>Nur Button</strong> reicht ein Klick. Bei <strong>Captcha</strong> öffnet sich ein Modal mit einem 6-stelligen Code den der User abtippen muss.</div>
            </div>

            <!-- Default Channel -->
            <div class="bh-field">
                <label class="bh-label">Default Channel</label>
                <input type="hidden" id="vfy-channel-id" value="<?= vfy_h($channelId) ?>">
                <div class="it-picker-row" id="vfy-channel-id-box">
                    <button type="button" class="it-picker-add" id="vfy-channel-id-btn">+</button>
                </div>
                <div class="bh-hint">In diesen Channel sendet <code>/verification setup</code> das Embed.</div>
            </div>

            <!-- Verified Role -->
            <div class="bh-field" style="border-bottom:none">
                <label class="bh-label" for="vfy-role-id">Verified Role ID</label>
                <input type="text" id="vfy-role-id" class="bh-input" placeholder="Rollen-ID eingeben"
                       value="<?= vfy_h($verifiedRole) ?>" maxlength="20">
                <div class="bh-hint">Diese Rolle wird nach erfolgreicher Verifikation vergeben. Servereinstellungen → Rollen → Rolle anklicken → ID kopieren.</div>
            </div>

        </div>
    </div>

    <!-- ── Verification Menu ────────────────────────────────────────────── -->
    <div class="bh-card">
        <div class="bh-card-hdr lv-toggle-hdr" data-target="vfy-embed-body">
            <div class="lv-card__hdr-left">
                <div class="lv-card__kicker">Embed</div>
                <div class="bh-card-title">Verification Menu</div>
            </div>
            <svg class="lv-chevron lv-chevron--open" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
            </svg>
        </div>
        <div class="lv-card__body" id="vfy-embed-body">

            <!-- Author + Title -->
            <div class="bh-field">
                <div class="lv-grid2">
                    <div>
                        <label class="bh-label" for="vfy-embed-author">Author</label>
                        <input type="text" id="vfy-embed-author" class="bh-input" placeholder="z.B. Verification System"
                               value="<?= vfy_h($embedAuthor) ?>" maxlength="256">
                    </div>
                    <div>
                        <label class="bh-label" for="vfy-embed-title">Title</label>
                        <input type="text" id="vfy-embed-title" class="bh-input" placeholder="z.B. Server Verification"
                               value="<?= vfy_h($embedTitle) ?>" maxlength="256">
                    </div>
                </div>
            </div>

            <!-- Body -->
            <div class="bh-field">
                <label class="bh-label" for="vfy-embed-body-text">Body (Beschreibung)</label>
                <textarea id="vfy-embed-body-text" class="bh-input" rows="4"
                          placeholder="z.B. Klicke auf den Button unten um dich zu verifizieren und Zugang zum Server zu erhalten."
                          style="resize:vertical"><?= vfy_h($embedBody) ?></textarea>
                <div class="bh-hint">Unterstützt Discord-Markdown: **fett**, *kursiv*, `code`, > Zitat</div>
            </div>

            <!-- Image URL + Embed URL -->
            <div class="bh-field">
                <div class="lv-grid2">
                    <div>
                        <label class="bh-label" for="vfy-embed-image">Image URL</label>
                        <input type="url" id="vfy-embed-image" class="bh-input" placeholder="https://..."
                               value="<?= vfy_h($embedImage) ?>" maxlength="512">
                        <div class="bh-hint">Großes Bild im Embed (optional)</div>
                    </div>
                    <div>
                        <label class="bh-label" for="vfy-embed-url">Embed URL</label>
                        <input type="url" id="vfy-embed-url" class="bh-input" placeholder="https://..."
                               value="<?= vfy_h($embedUrl) ?>" maxlength="512">
                        <div class="bh-hint">Macht den Titel klickbar (optional)</div>
                    </div>
                </div>
            </div>

            <!-- Footer + Color -->
            <div class="bh-field">
                <div class="lv-grid2">
                    <div>
                        <label class="bh-label" for="vfy-embed-footer">Footer</label>
                        <input type="text" id="vfy-embed-footer" class="bh-input" placeholder="z.B. Dein Server-Name"
                               value="<?= vfy_h($embedFooter) ?>" maxlength="256">
                    </div>
                    <div>
                        <label class="bh-label" for="vfy-embed-color-hex">Embed Color</label>
                        <div class="lv-color-row">
                            <div class="lv-color-swatch" id="vfy-color-swatch"
                                 style="background:<?= vfy_h($embedColor) ?>"></div>
                            <input type="color" id="vfy-embed-color-picker"
                                   value="<?= vfy_h($embedColor) ?>"
                                   style="position:absolute;opacity:0;pointer-events:none;width:0;height:0">
                            <input type="text" id="vfy-embed-color-hex" class="bh-input"
                                   value="<?= vfy_h($embedColor) ?>" maxlength="7" placeholder="#5ba9e4"
                                   style="font-family:monospace">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Button Name + Log Channel -->
            <div class="bh-field" style="border-bottom:none">
                <div class="lv-grid2">
                    <div>
                        <label class="bh-label" for="vfy-button-name">Button Name</label>
                        <input type="text" id="vfy-button-name" class="bh-input" placeholder="Start Verification"
                               value="<?= vfy_h($buttonName) ?>" maxlength="80">
                        <div class="bh-hint">Beschriftung des Buttons im Embed</div>
                    </div>
                    <div>
                        <label class="bh-label">Logging Channel</label>
                        <input type="hidden" id="vfy-log-channel" value="<?= vfy_h($logChannelId) ?>">
                        <div class="it-picker-row" id="vfy-log-channel-box">
                            <button type="button" class="it-picker-add" id="vfy-log-channel-btn">+</button>
                        </div>
                        <div class="bh-hint">Für erfolgreiche/fehlgeschlagene Verifikationen (optional)</div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- ── Extra Einstellungen ──────────────────────────────────────────── -->
    <div class="bh-card">
        <div class="bh-card-hdr lv-toggle-hdr" data-target="vfy-extra-body">
            <div class="lv-card__hdr-left">
                <div class="lv-card__kicker">Erweitert</div>
                <div class="bh-card-title">Extra Einstellungen</div>
            </div>
            <svg class="lv-chevron lv-chevron--open" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
            </svg>
        </div>
        <div class="lv-card__body" id="vfy-extra-body">

            <!-- Success Message -->
            <div class="bh-field">
                <label class="bh-label" for="vfy-success-msg">Success Message</label>
                <textarea id="vfy-success-msg" class="bh-input" rows="3"
                          placeholder="✅ Du wurdest erfolgreich verifiziert!"
                          style="resize:vertical"><?= vfy_h($successMsg) ?></textarea>
                <div class="bh-hint">Ephemeral-Nachricht die der User nach erfolgreicher Verifikation sieht. Leer lassen für Standard.</div>
            </div>

            <!-- Max Attempts + Time Limit -->
            <div class="bh-field">
                <div class="lv-grid2">
                    <div>
                        <label class="bh-label" for="vfy-max-attempts">Allowed Attempts</label>
                        <input type="number" id="vfy-max-attempts" class="bh-input" min="0" max="99"
                               placeholder="0 = deaktiviert"
                               value="<?= $maxAttempts ?>">
                        <div class="bh-hint">Max. Fehlversuche vor Auto-Kick. 0 = kein Auto-Kick bei Fehlversuchen. (Nur bei Captcha relevant)</div>
                    </div>
                    <div>
                        <label class="bh-label" for="vfy-time-limit">Time Limit (Sekunden)</label>
                        <input type="number" id="vfy-time-limit" class="bh-input" min="0"
                               placeholder="0 = deaktiviert"
                               value="<?= $timeLimitSec ?>">
                        <div class="bh-hint">Sekunden nach Join ohne Verifikation → Auto-Kick. 0 = deaktiviert.</div>
                    </div>
                </div>
            </div>

            <!-- Auto-Kick Toggle -->
            <div class="lv-feature" style="border-bottom:none">
                <div class="lv-feature__left">
                    <div class="lv-feature__title">Auto-Kick bei Zeitüberschreitung</div>
                    <div class="lv-feature__desc">Mitglieder die sich nicht innerhalb des Zeitlimits verifizieren werden automatisch gekickt. Nur aktiv wenn Time Limit > 0.</div>
                </div>
                <div class="lv-feature__right">
                    <label class="bh-toggle">
                        <input class="bh-toggle-input" type="checkbox" id="vfy-autokick-toggle" <?= $timeLimitSec > 0 ? 'checked' : '' ?>>
                        <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                    </label>
                </div>
            </div>

        </div>
    </div>

    <!-- ── Speichern ────────────────────────────────────────────────────── -->
    <div style="padding: 4px 0 20px">
        <div class="lv-btn-row" style="padding-left:0">
            <button class="bh-btn bh-btn--primary" id="vfy-save-btn" onclick="vfySaveSettings()">Einstellungen speichern</button>
            <span id="vfy-save-msg" class="lv-save-msg" style="display:none"></span>
        </div>
    </div>

    <!-- ── Commands ─────────────────────────────────────────────────────── -->
    <div style="margin-top:8px">
        <div class="lv-kicker">Modul</div>
        <div class="lv-title" style="font-size:20px;margin-bottom:12px">Commands</div>
    </div>

    <div class="bh-cmd-grid">
        <?php foreach ($vfyCommands as $cmd):
            $on = $cmdMap[$cmd['key']] ?? true;
        ?>
        <div class="bh-cmd-card">
            <div>
                <div class="bh-cmd-name"><?= vfy_h($cmd['name']) ?></div>
                <div class="bh-cmd-desc"><?= vfy_h($cmd['desc']) ?></div>
            </div>
            <label class="bh-toggle">
                <input type="checkbox" class="vfyCmdToggle bh-toggle-input" data-key="<?= vfy_h($cmd['key']) ?>" <?= $on ? 'checked' : '' ?>>
                <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
            </label>
        </div>
        <?php endforeach; ?>
    </div>

    </div><!-- /bh-mod-body -->
</div>

<script>
(function () {
    const BOT_ID = <?= json_encode($botId) ?>;

    bhSetupChannelPicker('vfy-channel-id-box', 'vfy-channel-id', 'vfy-channel-id-btn', BOT_ID);
    bhSetupChannelPicker('vfy-log-channel-box', 'vfy-log-channel', 'vfy-log-channel-btn', BOT_ID);

    function post(data) {
        const fd = new FormData();
        for (const [k, v] of Object.entries(data)) fd.append(k, v);
        return fetch(window.location.href, { method: 'POST', body: fd }).then(r => r.json());
    }

    // ── Save Settings ─────────────────────────────────────────────────────
    window.vfySaveSettings = async function () {
        const btn = document.getElementById('vfy-save-btn');
        const msg = document.getElementById('vfy-save-msg');
        btn.disabled = true;
        msg.style.display = 'none';

        const guildId = document.getElementById('vfy-guild-id').value.trim();
        if (!guildId) {
            msg.textContent  = 'Fehler: Guild ID ist erforderlich.';
            msg.className    = 'lv-save-msg lv-save-msg--err';
            msg.style.display = '';
            btn.disabled = false;
            return;
        }

        // Sync time limit with toggle
        const autoKick = document.getElementById('vfy-autokick-toggle').checked;
        const timeLimitInput = document.getElementById('vfy-time-limit');
        if (!autoKick) timeLimitInput.value = '0';

        const res = await post({
            vfy_action:        'save_settings',
            guild_id:          guildId,
            verification_type: document.getElementById('vfy-type').value,
            channel_id:        document.getElementById('vfy-channel-id').value.trim(),
            verified_role_id:  document.getElementById('vfy-role-id').value.trim(),
            embed_author:      document.getElementById('vfy-embed-author').value.trim(),
            embed_title:       document.getElementById('vfy-embed-title').value.trim(),
            embed_body:        document.getElementById('vfy-embed-body-text').value.trim(),
            embed_image:       document.getElementById('vfy-embed-image').value.trim(),
            embed_footer:      document.getElementById('vfy-embed-footer').value.trim(),
            embed_color:       document.getElementById('vfy-embed-color-hex').value.trim() || '#5ba9e4',
            embed_url:         document.getElementById('vfy-embed-url').value.trim(),
            button_name:       document.getElementById('vfy-button-name').value.trim() || 'Start Verification',
            log_channel_id:    document.getElementById('vfy-log-channel').value.trim(),
            success_message:   document.getElementById('vfy-success-msg').value.trim(),
            max_attempts:      document.getElementById('vfy-max-attempts').value,
            time_limit_sec:    timeLimitInput.value,
        });

        msg.textContent   = res.ok ? '✓ Gespeichert' : ('Fehler: ' + (res.error || 'Unbekannt'));
        msg.className     = 'lv-save-msg ' + (res.ok ? 'lv-save-msg--ok' : 'lv-save-msg--err');
        msg.style.display = '';
        btn.disabled = false;
        if (res.ok) setTimeout(() => { msg.style.display = 'none'; }, 3000);
    };

    // ── Auto-Kick Toggle ──────────────────────────────────────────────────
    document.getElementById('vfy-autokick-toggle').addEventListener('change', function () {
        const timeLimit = document.getElementById('vfy-time-limit');
        if (!this.checked) {
            timeLimit.value = '0';
        } else if (parseInt(timeLimit.value, 10) === 0) {
            timeLimit.value = '300'; // default 5 minutes when enabling
        }
    });

    document.getElementById('vfy-time-limit').addEventListener('input', function () {
        const toggle = document.getElementById('vfy-autokick-toggle');
        toggle.checked = parseInt(this.value, 10) > 0;
    });

    // ── Color picker ──────────────────────────────────────────────────────
    const colorSwatch = document.getElementById('vfy-color-swatch');
    const colorPicker = document.getElementById('vfy-embed-color-picker');
    const colorHex    = document.getElementById('vfy-embed-color-hex');

    colorSwatch.addEventListener('click', () => colorPicker.click());

    colorPicker.addEventListener('input', function () {
        colorHex.value = this.value;
        colorSwatch.style.background = this.value;
    });

    colorHex.addEventListener('input', function () {
        const val = this.value.trim();
        if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
            colorPicker.value = val;
            colorSwatch.style.background = val;
        }
    });

    colorHex.addEventListener('blur', function () {
        let val = this.value.trim();
        if (val && !val.startsWith('#')) val = '#' + val;
        if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
            this.value = val;
            colorPicker.value = val;
            colorSwatch.style.background = val;
        } else {
            this.value = colorPicker.value || '#5ba9e4';
            colorSwatch.style.background = this.value;
        }
    });

    // ── Command Toggles ───────────────────────────────────────────────────
    document.querySelectorAll('.vfyCmdToggle').forEach(toggle => {
        toggle.addEventListener('change', async (e) => {
            const res = await post({
                vfy_action:  'toggle_command',
                command_key: toggle.dataset.key,
                enabled:     e.target.checked ? '1' : '0',
            });
            if (!res.ok) {
                alert('Fehler beim Speichern.');
                e.target.checked = !e.target.checked;
            }
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
