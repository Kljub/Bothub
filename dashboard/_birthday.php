<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/functions/db_functions/birthday.php';
require_once dirname(__DIR__) . '/functions/db_functions/commands.php';
require_once dirname(__DIR__) . '/functions/module_toggle.php';

$pdo   = bh_get_pdo();
$botId = isset($currentBotId) && $currentBotId > 0 ? $currentBotId : (int)($_GET['bot_id'] ?? 0);

if ($botId <= 0) { ?>
<div style="color:#f87171;padding:20px">Kein Bot ausgewählt.</div>
<?php return; }

try { bh_birthday_ensure_tables($pdo); } catch (Throwable) {}

// ── AJAX POST ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bh_mod_handle_ajax($pdo, $botId);
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $raw    = (string)file_get_contents('php://input');
    $data   = json_decode($raw, true) ?? [];
    $action = (string)($data['action'] ?? '');
    try {
        if ($action === 'save') {
            $guild   = trim((string)($data['guild_id'] ?? ''));
            if ($guild === '') { echo json_encode(['ok' => false, 'error' => 'Kein Server ausgewählt.']); exit; }
            $channel = (string)($data['announce_channel_id'] ?? '');
            $message = mb_substr(trim((string)($data['announce_message'] ?? '')), 0, 512);
            $enabled = (int)(bool)($data['is_enabled'] ?? true);
            if ($message === '') $message = 'Alles Gute zum Geburtstag {user}! 🎂🎉';
            bh_birthday_save_settings($pdo, $botId, $guild, $channel, $message, $enabled);
            bhcmd_set_module_enabled($pdo, $botId, 'birthday-add',    (int)(bool)($data['cmd_birthday_add']    ?? 1));
            bhcmd_set_module_enabled($pdo, $botId, 'birthday-delete', (int)(bool)($data['cmd_birthday_delete'] ?? 1));
            echo json_encode(['ok' => true]); exit;
        }
        if ($action === 'delete_birthday') {
            $id = (int)($data['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'Ungültige ID.']); exit; }
            bh_birthday_delete($pdo, $botId, $id);
            echo json_encode(['ok' => true]); exit;
        }
        echo json_encode(['ok' => false, 'error' => 'Unbekannte Aktion.']);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Load guilds ───────────────────────────────────────────────────────────────
$guildId = trim((string)($_GET['guild_id'] ?? ''));
$guilds  = [];
try {
    $gs = $pdo->prepare('SELECT guild_id, guild_name FROM bot_guilds WHERE bot_id = ? ORDER BY guild_name ASC');
    $gs->execute([$botId]);
    $guilds = $gs->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) {}
if ($guildId === '' && count($guilds) > 0) {
    $guildId = (string)$guilds[0]['guild_id'];
}

// ── Load settings & birthdays ─────────────────────────────────────────────────
$birthdays = [];
$settings  = [];
if ($guildId !== '') {
    try { $birthdays = bh_birthday_list($pdo, $botId, $guildId); } catch (Throwable) {}
    try { $settings  = bh_birthday_get_settings($pdo, $botId, $guildId); } catch (Throwable) {}
}

$announceChannelId   = (string)($settings['announce_channel'] ?? '');
$announceMessage     = (string)($settings['announce_message'] ?? 'Alles Gute zum Geburtstag {user}! 🎂🎉');
$isEnabled           = (int)($settings['is_enabled'] ?? 1);

// ── Command states ────────────────────────────────────────────────────────────
try { $cmdAdd    = bhcmd_is_enabled($pdo, $botId, 'birthday-add'); }    catch (Throwable) { $cmdAdd    = 1; }
try { $cmdDelete = bhcmd_is_enabled($pdo, $botId, 'birthday-delete'); } catch (Throwable) { $cmdDelete = 1; }

$monthNames = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
$baseUrl    = '/dashboard?view=birthday&bot_id=' . $botId;
$esc        = fn(string $v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// Channel as tag item for JS
$channelItems = $announceChannelId !== ''
    ? [['id' => $announceChannelId, 'name' => $announceChannelId]]
    : [];
$modEnabled = bh_mod_is_enabled($pdo, $botId, 'module:birthday');
?>

<?= bh_mod_render($modEnabled, $botId, 'module:birthday', 'Birthday', 'Geburtstags-Funktion und alle Birthday-Commands für diesen Bot ein- oder ausschalten.') ?>
<div id="bh-mod-body">
<div class="bday-wrap">

    <div id="bday-flash" class="bday-flash"></div>

    <?php if (count($guilds) === 0): ?>
        <div class="bday-flash bday-flash--err" style="display:block">
            Der Bot ist noch in keinem Server. Lade ihn zunächst auf einen Server ein.
        </div>
    <?php else: ?>

    <!-- Guild Selector -->
    <div class="mb-2">
        <div class="bday-section-label">Birthday</div>
        <div class="bday-section-title">Server auswählen</div>
    </div>
    <div class="bday-card">
        <div class="bday-field" style="border-bottom:none">
            <div>
                <div class="bday-field-label">Server</div>
                <div class="bday-field-desc">Wähle den Server für den du die Einstellungen bearbeiten möchtest.</div>
            </div>
            <div class="bday-field-right">
                <select id="bday-guild-select"
                    onchange="window.location.href = '<?= $esc($baseUrl) ?>&guild_id=' + this.value"
                    style="width:100%;background:#0d1320;border:1px solid #2e3850;border-radius:7px;padding:8px 12px;font-size:13px;color:#e2e8f0;outline:none;cursor:pointer">
                    <?php foreach ($guilds as $g): ?>
                        <option value="<?= $esc((string)$g['guild_id']) ?>" <?= $g['guild_id'] === $guildId ? 'selected' : '' ?>>
                            <?= $esc((string)($g['guild_name'] ?: $g['guild_id'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <?php if ($guildId !== ''): ?>

    <!-- Settings -->
    <div class="mb-2 mt-2">
        <div class="bday-section-label">Birthday</div>
        <div class="bday-section-title">Einstellungen</div>
    </div>

    <div class="bday-card">
        <div class="bday-card-title">Geburtstags-Ankündigung</div>

        <!-- Enabled toggle -->
        <div class="bday-field">
            <div>
                <div class="bday-field-label">Ankündigungen aktiviert</div>
                <div class="bday-field-desc">Bot sendet automatisch um Mitternacht (UTC) eine Glückwunschnachricht für Nutzer die heute Geburtstag haben.</div>
            </div>
            <label class="toggle" style="flex-shrink:0">
                <input type="checkbox" id="bday-enabled" <?= $isEnabled ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
            </label>
        </div>

        <!-- Channel picker -->
        <div class="bday-field">
            <div>
                <div class="bday-field-label">Ankündigungs-Kanal</div>
                <div class="bday-field-desc">Kanal in dem der Bot Geburtstage ankündigt.</div>
            </div>
            <div class="bday-tags-box" id="bday-channel-box">
                <button type="button" class="bday-add-btn" id="bday-channel-add-btn" title="Kanal wählen"
                    <?= $announceChannelId !== '' ? 'style="display:none"' : '' ?>>+</button>
            </div>
        </div>

        <!-- Message -->
        <div class="bday-field">
            <div>
                <div class="bday-field-label">Glückwunschnachricht</div>
                <div class="bday-field-desc">Platzhalter: <code style="color:#a5b4fc;background:rgba(99,102,241,.15);padding:1px 5px;border-radius:4px">{user}</code> wird durch die Discord-Erwähnung ersetzt.</div>
            </div>
            <div class="bday-field-right">
                <input type="text" id="bday-message" class="bday-input" maxlength="512"
                    value="<?= $esc($announceMessage) ?>">
            </div>
        </div>
    </div>

    <!-- Birthday List -->
    <div class="mb-2 mt-2">
        <div class="bday-section-label">Birthday</div>
        <div class="bday-section-title">Eingetragene Geburtstage (<?= count($birthdays) ?>)</div>
    </div>

    <div class="bday-card">
        <?php if (count($birthdays) === 0): ?>
            <div class="bday-empty">
                <div class="bday-empty-icon">🎂</div>
                Noch keine Geburtstage eingetragen.<br>
                Nutzer können <code style="color:#a5b4fc;background:rgba(99,102,241,.15);padding:1px 5px;border-radius:4px">/birthday add</code> im Discord verwenden.
            </div>
        <?php else: ?>
            <table class="bday-table" id="bday-list">
                <thead>
                    <tr><th>Datum</th><th>Nutzer</th><th>Discord ID</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($birthdays as $bday):
                        $month = (int)$bday['birth_month'];
                        $day   = (int)$bday['birth_day'];
                        $label = $day . '. ' . ($monthNames[$month - 1] ?? '?');
                    ?>
                    <tr data-id="<?= (int)$bday['id'] ?>">
                        <td><span class="bday-date-badge"><?= $esc($label) ?></span></td>
                        <td style="font-weight:600"><?= $esc((string)($bday['username'] ?: 'Unbekannt')) ?></td>
                        <td style="font-size:11px;font-family:monospace;color:#4f5f80"><?= $esc((string)$bday['user_id']) ?></td>
                        <td style="text-align:right">
                            <button type="button" class="bday-del-btn" data-id="<?= (int)$bday['id'] ?>">Löschen</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php endif; // $guildId !== '' ?>
    <?php endif; // guilds exist ?>

    <!-- Commands -->
    <div class="mb-2 mt-2">
        <div class="bday-section-label">Module</div>
        <div class="bday-section-title">Commands</div>
    </div>

    <div class="bday-cmd-grid">
        <div class="bday-cmd-card">
            <div>
                <div class="bday-cmd-name">/birthday add</div>
                <div class="bday-cmd-desc">Geburtstag eintragen oder aktualisieren (Monat + Tag).</div>
            </div>
            <label class="toggle" style="flex-shrink:0">
                <input type="checkbox" id="bday-cmd-add" <?= $cmdAdd ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
            </label>
        </div>
        <div class="bday-cmd-card">
            <div>
                <div class="bday-cmd-name">/birthday delete</div>
                <div class="bday-cmd-desc">Eigenen Geburtstag von diesem Server entfernen.</div>
            </div>
            <label class="toggle" style="flex-shrink:0">
                <input type="checkbox" id="bday-cmd-delete" <?= $cmdDelete ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
            </label>
        </div>
    </div>

    <!-- Save -->
    <div class="bday-save-bar">
        <button type="button" class="bday-save-btn" id="bday-save-btn">Save</button>
    </div>

</div>
</div><!-- /bh-mod-body -->

<script>
(function () {
    'use strict';

    var BOT_ID   = <?= (int)$botId ?>;
    var GUILD_ID = <?= json_encode($guildId) ?>;
    var BASE_URL = window.location.href;

    // Channel state (single channel, stored as 1-item array for initTagBox)
    var channelState = <?= json_encode($channelItems, JSON_UNESCAPED_UNICODE) ?>;

    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Tag box (reused from polls pattern) ───────────────────────────────────
    function renderTagBox(boxId, list, addBtnId) {
        var box    = document.getElementById(boxId);
        var addBtn = document.getElementById(addBtnId);
        if (!box) return;
        Array.from(box.children).forEach(function (ch) { if (ch !== addBtn) ch.remove(); });
        list.forEach(function (item, idx) {
            var tag = document.createElement('span');
            tag.className = 'bday-tag';
            tag.innerHTML = esc(item.name || item.id)
                + '<button type="button" class="bday-tag-rm" title="Entfernen">×</button>';
            tag.querySelector('.bday-tag-rm').addEventListener('click', function () {
                list.splice(idx, 1);
                renderTagBox(boxId, list, addBtnId);
                if (addBtn) addBtn.style.display = '';
            });
            box.insertBefore(tag, addBtn);
        });
        // Hide + button when a channel is already selected (single channel)
        if (addBtn) addBtn.style.display = list.length > 0 ? 'none' : '';
    }

    function initTagBox(boxId, list, addBtnId, type) {
        renderTagBox(boxId, list, addBtnId);
        var addBtn = document.getElementById(addBtnId);
        if (!addBtn) return;
        addBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (typeof BhPerm === 'undefined') return;
            BhPerm.openPicker(addBtn, BOT_ID, type, list, function (item) {
                // Single-channel mode: replace existing
                list.length = 0;
                list.push({ id: item.id, name: item.name });
                renderTagBox(boxId, list, addBtnId);
            });
        });
    }

    initTagBox('bday-channel-box', channelState, 'bday-channel-add-btn', 'channels');

    // ── Delete birthday rows ──────────────────────────────────────────────────
    document.querySelectorAll('.bday-del-btn').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            if (!confirm('Geburtstag wirklich löschen?')) return;
            var id  = parseInt(btn.dataset.id, 10);
            var row = btn.closest('tr');
            try {
                var res = await fetch(BASE_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete_birthday', id: id })
                });
                var d = await res.json();
                if (d.ok && row) row.remove();
                else if (!d.ok) showFlash('err', d.error || 'Fehler.');
            } catch (e) { showFlash('err', 'Netzwerkfehler.'); }
        });
    });

    // ── Save ──────────────────────────────────────────────────────────────────
    document.getElementById('bday-save-btn').addEventListener('click', async function () {
        var btn = this;
        btn.disabled = true;
        btn.textContent = '…';

        var payload = {
            action:               'save',
            guild_id:              GUILD_ID,
            announce_channel_id:   channelState.length > 0 ? channelState[0].id : '',
            announce_message:      (document.getElementById('bday-message')  || {}).value || '',
            is_enabled:            document.getElementById('bday-enabled')?.checked  ? 1 : 0,
            cmd_birthday_add:      document.getElementById('bday-cmd-add')?.checked    ? 1 : 0,
            cmd_birthday_delete:   document.getElementById('bday-cmd-delete')?.checked ? 1 : 0,
        };

        try {
            var res  = await fetch(BASE_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            var data = await res.json();
            showFlash(data.ok ? 'ok' : 'err', data.ok ? 'Einstellungen gespeichert.' : (data.error || 'Fehler beim Speichern.'));
        } catch (_) {
            showFlash('err', 'Netzwerkfehler.');
        }

        btn.disabled = false;
        btn.textContent = 'Save';
    });

    function showFlash(type, msg) {
        var el = document.getElementById('bday-flash');
        if (!el) return;
        el.className = 'bday-flash bday-flash--' + type;
        el.textContent = msg;
        el.style.display = 'block';
        setTimeout(function () { el.style.display = 'none'; }, 3500);
    }

}());
</script>
