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

function cnt_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ── AJAX ───────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bh_mod_handle_ajax($pdo, $botId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['cnt_action'])) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');
    try {
        $action = (string)$_POST['cnt_action'];

        if ($action === 'save_settings') {
            $guildId        = (string)($_POST['guild_id']        ?? '');
            $channelId      = trim((string)($_POST['channel_id'] ?? ''));
            $mode           = in_array($_POST['mode'] ?? '', ['normal', 'webhook'], true) ? (string)$_POST['mode'] : 'normal';
            $reactEnabled   = isset($_POST['reactions_enabled'])  ? 1 : 0;
            $reactEmoji     = mb_substr(trim((string)($_POST['reaction_emoji'] ?? '✅')), 0, 100) ?: '✅';
            $allowMultiple  = isset($_POST['allow_multiple'])     ? 1 : 0;
            $cooldownEnabled= isset($_POST['cooldown_enabled'])   ? 1 : 0;
            $returnErrors   = isset($_POST['return_errors'])      ? 1 : 0;
            $errorWrong     = mb_substr(trim((string)($_POST['error_wrong_msg']    ?? '')), 0, 500) ?: null;
            $errorTwice     = mb_substr(trim((string)($_POST['error_twice_msg']    ?? '')), 0, 500) ?: null;
            $errorCooldown  = mb_substr(trim((string)($_POST['error_cooldown_msg'] ?? '')), 0, 500) ?: null;

            if ($guildId === '') throw new RuntimeException('Keine Guild-ID übergeben.');

            $pdo->prepare(
                'INSERT INTO bot_counting_settings
                    (bot_id, guild_id, channel_id, mode, reactions_enabled, reaction_emoji,
                     allow_multiple, cooldown_enabled, return_errors,
                     error_wrong_msg, error_twice_msg, error_cooldown_msg)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    channel_id       = VALUES(channel_id),
                    mode             = VALUES(mode),
                    reactions_enabled= VALUES(reactions_enabled),
                    reaction_emoji   = VALUES(reaction_emoji),
                    allow_multiple   = VALUES(allow_multiple),
                    cooldown_enabled = VALUES(cooldown_enabled),
                    return_errors    = VALUES(return_errors),
                    error_wrong_msg  = VALUES(error_wrong_msg),
                    error_twice_msg  = VALUES(error_twice_msg),
                    error_cooldown_msg = VALUES(error_cooldown_msg)'
            )->execute([
                $botId, $guildId,
                $channelId !== '' ? $channelId : null,
                $mode, $reactEnabled, $reactEmoji,
                $allowMultiple, $cooldownEnabled, $returnErrors,
                $errorWrong, $errorTwice, $errorCooldown,
            ]);

            echo json_encode(['ok' => true]); exit;
        }

        if ($action === 'toggle_command') {
            $key = (string)($_POST['command_key'] ?? '');
            if (empty($key)) throw new RuntimeException('Command key missing.');
            bhcmd_set_module_enabled($pdo, $botId, $key, (int)($_POST['enabled'] ?? 0));
            bh_notify_slash_sync($botId);
            echo json_encode(['ok' => true]); exit;
        }

        if ($action === 'reset_count') {
            $guildId = (string)($_POST['guild_id'] ?? '');
            if ($guildId === '') throw new RuntimeException('Keine Guild-ID übergeben.');
            $pdo->prepare(
                'UPDATE bot_counting_state
                 SET current_count = 0, last_user_id = NULL, last_message_id = NULL, last_count_at = NULL
                 WHERE bot_id = ? AND guild_id = ?'
            )->execute([$botId, $guildId]);
            echo json_encode(['ok' => true]); exit;
        }

        throw new RuntimeException('Unbekannte Aktion.');
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]); exit;
    }
}

