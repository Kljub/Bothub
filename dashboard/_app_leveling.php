<?php
declare(strict_types=1);

if (!isset($currentBotId) || $currentBotId <= 0) {
    echo '<p style="color:var(--bh-text-muted,#8b949e)">Kein Bot ausgewählt.</p>';
    return;
}

$botId = (int)$currentBotId;

require_once __DIR__ . '/../functions/custom_commands.php';
require_once __DIR__ . '/../functions/module_toggle.php';
$pdo = bh_cc_get_pdo();

// ── Helpers ───────────────────────────────────────────────────────────────────
function lv_ensure_settings(PDO $pdo, int $botId): array {
    $pdo->prepare('INSERT IGNORE INTO leveling_settings (bot_id) VALUES (?)')->execute([$botId]);
    $s = $pdo->prepare('SELECT * FROM leveling_settings WHERE bot_id = ? LIMIT 1');
    $s->execute([$botId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: [];
}

function lv_ensure_cmd(PDO $pdo, int $botId, string $key, string $name, string $desc): void {
    $pdo->prepare('INSERT IGNORE INTO commands (bot_id, command_key, name, description, is_enabled) VALUES (?,?,?,?,1)')
        ->execute([$botId, $key, $name, $desc]);
}

function lv_bool(array $s, string $k, bool $d = false): bool { return isset($s[$k]) ? (bool)$s[$k] : $d; }
function lv_int(array $s, string $k, int $d = 0): int { return isset($s[$k]) ? (int)$s[$k] : $d; }
function lv_str(array $s, string $k, string $d = ''): string { return isset($s[$k]) ? (string)$s[$k] : $d; }
function lv_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ── AJAX ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bh_mod_handle_ajax($pdo, $botId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['lv_action'])) {
    header('Content-Type: application/json');
    try {
        $action = (string)$_POST['lv_action'];

        // SECURITY: Verify bot ownership
        $ownerCheck = $pdo->prepare('SELECT id FROM bot_instances WHERE id = ? AND owner_user_id = ? LIMIT 1');
        $ownerCheck->execute([$botId, (int)$_SESSION['user_id']]);
        if (!$ownerCheck->fetch()) {
            echo json_encode(['ok' => false, 'error' => 'Nicht autorisiert.']); exit;
        }

        if ($action === 'save_settings') {
            lv_ensure_settings($pdo, $botId);
            $color  = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['embed_color'] ?? '') ? (string)$_POST['embed_color'] : '#f45142';
            $lvMsg  = in_array($_POST['levelup_message'] ?? '', ['disabled','current_channel','dm'], true) ? (string)$_POST['levelup_message'] : 'disabled';
            $pdo->prepare(
                'UPDATE leveling_settings SET embed_color=?,max_level=?,xp_per_level=?,clear_on_leave=?,levelup_message=?,
                 msg_xp_min=?,msg_xp_max=?,msg_cooldown=?,voice_xp_enabled=?,voice_xp_per_minute=?,sum_boosts=?,randomize_boosts=?
                 WHERE bot_id=?'
            )->execute([
                $color, max(0,(int)($_POST['max_level']??0)), max(1,(int)($_POST['xp_per_level']??50)),
                (int)($_POST['clear_on_leave']??0), $lvMsg,
                max(1,(int)($_POST['msg_xp_min']??10)), max(1,(int)($_POST['msg_xp_max']??25)),
                max(0,(int)($_POST['msg_cooldown']??15)), (int)($_POST['voice_xp_enabled']??0),
                max(1,(int)($_POST['voice_xp_per_minute']??5)), (int)($_POST['sum_boosts']??0),
                (int)($_POST['randomize_boosts']??0), $botId,
            ]);
            echo json_encode(['ok' => true]); exit;
        }

        if ($action === 'add_booster') {
            $type   = ($_POST['booster_type'] ?? '') === 'channel' ? 'channel' : 'role';
            $target = preg_replace('/\D/', '', (string)($_POST['target_id'] ?? ''));
            if ($target === '') throw new RuntimeException('Ungültige ID.');
            $pdo->prepare('INSERT INTO leveling_boosters (bot_id,booster_type,target_id,percentage) VALUES (?,?,?,?)')
                ->execute([$botId, $type, $target, max(1,(int)($_POST['percentage']??10))]);
            echo json_encode(['ok' => true]); exit;
        }

        if ($action === 'delete_booster') {
            $id = (int)($_POST['booster_id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Ungültige ID.');
            $pdo->prepare('DELETE FROM leveling_boosters WHERE id=? AND bot_id=?')->execute([$id, $botId]);
            echo json_encode(['ok' => true]); exit;
        }

        if ($action === 'toggle_command') {
            $key = preg_replace('/[^a-z_]/', '', (string)($_POST['command_key'] ?? ''));
            $pdo->prepare('UPDATE commands SET is_enabled=? WHERE bot_id=? AND command_key=?')
                ->execute([(int)($_POST['enabled']??0), $botId, $key]);
            echo json_encode(['ok' => true]); exit;
        }

        throw new RuntimeException('Unbekannte Aktion.');
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]); exit;
    }
}

