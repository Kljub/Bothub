<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/functions/db_functions/timed_messages.php';

$pdo   = bh_get_pdo();
$botId = isset($currentBotId) && $currentBotId > 0 ? $currentBotId : (int)($_GET['bot_id'] ?? 0);

if ($botId <= 0) { ?>
<div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl p-5">
    <div class="text-sm text-rose-600 dark:text-rose-400">Bot nicht gefunden.</div>
</div>
<?php return; }

// ── Auto-migrate ──────────────────────────────────────────────────────────
try { bhtm_ensure_tables($pdo); } catch (Throwable) {}

// ── Handle AJAX ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    $raw    = (string)file_get_contents('php://input');
    $data   = json_decode($raw, true);
    $action = (string)($data['action'] ?? '');
    header('Content-Type: application/json; charset=utf-8');

    // SECURITY: IDOR Check
    $ownerCheck = $pdo->prepare('SELECT id FROM bot_instances WHERE id = :id AND owner_user_id = :uid LIMIT 1');
    $ownerCheck->execute([':id' => $botId, ':uid' => (int)$_SESSION['user_id']]);
    if (!$ownerCheck->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'Nicht autorisiert.']); exit;
    }

    if ($action === 'save_settings') {
        $evtHandler = (isset($data['evt_handler']) && $data['evt_handler']) ? 1 : 0;
        try {
            bhtm_save_settings($pdo, $botId, $evtHandler);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'add') {
        $name      = mb_substr(trim((string)($data['name']       ?? '')), 0, 100);
        $channelId = trim((string)($data['channel_id'] ?? ''));
        $days      = max(0, (int)($data['interval_days']    ?? 0));
        $hours     = max(0, min(23, (int)($data['interval_hours']   ?? 1)));
        $minutes   = in_array((int)($data['interval_minutes'] ?? 0), [0,5,10,15,20,25,30,35,40,45,50,55], true)
                        ? (int)$data['interval_minutes'] : 0;
        $isEmbed   = (isset($data['is_embed']) && $data['is_embed']) ? 1 : 0;
        $plainText = trim((string)($data['plain_text'] ?? ''));
        $author    = mb_substr(trim((string)($data['embed_author']    ?? '')), 0, 256);
        $thumb     = mb_substr(trim((string)($data['embed_thumbnail'] ?? '')), 0, 512);
        $title     = mb_substr(trim((string)($data['embed_title']     ?? '')), 0, 256);
        $body      = trim((string)($data['embed_body'] ?? ''));
        $image     = mb_substr(trim((string)($data['embed_image'] ?? '')), 0, 512);
        $color     = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($data['embed_color'] ?? ''))
                        ? (string)$data['embed_color'] : '#ef4444';
        $embedUrl  = mb_substr(trim((string)($data['embed_url'] ?? '')), 0, 512);
        $blockStk  = (isset($data['block_stacked']) && $data['block_stacked']) ? 1 : 0;

        if ($name === '') {
            echo json_encode(['ok' => false, 'error' => 'Name is required.']);
            exit;
        }
        if ($channelId === '') {
            echo json_encode(['ok' => false, 'error' => 'Channel ID is required.']);
            exit;
        }
        $totalSec = ($days * 86400) + ($hours * 3600) + ($minutes * 60);
        if ($totalSec < 300) {
            echo json_encode(['ok' => false, 'error' => 'Interval must be at least 5 minutes.']);
            exit;
        }

        // next_send_at = now + interval (UTC, matching Node.js reschedule)
        $nextSend = gmdate('Y-m-d H:i:s', time() + $totalSec);

        try {
            $newId = bhtm_add($pdo, $botId, [
                'name' => $name, 'channel_id' => $channelId,
                'days' => $days, 'hours' => $hours, 'minutes' => $minutes,
                'is_embed' => $isEmbed, 'plain_text' => $plainText,
                'author' => $author, 'thumb' => $thumb, 'title' => $title,
                'body' => $body, 'image' => $image, 'color' => $color,
                'embed_url' => $embedUrl, 'block_stacked' => $blockStk,
                'next_send_at' => $nextSend,
            ]);
            echo json_encode(['ok' => true, 'id' => $newId]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid ID.']); exit; }
        try {
            bhtm_delete($pdo, $botId, $id);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'toggle') {
        $id       = (int)($data['id']     ?? 0);
        $isActive = (isset($data['is_active']) && $data['is_active']) ? 1 : 0;
        if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid ID.']); exit; }
        try {
            bhtm_toggle($pdo, $botId, $id, $isActive);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

// ── Load settings ─────────────────────────────────────────────────────────
$settings = [];
try { $settings = bhtm_load_settings($pdo, $botId); } catch (Throwable) {}

$evtHandler = (int)($settings['evt_handler'] ?? 1);

// ── Load existing timed messages ──────────────────────────────────────────
$timedMsgs = [];
try { $timedMsgs = bhtm_list($pdo, $botId); } catch (Throwable) {}

$esc = fn(string $v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>

<div class="bh-section-label">TIMED MESSAGES</div>
<div class="bh-section-title">New Timed Message</div>

<div id="bh-alert" style="display:none"></div>

<!-- ── Create form ─────────────────────────────────────────────────────── -->
<div class="bh-card">
    <div class="bh-card-title">Create Timed Message</div>
    <div class="bh-card-desc">
        Create and customise a new timed message with a scheduled interval time.
        The message you build will be delivered to a selected channel, with optional reactions.
    </div>

    <!-- Unique Name -->
    <div class="bh-field">
        <div class="bh-label">Timed Message Name</div>
        <div class="bh-hint">Create a <span style="color:#ef4444;font-weight:700">unique</span> identifier name for each one of your timed events. This name will be used for error messages and storing your intervals.</div>
        <input type="text" id="tm-name" class="bh-input" placeholder="A name is required for your timed message to operate!">
    </div>

    <!-- Channel -->
    <div class="bh-field">
        <div class="bh-label">Channel Selection</div>
        <div class="bh-hint">Choose any <span style="color:#ef4444;font-weight:700">text</span> or <span style="color:#ef4444;font-weight:700">announcement</span> channel to send your timed message to.</div>
        <input type="hidden" id="tm-channel-val" value="">
        <div class="it-picker-row" id="tm-channel-box">
            <button type="button" class="it-picker-add" id="tm-channel-btn">+</button>
        </div>
    </div>

    <!-- Interval Days -->
    <div class="bh-field">
        <div class="bh-label">Time Interval - Days</div>
        <div class="bh-hint">Choose the amount of days of a delay you want to have between timed messages that are sent.</div>
        <input type="number" id="tm-days" class="bh-input" value="0" min="0" max="365">
    </div>

    <!-- Interval Hours -->
    <div class="bh-field">
        <div class="bh-label">Time Interval - Hours</div>
        <div class="bh-hint">Choose the amount of hours of a delay you want to have between timed messages that are sent.</div>
        <input type="number" id="tm-hours" class="bh-input" value="1" min="0" max="23">
    </div>

    <!-- Interval Minutes -->
    <div class="bh-field">
        <div class="bh-label">Time Interval - Minutes</div>
        <div class="bh-hint">Choose the amount of minutes of a delay you want to have between timed messages that are sent. You are only able to specify a timeframe in 5 minute steps.</div>
        <select id="tm-minutes" class="bh-select" style="max-width:200px">
            <?php foreach ([0,5,10,15,20,25,30,35,40,45,50,55] as $m): ?>
            <option value="<?= $m ?>"><?= $m ?> Minutes</option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Message Structure -->
    <div class="bh-field">
        <div class="bh-label">Timed Message Structure</div>
        <div class="bh-hint">Set up a custom embed or plain text message, that will be sent based off your interval set up above.</div>

        <div class="bh-toggle-row">
            <span class="bh-toggle-row__title">Message</span>
            <div style="display:flex;align-items:center;gap:8px">
                <span style="font-size:12px;color:#4f5f80">Embed</span>
                <label class="bh-toggle">
                    <input class="bh-toggle-input" type="checkbox" id="tm-is-embed" checked>
                    <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                </label>
            </div>
        </div>

        <!-- Embed editor -->
        <div id="tm-embed-panel">
            <div class="tm-embed-panel">
                <div class="tm-embed-inner">
                    <div id="tm-embed-stripe" class="tm-embed-stripe" title="Click to change color" style="background:#ef4444"></div>
                    <div class="tm-embed-body">
                        <!-- Author + Thumbnail -->
                        <div class="tm-embed-row">
                            <div>
                                <div class="bh-embed-label">Author</div>
                                <input type="text" id="tm-embed-author" class="bh-embed-input" placeholder="Author name">
                            </div>
                            <div>
                                <div class="bh-embed-label">Thumbnail URL</div>
                                <input type="text" id="tm-embed-thumb" class="bh-embed-input" placeholder="https://...">
                            </div>
                        </div>

                        <!-- Title -->
                        <div>
                            <div class="bh-embed-label">Title</div>
                            <input type="text" id="tm-embed-title" class="bh-embed-input" placeholder="Example Timed Message" value="Example Timed Message">
                        </div>

                        <!-- Body -->
                        <div>
                            <div class="bh-embed-label">Description</div>
                            <textarea id="tm-embed-body" class="bh-embed-textarea" placeholder="This is a demo of how your timed message will look like! Feel free to customize it to your own needs!">This is a demo of how your timed message will look like! Feel free to customize it to your own needs!</textarea>
                        </div>

                        <!-- Image URL -->
                        <div>
                            <div class="bh-embed-label">Image URL</div>
                            <input type="text" id="tm-embed-image" class="bh-embed-input" placeholder="https://...">
                        </div>

                        <!-- Footer / Color / Embed URL -->
                        <div class="tm-embed-footer-row">
                            <div>
                                <div class="bh-embed-label">Color</div>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <span id="tm-color-swatch" class="tm-color-btn" style="background:#ef4444" onclick="document.getElementById('tm-color-picker').click()"></span>
                                    <input type="color" id="tm-color-picker" value="#ef4444" style="display:none">
                                    <input type="text" id="tm-color-hex" class="bh-embed-input" value="#ef4444" placeholder="#ef4444" style="width:90px;font-family:monospace">
                                </div>
                            </div>
                            <div>
                                <div class="bh-embed-label">Embed URL</div>
                                <input type="text" id="tm-embed-url" class="bh-embed-input" placeholder="https://...">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Plain text panel -->
        <div id="tm-plain-panel" style="display:none">
            <textarea id="tm-plain-text" class="bh-textarea" placeholder="Your timed message text..."></textarea>
        </div>
    </div>

    <!-- Block Stacked -->
    <div class="bh-toggle-row">
        <div class="bh-toggle-row__info">
            <div class="bh-toggle-row__title">Block Stacked Messages</div>
            <div class="bh-toggle-row__desc">If the previous message in the channel was sent by your bot when it would send a timed message, it will delay sending the message until the next execution.</div>
        </div>
        <label class="bh-toggle" style="margin-left:16px">
            <input class="bh-toggle-input" type="checkbox" id="tm-block-stacked">
            <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
        </label>
    </div>

    <button class="bh-btn bh-btn--primary" id="bh-btn bh-btn--primary" onclick="tmAdd()">Add</button>
</div>

<!-- ── Existing messages ────────────────────────────────────────────────── -->
<?php if (!empty($timedMsgs)): ?>
<div id="bh-section-label" class="bh-section-label" style="margin-top:28px">EXISTING TIMED MESSAGES</div>
<div id="tm-list">
    <?php foreach ($timedMsgs as $tm): ?>
    <?php
        $intervalParts = [];
        if ((int)$tm['interval_days']    > 0) $intervalParts[] = $tm['interval_days']    . 'd';
        if ((int)$tm['interval_hours']   > 0) $intervalParts[] = $tm['interval_hours']   . 'h';
        if ((int)$tm['interval_minutes'] > 0) $intervalParts[] = $tm['interval_minutes'] . 'm';
        $intervalStr = $intervalParts ? implode(' ', $intervalParts) : '1h';
    ?>
    <div class="bh-list-card" id="tm-row-<?= (int)$tm['id'] ?>">
        <div>
            <div class="bh-list-name"><?= $esc((string)$tm['name']) ?></div>
            <div class="bh-list-meta">
                Channel: <code style="color:#a5b4fc"><?= $esc((string)$tm['channel_id']) ?></code>
                &nbsp;·&nbsp; Interval: <strong><?= $esc($intervalStr) ?></strong>
                &nbsp;·&nbsp; <?= (int)$tm['is_embed'] ? 'Embed' : 'Plain text' ?>
                <?php if (!(int)$tm['is_active']): ?>
                    &nbsp;·&nbsp; <span style="color:#f87171">Paused</span>
                <?php endif; ?>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:8px">
            <label class="bh-toggle" title="Enable / Disable">
                <input class="bh-toggle-input" type="checkbox" <?= (int)$tm['is_active'] ? 'checked' : '' ?>
                    onchange="tmToggle(<?= (int)$tm['id'] ?>, this.checked)">
                <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
            </label>
            <button class="bh-btn bh-btn--danger bh-btn--sm" onclick="tmDelete(<?= (int)$tm['id'] ?>)">Delete</button>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div id="tm-list"></div>
<?php endif; ?>

<!-- ── Module: Commands ────────────────────────────────────────────────── -->
<div style="margin-top:32px">
    <div class="bh-section-label">MODULE</div>
    <div class="tm-module-title">Commands</div>
    <div class="bh-cmd-grid">
        <div class="bh-cmd-card">
            <div>
                <div class="bh-cmd-name">Add Command</div>
                <div class="bh-cmd-desc">This command will have access to all the variables and settings of this module.</div>
            </div>
            <button class="bh-btn bh-btn--primary">Add</button>
        </div>
    </div>
</div>

<!-- ── Module: Events ─────────────────────────────────────────────────── -->
<div style="margin-top:28px;margin-bottom:32px">
    <div class="bh-section-label">MODULE</div>
    <div class="tm-module-title">Events</div>
    <div class="bh-cmd-grid">
        <div class="bh-cmd-card">
            <div>
                <div class="bh-cmd-name">Timed Messages Handler</div>
                <div class="bh-cmd-desc">When a timed event is executed</div>
            </div>
            <label class="bh-toggle" style="margin-left:16px">
                <input class="bh-toggle-input" type="checkbox" id="tm-evt-handler" <?= $evtHandler ? 'checked' : '' ?>
                    onchange="tmSaveSettings()">
                <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
            </label>
        </div>
        <div class="bh-cmd-card">
            <div>
                <div class="bh-cmd-name">Add Event</div>
                <div class="bh-cmd-desc">This event will have access to all the variables and settings of this module.</div>
            </div>
            <button class="bh-btn bh-btn--primary">Add</button>
        </div>
    </div>
</div>

<script>
(function () {
    const BOT_ID = <?= (int)$botId ?>;

    const tmChannelPicker = bhSetupChannelPicker('tm-channel-box', 'tm-channel-val', 'tm-channel-btn', BOT_ID);

    // ── Color sync ────────────────────────────────────────────────────────
    const colorPicker = document.getElementById('tm-color-picker');
    const colorHex    = document.getElementById('tm-color-hex');
    const colorSwatch = document.getElementById('tm-color-swatch');
    const embedStripe = document.getElementById('tm-embed-stripe');

    function applyColor(hex) {
        colorSwatch.style.background = hex;
        if (embedStripe) embedStripe.style.background = hex;
        colorHex.value    = hex;
        colorPicker.value = hex;
    }

    colorPicker.addEventListener('input', () => applyColor(colorPicker.value));
    colorHex.addEventListener('change', () => {
        const v = colorHex.value.trim();
        if (/^#[0-9a-fA-F]{6}$/.test(v)) applyColor(v);
    });

    // ── Embed / plain text toggle ─────────────────────────────────────────
    const isEmbedCb   = document.getElementById('tm-is-embed');
    const embedPanel  = document.getElementById('tm-embed-panel');
    const plainPanel  = document.getElementById('tm-plain-panel');

    isEmbedCb.addEventListener('change', () => {
        embedPanel.style.display = isEmbedCb.checked ? '' : 'none';
        plainPanel.style.display = isEmbedCb.checked ? 'none' : '';
    });

    // ── Flash ─────────────────────────────────────────────────────────────
    function flash(msg, ok) {
        const el = document.getElementById('bh-alert');
        el.className = 'bh-alert ' + (ok ? 'bh-alert--ok' : 'bh-alert--err');
        el.textContent = msg;
        el.style.display = '';
        clearTimeout(el._t);
        el._t = setTimeout(() => { el.style.display = 'none'; }, 4000);
    }

    // ── Add timed message ─────────────────────────────────────────────────
    window.tmAdd = async function () {
        const btn = document.getElementById('bh-btn bh-btn--primary');
        btn.disabled = true;

        const payload = {
            action:          'add',
            name:            document.getElementById('tm-name').value.trim(),
            channel_id:      document.getElementById('tm-channel-val').value.trim(),
            interval_days:   parseInt(document.getElementById('tm-days').value)   || 0,
            interval_hours:  parseInt(document.getElementById('tm-hours').value)  || 0,
            interval_minutes:parseInt(document.getElementById('tm-minutes').value)|| 0,
            is_embed:        document.getElementById('tm-is-embed').checked,
            plain_text:      document.getElementById('tm-plain-text').value.trim(),
            embed_author:    document.getElementById('tm-embed-author').value.trim(),
            embed_thumbnail: document.getElementById('tm-embed-thumb').value.trim(),
            embed_title:     document.getElementById('tm-embed-title').value.trim(),
            embed_body:      document.getElementById('tm-embed-body').value.trim(),
            embed_image:     document.getElementById('tm-embed-image').value.trim(),
            embed_color:     document.getElementById('tm-color-hex').value.trim(),
            embed_url:       document.getElementById('tm-embed-url').value.trim(),
            block_stacked:   document.getElementById('tm-block-stacked').checked,
        };

        try {
            const res  = await fetch(location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const json = await res.json();
            if (json.ok) {
                flash('Timed message added!', true);
                appendRow(json.id, payload);
                // reset form
                document.getElementById('tm-name').value    = '';
                document.getElementById('tm-channel-val').value = '';
                tmChannelPicker?.clear();
            } else {
                flash(json.error || 'Error saving.', false);
            }
        } catch (e) {
            flash('Network error.', false);
        }
        btn.disabled = false;
    };

    function appendRow(id, p) {
        const list = document.getElementById('tm-list');
        const parts = [];
        if (p.interval_days    > 0) parts.push(p.interval_days    + 'd');
        if (p.interval_hours   > 0) parts.push(p.interval_hours   + 'h');
        if (p.interval_minutes > 0) parts.push(p.interval_minutes + 'm');
        const intStr = parts.length ? parts.join(' ') : '1h';

        const div = document.createElement('div');
        div.className = 'bh-list-card';
        div.id = 'tm-row-' + id;
        div.innerHTML = `
            <div>
                <div class="bh-list-name">${escHtml(p.name)}</div>
                <div class="bh-list-meta">
                    Channel: <code style="color:#a5b4fc">${escHtml(p.channel_id)}</code>
                    &nbsp;·&nbsp; Interval: <strong>${escHtml(intStr)}</strong>
                    &nbsp;·&nbsp; ${p.is_embed ? 'Embed' : 'Plain text'}
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
                <label class="bh-toggle" title="Enable / Disable">
                    <input class="bh-toggle-input" type="checkbox" checked onchange="tmToggle(${id}, this.checked)">
                    <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                </label>
                <button class="bh-btn bh-btn--danger bh-btn--sm" onclick="tmDelete(${id})">Delete</button>
            </div>
        `;
        list.prepend(div);
    }

    // ── Delete ────────────────────────────────────────────────────────────
    window.tmDelete = async function (id) {
        if (!confirm('Delete this timed message?')) return;
        try {
            const res  = await fetch(location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', id }),
            });
            const json = await res.json();
            if (json.ok) {
                const row = document.getElementById('tm-row-' + id);
                if (row) row.remove();
                flash('Deleted.', true);
            } else {
                flash(json.error || 'Error.', false);
            }
        } catch (e) {
            flash('Network error.', false);
        }
    };

    // ── Toggle active ─────────────────────────────────────────────────────
    window.tmToggle = async function (id, active) {
        try {
            await fetch(location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'toggle', id, is_active: active }),
            });
        } catch (_) {}
    };

    // ── Save settings ─────────────────────────────────────────────────────
    window.tmSaveSettings = async function () {
        try {
            const res = await fetch(location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action:      'save_settings',
                    evt_handler: document.getElementById('tm-evt-handler').checked,
                }),
            });
            const json = await res.json();
            if (!json.ok) flash(json.error || 'Error saving settings.', false);
        } catch (_) {}
    };

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
})();
</script>