// ── Auto-migrate ──────────────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `bot_counting_settings` (
        `bot_id`              BIGINT UNSIGNED NOT NULL,
        `guild_id`            VARCHAR(20) NOT NULL,
        `channel_id`          VARCHAR(20) NULL DEFAULT NULL,
        `mode`                ENUM('normal','webhook') NOT NULL DEFAULT 'normal',
        `reactions_enabled`   TINYINT(1) NOT NULL DEFAULT 1,
        `reaction_emoji`      VARCHAR(100) NOT NULL DEFAULT '✅',
        `allow_multiple`      TINYINT(1) NOT NULL DEFAULT 0,
        `cooldown_enabled`    TINYINT(1) NOT NULL DEFAULT 0,
        `return_errors`       TINYINT(1) NOT NULL DEFAULT 1,
        `error_wrong_msg`     TEXT NULL DEFAULT NULL,
        `error_twice_msg`     TEXT NULL DEFAULT NULL,
        `error_cooldown_msg`  TEXT NULL DEFAULT NULL,
        UNIQUE KEY `uq_bot_guild` (`bot_id`, `guild_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `bot_counting_state` (
        `bot_id`          BIGINT UNSIGNED NOT NULL,
        `guild_id`        VARCHAR(20) NOT NULL,
        `current_count`   INT NOT NULL DEFAULT 0,
        `last_user_id`    VARCHAR(20) NULL DEFAULT NULL,
        `last_message_id` VARCHAR(20) NULL DEFAULT NULL,
        `last_count_at`   DATETIME NULL DEFAULT NULL,
        UNIQUE KEY `uq_bot_guild` (`bot_id`, `guild_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable) {}

// ── Guild context from global index.php selector ──────────────────────────────
$guildId = $currentGuildId ?? '';