// ── Load data ─────────────────────────────────────────────────────────────────
$settings = lv_ensure_settings($pdo, $botId);

lv_ensure_cmd($pdo, $botId, 'rank',             'rank',              'Get your or another user\'s experience progress');
lv_ensure_cmd($pdo, $botId, 'level_leaderboard','level leaderboard', 'Check the leveling leaderboard of this server');
lv_ensure_cmd($pdo, $botId, 'level_editxp',     'level edit-xp',     'Edit someone\'s experience or level');
lv_ensure_cmd($pdo, $botId, 'level_reset',      'level reset-leaderboard', 'Reset this server\'s leveling leaderboard');

$cmdStmt = $pdo->prepare('SELECT command_key, is_enabled FROM commands WHERE bot_id=? AND command_key IN (?,?,?,?)');
$cmdStmt->execute([$botId, 'rank','level_leaderboard','level_editxp','level_reset']);
$cmdMap = [];
foreach ($cmdStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $cmdMap[$r['command_key']] = (bool)$r['is_enabled'];

$bstStmt = $pdo->prepare('SELECT * FROM leveling_boosters WHERE bot_id=? ORDER BY id ASC');
$bstStmt->execute([$botId]);
$boosters = $bstStmt->fetchAll(PDO::FETCH_ASSOC);

$usersCount = (int)$pdo->query("SELECT COUNT(*) FROM leveling_users WHERE bot_id=$botId")->fetchColumn();
$topStmt = $pdo->prepare('SELECT * FROM leveling_users WHERE bot_id=? ORDER BY total_xp DESC LIMIT 1');
$topStmt->execute([$botId]);
$topRow = $topStmt->fetch(PDO::FETCH_ASSOC);

$embedColor = lv_str($settings, 'embed_color', '#f45142');
$modEnabled = bh_mod_is_enabled($pdo, $botId, 'module:leveling');
?>


<div class="lv-page">

    <!-- Header -->
    <div class="lv-head">
        <div class="lv-kicker">Leveling</div>
        <h1 class="lv-title">Leveling</h1>
        <p class="lv-subtitle">XP & Level-System — Nutzer sammeln XP durch Nachrichten und Sprachkanäle.</p>
    </div>

    <?= bh_mod_render($modEnabled, $botId, 'module:leveling', 'Leveling', 'XP-System und alle Level-Commands für diesen Bot ein- oder ausschalten.') ?>
    <div id="bh-mod-body">

    <!-- Stats -->
    <div class="lv-stats">
        <div class="lv-stat">
            <div class="lv-stat__val"><?= number_format($usersCount) ?></div>
            <div class="lv-stat__lbl">Nutzer mit XP</div>
        </div>
        <div class="lv-stat">
            <div class="lv-stat__val"><?= lv_int($settings,'msg_xp_min',10) ?>&thinsp;–&thinsp;<?= lv_int($settings,'msg_xp_max',25) ?></div>
            <div class="lv-stat__lbl">XP pro Nachricht</div>
        </div>
        <div class="lv-stat">
            <div class="lv-stat__val"><?= lv_int($settings,'msg_cooldown',15) ?>s</div>
            <div class="lv-stat__lbl">Cooldown</div>
        </div>
        <div class="lv-stat">
            <div class="lv-stat__val"><?= count($boosters) ?></div>
            <div class="lv-stat__lbl">XP Boosters</div>
        </div>
    </div>

    <div id="lvAlert" style="display:none" class="bh-alert"></div>

    <!-- ══ General Settings ══ -->
    <div class="bh-card">
        <div class="bh-card-hdr lv-toggle-hdr" data-target="lv-general-body">
            <div class="lv-card__hdr-left">
                <div class="lv-card__kicker">Leveling</div>
                <div class="bh-card-title">General Settings</div>
            </div>
            <svg class="lv-chevron lv-chevron--open" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
            </svg>
        </div>

        <div class="lv-card__body" id="lv-general-body">

            <!-- Embed Color -->
            <div class="bh-field">
                <label class="bh-label">Embeds Color</label>
                <div class="bh-hint" style="margin-top:0;margin-bottom:8px;">Farbe für Embeds wie das Leaderboard und Rank-Karte.</div>
                <div class="lv-color-row">
                    <div class="lv-color-swatch">
                        <input type="color" id="lvColorPicker" value="<?= lv_h($embedColor) ?>">
                    </div>
                    <input type="text" id="lvColorText" class="bh-input lv-color-text" value="<?= lv_h($embedColor) ?>" maxlength="7">
                </div>
            </div>

            <!-- Max Level + XP per Level -->
            <div class="bh-field">
                <div class="lv-grid2">
                    <div>
                        <label class="bh-label">Max Level</label>
                        <input type="number" id="lv_max_level" class="bh-input" min="0" value="<?= lv_int($settings,'max_level',0) ?>">
                        <div class="bh-hint">0 = kein Limit</div>
                    </div>
                    <div>
                        <label class="bh-label">Additional XP per Level</label>
                        <input type="number" id="lv_xp_per_level" class="bh-input" min="1" value="<?= lv_int($settings,'xp_per_level',50) ?>">
                        <div class="bh-hint">XP-Anstieg pro Level (entspricht XP für Level 1)</div>
                    </div>
                </div>
            </div>

            <!-- Clear on Leave -->
            <div class="lv-feature">
                <div class="lv-feature__left">
                    <div class="lv-feature__title">Clear Leveling Data on Leave</div>
                    <div class="lv-feature__desc">Wenn ein Nutzer den Server verlässt, werden seine Leveling-Daten gelöscht.</div>
                </div>
                <div class="lv-feature__right">
                    <label class="bh-toggle">
                        <input class="bh-toggle-input" type="checkbox" id="lv_clear_on_leave" <?= lv_bool($settings,'clear_on_leave',true) ? 'checked' : '' ?>>
                        <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                    </label>
                </div>
            </div>

            <!-- Level-up message -->
            <div class="bh-field">
                <label class="bh-label">Send a message on level up</label>
                <select id="lv_levelup_message" class="bh-select">
                    <option value="disabled"        <?= lv_str($settings,'levelup_message','disabled')==='disabled'        ? 'selected' : '' ?>>Disabled</option>
                    <option value="current_channel" <?= lv_str($settings,'levelup_message')==='current_channel' ? 'selected' : '' ?>>Current Channel</option>
                    <option value="dm"              <?= lv_str($settings,'levelup_message')==='dm'              ? 'selected' : '' ?>>DM</option>
                </select>
            </div>

            <div class="lv-btn-row">
                <button type="button" class="bh-btn bh-btn--primary" id="lvSaveGeneral">Speichern</button>
                <span class="lv-save-msg" id="lvSaveGeneralMsg"></span>
            </div>
        </div>
    </div>

    <!-- ══ Messages XP ══ -->
    <div class="bh-card">
        <div class="bh-card-hdr lv-toggle-hdr" data-target="lv-msg-body">
            <div class="lv-card__hdr-left">
                <div class="lv-card__kicker">Leveling</div>
                <div class="bh-card-title">Messages XP Settings</div>
            </div>
            <svg class="lv-chevron lv-chevron--open" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
            </svg>
        </div>

        <div class="lv-card__body" id="lv-msg-body">
            <div class="bh-field">
                <div class="lv-grid2">
                    <div>
                        <label class="bh-label">Minimum XP per Message</label>
                        <input type="number" id="lv_msg_xp_min" class="bh-input" min="1" value="<?= lv_int($settings,'msg_xp_min',10) ?>">
                    </div>
                    <div>
                        <label class="bh-label">Maximum XP per Message</label>
                        <input type="number" id="lv_msg_xp_max" class="bh-input" min="1" value="<?= lv_int($settings,'msg_xp_max',25) ?>">
                    </div>
                </div>
            </div>
            <div class="bh-field">
                <label class="bh-label">Cooldown (Sekunden)</label>
                <input type="number" id="lv_msg_cooldown" class="bh-input" min="0" value="<?= lv_int($settings,'msg_cooldown',15) ?>">
                <div class="bh-hint">Nutzer erhalten innerhalb dieser Zeit keine weiteren XP.</div>
            </div>
            <div class="lv-btn-row">
                <button type="button" class="bh-btn bh-btn--primary" id="lvSaveMsg">Speichern</button>
                <span class="lv-save-msg" id="lvSaveMsgMsg"></span>
            </div>
        </div>
    </div>

    <!-- ══ Voice XP ══ -->
    <div class="bh-card">
        <div class="bh-card-hdr lv-toggle-hdr" data-target="lv-voice-body">
            <div class="lv-card__hdr-left">
                <div class="lv-card__kicker">Leveling</div>
                <div class="bh-card-title">Voice XP Settings</div>
            </div>
            <svg class="lv-chevron lv-chevron--open" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
            </svg>
        </div>

        <div class="lv-card__body" id="lv-voice-body">
            <div class="lv-feature">
                <div class="lv-feature__left">
                    <div class="lv-feature__title">Enable voice XP</div>
                    <div class="lv-feature__desc">Nutzer erhalten XP durch Teilnahme in Sprachkanälen.</div>
                </div>
                <div class="lv-feature__right">
                    <label class="bh-toggle">
                        <input class="bh-toggle-input" type="checkbox" id="lv_voice_xp_enabled" <?= lv_bool($settings,'voice_xp_enabled') ? 'checked' : '' ?>>
                        <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                    </label>
                </div>
            </div>
            <div class="bh-field">
                <label class="bh-label">XP per Minute</label>
                <input type="number" id="lv_voice_xp_per_minute" class="bh-input" min="1" value="<?= lv_int($settings,'voice_xp_per_minute',5) ?>">
                <div class="bh-hint">XP die jede Minute im Sprachkanal gutgeschrieben werden.</div>
            </div>
            <div class="lv-btn-row">
                <button type="button" class="bh-btn bh-btn--primary" id="lvSaveVoice">Speichern</button>
                <span class="lv-save-msg" id="lvSaveVoiceMsg"></span>
            </div>
        </div>
    </div>

    <!-- ══ XP Boosters ══ -->
    <div class="bh-card">
        <div class="bh-card-hdr lv-toggle-hdr" data-target="lv-boost-body">
            <div class="lv-card__hdr-left">
                <div class="lv-card__kicker">Leveling</div>
                <div class="bh-card-title">XP Boosters</div>
            </div>
            <svg class="lv-chevron lv-chevron--open" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
            </svg>
        </div>

        <div class="lv-card__body" id="lv-boost-body">
            <!-- Booster options -->
            <div class="lv-feature">
                <div class="lv-feature__left">
                    <div class="lv-feature__title">Sum XP Boosts</div>
                    <div class="lv-feature__desc">Alle geltenden Boosts werden addiert. Sonst wird nur der höchste Boost verwendet.</div>
                </div>
                <div class="lv-feature__right">
                    <label class="bh-toggle">
                        <input class="bh-toggle-input" type="checkbox" id="lv_sum_boosts" <?= lv_bool($settings,'sum_boosts') ? 'checked' : '' ?>>
                        <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                    </label>
                </div>
            </div>
            <div class="lv-feature">
                <div class="lv-feature__left">
                    <div class="lv-feature__title">Randomize Boosts</div>
                    <div class="lv-feature__desc">Der Boost-Wert variiert leicht (±1%) um etwas Zufälligkeit hinzuzufügen.</div>
                </div>
                <div class="lv-feature__right">
                    <label class="bh-toggle">
                        <input class="bh-toggle-input" type="checkbox" id="lv_randomize_boosts" <?= lv_bool($settings,'randomize_boosts',true) ? 'checked' : '' ?>>
                        <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                    </label>
                </div>
            </div>
            <div class="lv-btn-row" style="border-top:none;padding-top:4px;">
                <button type="button" class="bh-btn bh-btn--primary bh-btn bh-btn--sm" id="lvSaveBoostOpts">Optionen speichern</button>
                <span class="lv-save-msg" id="lvSaveBoostOptsMsg"></span>
            </div>

            <!-- Existing boosters -->
            <?php if ($boosters): ?>
            <div style="border-top:1px solid var(--bh-border,#2d3346);padding:12px 20px 4px;">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--bh-text-muted,#8b949e);margin-bottom:8px;">Aktive Boosters</div>
                <?php foreach ($boosters as $b): ?>
                <div class="lv-booster-row">
                    <span class="lv-booster-badge"><?= lv_h($b['booster_type']) ?></span>
                    <span class="lv-booster-id"><?= lv_h($b['target_id']) ?></span>
                    <span class="lv-booster-pct">+<?= (int)$b['percentage'] ?>%</span>
                    <button class="lv-booster-del" data-id="<?= (int)$b['id'] ?>">✕ Löschen</button>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Add booster -->
            <div style="border-top:1px solid var(--bh-border,#2d3346);padding:16px 20px;">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--bh-text-muted,#8b949e);margin-bottom:12px;">Booster hinzufügen</div>
                <div class="lv-grid2" style="margin-bottom:12px;">
                    <div>
                        <label class="bh-label">Typ</label>
                        <select id="lvBstType" class="bh-select">
                            <option value="role">Rollen-Boost</option>
                            <option value="channel">Kanal-Boost</option>
                        </select>
                    </div>
                    <div>
                        <label class="bh-label">Prozent</label>
                        <input type="number" id="lvBstPct" class="bh-input" min="1" value="10">
                    </div>
                </div>
                <div style="margin-bottom:12px;">
                    <label class="bh-label" id="lvBstTargetLbl">Rollen-ID</label>
                    <input type="text" id="lvBstTarget" class="bh-input" placeholder="Discord ID">
                    <div class="bh-hint">Rechtsklick auf Rolle/Kanal → ID kopieren (Entwickler-Modus erforderlich)</div>
                </div>
                <button type="button" class="bh-btn bh-btn--primary" id="lvAddBstBtn">Hinzufügen</button>
                <span class="lv-save-msg" id="lvBstMsg" style="margin-left:10px;"></span>
            </div>
        </div>
    </div>

    <!-- ══ Commands ══ -->
    <div class="bh-card">
        <div class="bh-card-hdr lv-toggle-hdr" data-target="lv-cmds-body">
            <div class="lv-card__hdr-left">
                <div class="lv-card__kicker">Module</div>
                <div class="bh-card-title">Commands</div>
            </div>
            <svg class="lv-chevron lv-chevron--open" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
            </svg>
        </div>

        <div class="lv-card__body" id="lv-cmds-body">
            <div class="lv-cmds">
                <?php
                $cmds = [
                    ['key'=>'rank',             'name'=>'/rank',                   'desc'=>'Get your or another user\'s experience progress'],
                    ['key'=>'level_leaderboard','name'=>'/level leaderboard',      'desc'=>'Check the leveling leaderboard of this server'],
                    ['key'=>'level_editxp',     'name'=>'/level edit-xp',          'desc'=>'Edit someone\'s experience or level'],
                    ['key'=>'level_reset',      'name'=>'/level reset-leaderboard','desc'=>'Reset this server\'s leveling leaderboard'],
                ];
                foreach ($cmds as $cmd):
                    $on = $cmdMap[$cmd['key']] ?? true;
                ?>
                <div class="lv-cmd">
                    <div>
                        <div class="bh-cmd-name"><?= lv_h($cmd['name']) ?></div>
                        <div class="bh-cmd-desc"><?= lv_h($cmd['desc']) ?></div>
                    </div>
                    <label class="bh-toggle">
                        <input type="checkbox" class="lvCmdToggle bh-toggle-input" data-key="<?= lv_h($cmd['key']) ?>" <?= $on ? 'checked' : '' ?>>
                        <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
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

    function showMsg(el, ok, text) {
        el.textContent = text;
        el.className   = 'lv-save-msg lv-save-msg--' + (ok ? 'ok' : 'err');
        setTimeout(() => { el.textContent = ''; el.className = 'lv-save-msg'; }, 3000);
    }

    // Collapsible cards
    document.querySelectorAll('.lv-toggle-hdr').forEach(hdr => {
        hdr.addEventListener('click', () => {
            const body    = document.getElementById(hdr.dataset.target);
            const chevron = hdr.querySelector('.lv-chevron');
            if (body) body.classList.toggle('lv-collapsed');
            if (chevron) chevron.classList.toggle('lv-chevron--open');
        });
    });

    // Color picker sync
    const picker = document.getElementById('lvColorPicker');
    const text   = document.getElementById('lvColorText');
    picker.addEventListener('input', () => text.value = picker.value);
    text.addEventListener('change', () => {
        if (/^#[0-9a-fA-F]{6}$/.test(text.value)) picker.value = text.value;
    });

    // ── Save General ──
    document.getElementById('lvSaveGeneral').addEventListener('click', async () => {
        const msg = document.getElementById('lvSaveGeneralMsg');
        const color = /^#[0-9a-fA-F]{6}$/.test(text.value) ? text.value : picker.value;
        const res = await post({
            lv_action:        'save_settings',
            embed_color:      color,
            max_level:        document.getElementById('lv_max_level').value,
            xp_per_level:     document.getElementById('lv_xp_per_level').value,
            clear_on_leave:   document.getElementById('lv_clear_on_leave').checked ? '1' : '0',
            levelup_message:  document.getElementById('lv_levelup_message').value,
            // carry forward other fields with current values so they aren't reset
            msg_xp_min:       document.getElementById('lv_msg_xp_min').value,
            msg_xp_max:       document.getElementById('lv_msg_xp_max').value,
            msg_cooldown:     document.getElementById('lv_msg_cooldown').value,
            voice_xp_enabled: document.getElementById('lv_voice_xp_enabled').checked ? '1' : '0',
            voice_xp_per_minute: document.getElementById('lv_voice_xp_per_minute').value,
            sum_boosts:       document.getElementById('lv_sum_boosts').checked ? '1' : '0',
            randomize_boosts: document.getElementById('lv_randomize_boosts').checked ? '1' : '0',
        });
        showMsg(msg, res.ok, res.ok ? '✓ Gespeichert' : '✕ ' + (res.error||'Fehler'));
    });

    // ── Save Messages XP ──
    document.getElementById('lvSaveMsg').addEventListener('click', async () => {
        const msg = document.getElementById('lvSaveMsgMsg');
        const res = await post({
            lv_action:        'save_settings',
            embed_color:      picker.value,
            max_level:        document.getElementById('lv_max_level').value,
            xp_per_level:     document.getElementById('lv_xp_per_level').value,
            clear_on_leave:   document.getElementById('lv_clear_on_leave').checked ? '1' : '0',
            levelup_message:  document.getElementById('lv_levelup_message').value,
            msg_xp_min:       document.getElementById('lv_msg_xp_min').value,
            msg_xp_max:       document.getElementById('lv_msg_xp_max').value,
            msg_cooldown:     document.getElementById('lv_msg_cooldown').value,
            voice_xp_enabled: document.getElementById('lv_voice_xp_enabled').checked ? '1' : '0',
            voice_xp_per_minute: document.getElementById('lv_voice_xp_per_minute').value,
            sum_boosts:       document.getElementById('lv_sum_boosts').checked ? '1' : '0',
            randomize_boosts: document.getElementById('lv_randomize_boosts').checked ? '1' : '0',
        });
        showMsg(msg, res.ok, res.ok ? '✓ Gespeichert' : '✕ ' + (res.error||'Fehler'));
    });

    // ── Save Voice XP ──
    document.getElementById('lvSaveVoice').addEventListener('click', async () => {
        const msg = document.getElementById('lvSaveVoiceMsg');
        const res = await post({
            lv_action:        'save_settings',
            embed_color:      picker.value,
            max_level:        document.getElementById('lv_max_level').value,
            xp_per_level:     document.getElementById('lv_xp_per_level').value,
            clear_on_leave:   document.getElementById('lv_clear_on_leave').checked ? '1' : '0',
            levelup_message:  document.getElementById('lv_levelup_message').value,
            msg_xp_min:       document.getElementById('lv_msg_xp_min').value,
            msg_xp_max:       document.getElementById('lv_msg_xp_max').value,
            msg_cooldown:     document.getElementById('lv_msg_cooldown').value,
            voice_xp_enabled: document.getElementById('lv_voice_xp_enabled').checked ? '1' : '0',
            voice_xp_per_minute: document.getElementById('lv_voice_xp_per_minute').value,
            sum_boosts:       document.getElementById('lv_sum_boosts').checked ? '1' : '0',
            randomize_boosts: document.getElementById('lv_randomize_boosts').checked ? '1' : '0',
        });
        showMsg(msg, res.ok, res.ok ? '✓ Gespeichert' : '✕ ' + (res.error||'Fehler'));
    });

    // ── Save Booster Options ──
    document.getElementById('lvSaveBoostOpts').addEventListener('click', async () => {
        const msg = document.getElementById('lvSaveBoostOptsMsg');
        const res = await post({
            lv_action:        'save_settings',
            embed_color:      picker.value,
            max_level:        document.getElementById('lv_max_level').value,
            xp_per_level:     document.getElementById('lv_xp_per_level').value,
            clear_on_leave:   document.getElementById('lv_clear_on_leave').checked ? '1' : '0',
            levelup_message:  document.getElementById('lv_levelup_message').value,
            msg_xp_min:       document.getElementById('lv_msg_xp_min').value,
            msg_xp_max:       document.getElementById('lv_msg_xp_max').value,
            msg_cooldown:     document.getElementById('lv_msg_cooldown').value,
            voice_xp_enabled: document.getElementById('lv_voice_xp_enabled').checked ? '1' : '0',
            voice_xp_per_minute: document.getElementById('lv_voice_xp_per_minute').value,
            sum_boosts:       document.getElementById('lv_sum_boosts').checked ? '1' : '0',
            randomize_boosts: document.getElementById('lv_randomize_boosts').checked ? '1' : '0',
        });
        showMsg(msg, res.ok, res.ok ? '✓ Gespeichert' : '✕ ' + (res.error||'Fehler'));
    });

    // ── Booster type label ──
    document.getElementById('lvBstType').addEventListener('change', function () {
        document.getElementById('lvBstTargetLbl').textContent =
            this.value === 'channel' ? 'Kanal-ID' : 'Rollen-ID';
    });

    // ── Add booster ──
    document.getElementById('lvAddBstBtn').addEventListener('click', async () => {
        const msg = document.getElementById('lvBstMsg');
        const res = await post({
            lv_action:    'add_booster',
            booster_type: document.getElementById('lvBstType').value,
            target_id:    document.getElementById('lvBstTarget').value.trim(),
            percentage:   document.getElementById('lvBstPct').value,
        });
        if (res.ok) { location.reload(); }
        else { showMsg(msg, false, '✕ ' + (res.error||'Fehler')); }
    });

    // ── Delete booster ──
    document.querySelectorAll('.lv-booster-del').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('Booster wirklich löschen?')) return;
            const res = await post({ lv_action: 'delete_booster', booster_id: btn.dataset.id });
            if (res.ok) location.reload();
        });
    });

    // ── Command toggles ──
    document.querySelectorAll('.lvCmdToggle').forEach(toggle => {
        toggle.addEventListener('change', () => {
            post({ lv_action: 'toggle_command', command_key: toggle.dataset.key, enabled: toggle.checked ? '1' : '0' });
        });
    });
})();
</script>