// ── Load settings (INSERT IGNORE + SELECT) ────────────────────────────────────
$settings = [];
if ($guildId !== '') {
    try {
        $pdo->prepare(
            'INSERT IGNORE INTO bot_counting_settings (bot_id, guild_id) VALUES (?, ?)'
        )->execute([$botId, $guildId]);

        $stmt = $pdo->prepare('SELECT * FROM bot_counting_settings WHERE bot_id = ? AND guild_id = ? LIMIT 1');
        $stmt->execute([$botId, $guildId]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {}
}

// ── Load state ────────────────────────────────────────────────────────────────
$state = ['current_count' => 0, 'last_user_id' => null];
if ($guildId !== '') {
    try {
        $pdo->prepare(
            'INSERT IGNORE INTO bot_counting_state (bot_id, guild_id) VALUES (?, ?)'
        )->execute([$botId, $guildId]);

        $stStmt = $pdo->prepare('SELECT * FROM bot_counting_state WHERE bot_id = ? AND guild_id = ? LIMIT 1');
        $stStmt->execute([$botId, $guildId]);
        $state = $stStmt->fetch(PDO::FETCH_ASSOC) ?: $state;
    } catch (Throwable) {}
}

// Defaults
$channelId      = (string)($settings['channel_id']       ?? '');
$mode           = (string)($settings['mode']             ?? 'normal');
$reactEnabled   = (bool)  ($settings['reactions_enabled'] ?? true);
$reactEmoji     = (string)($settings['reaction_emoji']   ?? '✅');
$allowMultiple  = (bool)  ($settings['allow_multiple']   ?? false);
$cooldownEnabled= (bool)  ($settings['cooldown_enabled'] ?? false);
$returnErrors   = (bool)  ($settings['return_errors']    ?? true);
$errorWrong     = (string)($settings['error_wrong_msg']  ?? '');
$errorTwice     = (string)($settings['error_twice_msg']  ?? '');
$errorCooldown  = (string)($settings['error_cooldown_msg'] ?? '');
$currentCount   = (int)   ($state['current_count']       ?? 0);

// ── Commands ──────────────────────────────────────────────────────────────────
$countingCommands = [
    ['key' => 'counting', 'name' => '/counting-set number', 'desc' => 'Setzt den aktuellen Count auf eine beliebige Zahl.'],
];

foreach ($countingCommands as $cmd) {
    $pdo->prepare(
        'INSERT INTO commands (bot_id, command_key, command_type, name, description, is_enabled)
         VALUES (?, ?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
             command_type = VALUES(command_type),
             name         = VALUES(name),
             description  = VALUES(description),
             updated_at   = NOW()'
    )->execute([$botId, $cmd['key'], 'module', $cmd['name'], $cmd['desc']]);
}

$cmdKeys  = array_column($countingCommands, 'key');
$cmdStmt  = $pdo->prepare(
    'SELECT command_key, is_enabled FROM commands WHERE bot_id=? AND command_key IN (' .
    implode(',', array_fill(0, count($cmdKeys), '?')) . ')'
);
$cmdStmt->execute(array_merge([$botId], $cmdKeys));
$cmdMap = [];
foreach ($cmdStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $cmdMap[$r['command_key']] = (bool)$r['is_enabled'];
}
$modEnabled = bh_mod_is_enabled($pdo, $botId, 'module:counting');
?>

<div class="lv-page">

    <!-- ── Header ───────────────────────────────────────────────────────────── -->
    <div class="lv-head">
        <div class="lv-kicker">Engagement</div>
        <h1 class="lv-title">Counting</h1>
        <p class="lv-subtitle">Lass deine Community gemeinsam zählen — mit Fehlerbehandlung, Cooldowns und Webhook-Modus.</p>
    </div>

    <?= bh_mod_render($modEnabled, $botId, 'module:counting', 'Counting', 'Zählkanal-Funktion und alle Counting-Commands für diesen Bot ein- oder ausschalten.') ?>
    <div>

    <!-- ── Stats ────────────────────────────────────────────────────────────── -->
    <div class="lv-stats">
        <div class="lv-stat">
            <div class="lv-stat__val cnt-count-display"><?= number_format($currentCount) ?></div>
            <div class="lv-stat__lbl">Aktueller Count</div>
        </div>
        <div class="lv-stat">
            <div class="lv-stat__val"><?= $channelId !== '' ? '<span class="badge badge--active">Konfiguriert</span>' : '<span class="badge badge--ended">Kein Channel</span>' ?></div>
            <div class="lv-stat__lbl">Zähl-Channel</div>
        </div>
        <div class="lv-stat">
            <div class="lv-stat__val"><?= $currentCount + 1 ?></div>
            <div class="lv-stat__lbl">Nächste Zahl</div>
        </div>
    </div>

    <!-- ── Einstellungen ────────────────────────────────────────────────────── -->
    <div class="bh-card">
        <div class="bh-card-hdr lv-toggle-hdr" data-target="cnt-settings-body">
            <div class="lv-card__hdr-left">
                <div class="lv-card__kicker">Konfiguration</div>
                <div class="bh-card-title">Einstellungen</div>
            </div>
            <svg class="lv-chevron lv-chevron--open" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
            </svg>
        </div>
        <div class="lv-card__body" id="cnt-settings-body">

            <!-- Channel picker -->
            <div class="bh-field">
                <label class="bh-label">Zähl-Channel</label>
                <input type="hidden" id="cnt-channel-val" value="<?= cnt_h($channelId) ?>">
                <input type="hidden" id="cnt-guild-val"   value="<?= cnt_h($guildId) ?>">
                <div class="it-picker-row" id="cnt-channel-box"
                     data-bh-val="cnt-channel-val"
                     data-bh-guild="cnt-guild-val"
                     data-bh-bot="<?= $botId ?>">
                    <button type="button" class="it-picker-add">+</button>
                </div>
                <div class="bh-hint">Der Channel, in dem gezählt wird. Leer lassen um das Modul zu deaktivieren.</div>
            </div>

            <!-- Mode -->
            <div class="bh-field">
                <label class="bh-label" for="cnt-mode">Modus</label>
                <select id="cnt-mode" class="bh-select" onchange="cntModeChange()">
                    <option value="normal"  <?= $mode === 'normal'  ? 'selected' : '' ?>>Normal — Bot reagiert mit Emoji</option>
                    <option value="webhook" <?= $mode === 'webhook' ? 'selected' : '' ?>>Webhook — Nachricht löschen & als Bot neu senden</option>
                </select>
                <div class="bh-hint">Im Webhook-Modus erscheint die Zahl als Bot-Nachricht mit dem Avatar des Nutzers.</div>
            </div>

            <!-- Reactions -->
            <div class="lv-feature" id="cnt-reactions-row">
                <div class="lv-feature__left">
                    <div class="lv-feature__title">Reactions aktiviert</div>
                    <div class="lv-feature__desc">Bot reagiert bei korrekter Zahl mit einem Emoji auf die Nachricht. (Nur im Normal-Modus sichtbar.)</div>
                </div>
                <div class="lv-feature__right">
                    <label class="bh-toggle">
                        <input class="bh-toggle-input" type="checkbox" id="cnt-reactions-enabled" <?= $reactEnabled ? 'checked' : '' ?> onchange="cntReactChange()">
                        <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                    </label>
                </div>
            </div>

            <!-- Emoji (show if reactions enabled) -->
            <div class="bh-field" id="cnt-emoji-field" <?= (!$reactEnabled || $mode === 'webhook') ? 'style="display:none"' : '' ?>>
                <label class="bh-label" for="cnt-reaction-emoji">Reaction Emoji</label>
                <input type="text" id="cnt-reaction-emoji" class="bh-input" value="<?= cnt_h($reactEmoji) ?>" placeholder="✅" maxlength="100" style="max-width:140px">
                <div class="bh-hint">Emoji das der Bot bei korrekter Zahl reagiert. Standard: ✅</div>
            </div>

            <!-- Allow multiple -->
            <div class="lv-feature">
                <div class="lv-feature__left">
                    <div class="lv-feature__title">Allow multiple counts</div>
                    <div class="lv-feature__desc">Gleicher User darf mehrmals nacheinander zählen. Standardmäßig deaktiviert.</div>
                </div>
                <div class="lv-feature__right">
                    <label class="bh-toggle">
                        <input class="bh-toggle-input" type="checkbox" id="cnt-allow-multiple" <?= $allowMultiple ? 'checked' : '' ?>>
                        <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                    </label>
                </div>
            </div>

            <!-- Cooldown -->
            <div class="lv-feature" style="border-bottom:none">
                <div class="lv-feature__left">
                    <div class="lv-feature__title">5s Cooldown</div>
                    <div class="lv-feature__desc">User müssen 5 Sekunden zwischen ihren Zählversuchen warten.</div>
                </div>
                <div class="lv-feature__right">
                    <label class="bh-toggle">
                        <input class="bh-toggle-input" type="checkbox" id="cnt-cooldown-enabled" <?= $cooldownEnabled ? 'checked' : '' ?>>
                        <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                    </label>
                </div>
            </div>

            <div class="lv-btn-row">
                <button class="bh-btn bh-btn--primary" id="cnt-save-btn" onclick="cntSaveSettings()">Speichern</button>
                <span id="cnt-save-msg" class="lv-save-msg" style="display:none"></span>
            </div>
        </div>
    </div>

    <!-- ── Fehler-Einstellungen ──────────────────────────────────────────────── -->
    <div class="bh-card">
        <div class="bh-card-hdr lv-toggle-hdr" data-target="cnt-errors-body">
            <div class="lv-card__hdr-left">
                <div class="lv-card__kicker">Nachrichten</div>
                <div class="bh-card-title">Fehler-Einstellungen</div>
            </div>
            <svg class="lv-chevron lv-chevron--open" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
            </svg>
        </div>
        <div class="lv-card__body" id="cnt-errors-body">

            <!-- Return errors toggle -->
            <div class="lv-feature">
                <div class="lv-feature__left">
                    <div class="lv-feature__title">Fehler zurückmelden</div>
                    <div class="lv-feature__desc">Bot antwortet bei Fehlern mit einer Nachricht. Die Antwort wird nach 6 Sekunden automatisch gelöscht.</div>
                </div>
                <div class="lv-feature__right">
                    <label class="bh-toggle">
                        <input class="bh-toggle-input" type="checkbox" id="cnt-return-errors" <?= $returnErrors ? 'checked' : '' ?>>
                        <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                    </label>
                </div>
            </div>

            <!-- Variables hint -->
            <div class="cnt-vars-row">
                <span class="cnt-vars-label">Variablen:</span>
                <span class="cnt-var-badge" data-var="{user}" title="Erwähnt den User">{user}</span>
                <span class="cnt-var-badge" data-var="{next}" title="Die nächste erwartete Zahl">{next}</span>
            </div>

            <!-- Error: wrong count -->
            <div class="bh-field">
                <label class="bh-label" for="cnt-error-wrong">Falsche Zahl</label>
                <textarea id="cnt-error-wrong" class="bh-input" rows="2" placeholder="❌ {user}, you counted wrong! The next number is **{next}**." style="resize:vertical"><?= cnt_h($errorWrong) ?></textarea>
                <div class="bh-hint">Wird gesendet wenn jemand die falsche Zahl oder kein Integer eingibt. Leer lassen für Standard.</div>
            </div>

            <!-- Error: counting twice -->
            <div class="bh-field">
                <label class="bh-label" for="cnt-error-twice">Doppelt gezählt</label>
                <textarea id="cnt-error-twice" class="bh-input" rows="2" placeholder="❌ {user}, you are only allowed to count once in a row!" style="resize:vertical"><?= cnt_h($errorTwice) ?></textarea>
                <div class="bh-hint">Wird gesendet wenn derselbe User zweimal nacheinander zählt (und "Allow multiple" deaktiviert ist).</div>
            </div>

            <!-- Error: cooldown -->
            <div class="bh-field">
                <label class="bh-label" for="cnt-error-cooldown">Cooldown aktiv</label>
                <textarea id="cnt-error-cooldown" class="bh-input" rows="2" placeholder="❌ {user}, please wait a moment before counting again!" style="resize:vertical"><?= cnt_h($errorCooldown) ?></textarea>
                <div class="bh-hint">Wird gesendet wenn der User noch im 5s Cooldown ist. Leer lassen für Standard.</div>
            </div>

            <div class="lv-btn-row">
                <button class="bh-btn bh-btn--primary" id="cnt-save-err-btn" onclick="cntSaveSettings()">Speichern</button>
                <span id="cnt-save-err-msg" class="lv-save-msg" style="display:none"></span>
            </div>
        </div>
    </div>

    <!-- ── Danger Zone ───────────────────────────────────────────────────────── -->
    <div class="bh-card">
        <div class="bh-card-hdr lv-toggle-hdr" data-target="cnt-danger-body">
            <div class="lv-card__hdr-left">
                <div class="lv-card__kicker">Danger Zone</div>
                <div class="bh-card-title">Count zurücksetzen</div>
            </div>
            <svg class="lv-chevron" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
            </svg>
        </div>
        <div class="lv-card__body lv-collapsed" id="cnt-danger-body">
            <div class="lv-feature" style="border-bottom:none">
                <div class="lv-feature__left">
                    <div class="lv-feature__title">Count auf 0 zurücksetzen</div>
                    <div class="lv-feature__desc">Setzt den aktuellen Count sowie den letzten User und Zeitstempel zurück. Diese Aktion kann nicht rückgängig gemacht werden.</div>
                </div>
                <div class="lv-feature__right">
                    <button class="bh-btn bh-btn--primary bh-btn bh-btn--danger" id="cnt-reset-btn" onclick="cntResetCount()">Zurücksetzen</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Commands ─────────────────────────────────────────────────────────── -->
    <div style="margin-top:8px">
        <div class="lv-kicker">Modul</div>
        <div class="lv-title" style="font-size:20px;margin-bottom:12px">Commands</div>
    </div>

    <div class="bh-cmd-grid">
        <?php foreach ($countingCommands as $cmd):
            $on = $cmdMap[$cmd['key']] ?? true;
        ?>
        <div class="bh-cmd-card">
            <div>
                <div class="bh-cmd-name"><?= cnt_h($cmd['name']) ?></div>
                <div class="bh-cmd-desc"><?= cnt_h($cmd['desc']) ?></div>
            </div>
            <label class="bh-toggle">
                <input type="checkbox" class="cntCmdToggle bh-toggle-input" data-key="<?= cnt_h($cmd['key']) ?>" <?= $on ? 'checked' : '' ?>>
                <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
            </label>
        </div>
        <?php endforeach; ?>
    </div>

    </div>
</div>

<script>
(function () {
    const BOT_ID   = <?= json_encode($botId) ?>;
    const GUILD_ID = <?= json_encode($guildId) ?>;

    // ── Channel picker (initialised by channel-picker.js) ────────────────────

    function post(data) {
        const fd = new FormData();
        for (const [k, v] of Object.entries(data)) fd.append(k, v);
        return fetch(window.location.href, { method: 'POST', body: fd }).then(r => r.json());
    }

    function showMsg(id, ok, text) {
        const el = document.getElementById(id);
        if (!el) return;
        el.textContent  = text;
        el.className    = 'lv-save-msg ' + (ok ? 'lv-save-msg--ok' : 'lv-save-msg--err');
        el.style.display = '';
        if (ok) setTimeout(() => { el.style.display = 'none'; }, 3000);
    }

    // ── Mode change: show/hide reactions ─────────────────────────────────────
    window.cntModeChange = function () {
        const mode       = document.getElementById('cnt-mode').value;
        const reactRow   = document.getElementById('cnt-reactions-row');
        const emojiField = document.getElementById('cnt-emoji-field');
        const reactChk   = document.getElementById('cnt-reactions-enabled');
        if (mode === 'webhook') {
            if (reactRow)   reactRow.style.display   = 'none';
            if (emojiField) emojiField.style.display = 'none';
        } else {
            if (reactRow)   reactRow.style.display   = '';
            if (emojiField) emojiField.style.display = reactChk && reactChk.checked ? '' : 'none';
        }
    };

    window.cntReactChange = function () {
        const checked    = document.getElementById('cnt-reactions-enabled').checked;
        const emojiField = document.getElementById('cnt-emoji-field');
        const mode       = document.getElementById('cnt-mode').value;
        if (emojiField) emojiField.style.display = (checked && mode !== 'webhook') ? '' : 'none';
    };

    // Apply on load
    cntModeChange();

    // ── Save settings ─────────────────────────────────────────────────────────
    window.cntSaveSettings = async function () {
        const saveBtn    = document.getElementById('cnt-save-btn');
        const saveErrBtn = document.getElementById('cnt-save-err-btn');
        if (saveBtn)    saveBtn.disabled    = true;
        if (saveErrBtn) saveErrBtn.disabled = true;

        const payload = {
            cnt_action:        'save_settings',
            guild_id:           document.getElementById('cnt-guild-val').value || GUILD_ID,
            channel_id:         document.getElementById('cnt-channel-val').value.trim(),
            mode:               document.getElementById('cnt-mode').value,
            reaction_emoji:     document.getElementById('cnt-reaction-emoji').value.trim(),
            error_wrong_msg:    document.getElementById('cnt-error-wrong').value.trim(),
            error_twice_msg:    document.getElementById('cnt-error-twice').value.trim(),
            error_cooldown_msg: document.getElementById('cnt-error-cooldown').value.trim(),
        };

        if (document.getElementById('cnt-reactions-enabled').checked) payload.reactions_enabled = '1';
        if (document.getElementById('cnt-allow-multiple').checked)    payload.allow_multiple     = '1';
        if (document.getElementById('cnt-cooldown-enabled').checked)  payload.cooldown_enabled   = '1';
        if (document.getElementById('cnt-return-errors').checked)     payload.return_errors      = '1';

        const res = await post(payload);

        const msg  = res.ok ? '✓ Gespeichert' : ('Fehler: ' + (res.error || 'Unbekannt'));
        showMsg('cnt-save-msg',     res.ok, msg);
        showMsg('cnt-save-err-msg', res.ok, msg);

        if (saveBtn)    saveBtn.disabled    = false;
        if (saveErrBtn) saveErrBtn.disabled = false;
    };

    // ── Reset count ───────────────────────────────────────────────────────────
    window.cntResetCount = async function () {
        if (!confirm('Count wirklich auf 0 zurücksetzen?')) return;
        const btn = document.getElementById('cnt-reset-btn');
        btn.disabled = true;
        const res = await post({ cnt_action: 'reset_count', guild_id: GUILD_ID });
        if (res.ok) {
            // Update displayed count
            const display = document.querySelector('.cnt-count-display');
            if (display) display.textContent = '0';
        } else {
            alert('Fehler: ' + (res.error || 'Unbekannt'));
        }
        btn.disabled = false;
    };

    // ── Command toggles ───────────────────────────────────────────────────────
    document.querySelectorAll('.cntCmdToggle').forEach(toggle => {
        toggle.addEventListener('change', async (e) => {
            const res = await post({
                cnt_action:  'toggle_command',
                command_key: toggle.dataset.key,
                enabled:     e.target.checked ? '1' : '0',
            });
            if (!res.ok) {
                alert('Fehler beim Speichern.');
                e.target.checked = !e.target.checked;
            }
        });
    });

    // ── Variable badges → clipboard ───────────────────────────────────────────
    document.querySelectorAll('.cnt-var-badge').forEach(badge => {
        badge.addEventListener('click', () => {
            navigator.clipboard.writeText(badge.dataset.var).then(() => {
                badge.classList.add('cnt-var-badge--copied');
                const orig = badge.textContent;
                badge.textContent = '✓ Kopiert';
                setTimeout(() => {
                    badge.classList.remove('cnt-var-badge--copied');
                    badge.textContent = orig;
                }, 1500);
            }).catch(() => {});
        });
    });

    // ── Collapsible cards ─────────────────────────────────────────────────────
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
