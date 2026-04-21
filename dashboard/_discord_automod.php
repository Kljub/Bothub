<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/functions/db_functions/automod.php';

$pdo   = bh_get_pdo();
$botId = isset($currentBotId) && $currentBotId > 0 ? $currentBotId : (int)($_GET['bot_id'] ?? 0);

if ($botId <= 0) { ?>
<div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl p-5">
    <div class="text-sm text-rose-600 dark:text-rose-400">Bot nicht gefunden.</div>
</div>
<?php return; }

try { bham_ensure_tables($pdo); } catch (Throwable) {}

// ── AJAX ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    $raw  = (string)file_get_contents('php://input');
    $data = json_decode($raw, true);
    header('Content-Type: application/json; charset=utf-8');

    if (!is_array($data)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
        exit;
    }

    $action = (string)($data['action'] ?? 'save');

    if ($action === 'save') {
        try {
            bham_save($pdo, $botId, [
                'anti_invite'    => (int)($data['anti_invite']   ?? 0),
                'anti_links'     => (int)($data['anti_links']    ?? 0),
                'anti_spam'      => (int)($data['anti_spam']     ?? 0),
                'spam_max_msg'   => (int)($data['spam_max_msg']  ?? 5),
                'spam_window_s'  => (int)($data['spam_window_s'] ?? 5),
                'spam_action'    => (string)($data['spam_action'] ?? 'delete'),
                'link_channels'  => (array)($data['link_channels'] ?? []),
                'blacklist'      => (array)($data['blacklist']     ?? []),
                'log_channel_id' => (string)($data['log_channel_id'] ?? ''),
            ]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

// ── Load settings ──────────────────────────────────────────────
$s = bham_get($pdo, $botId);

$linkChannels = is_array($s['link_channels']) ? $s['link_channels'] : [];
$blacklist     = is_array($s['blacklist'])     ? $s['blacklist']     : [];

function am_h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>

<div class="space-y-2" id="am-root">

    <!-- Header -->
    <div class="mb-6">
        <p class="text-xs uppercase tracking-widest text-gray-400 dark:text-gray-500 mb-1">Moderation</p>
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Discord Automod</h1>
        <p class="text-sm text-gray-400 dark:text-gray-500 mt-1">Automatically moderate messages in your server.</p>
    </div>

    <div id="am-banner" class="am-banner"></div>

    <!-- ── Anti-Invite ─────────────────────────────────────────── -->
    <div class="am-card">
        <div class="am-card-header">
            <div class="am-card-header-left">
                <p class="am-card-title">Anti-Invite</p>
                <p class="am-card-desc">Automatically delete Discord invite links (discord.gg, discord.com/invite).</p>
            </div>
            <label class="am-toggle">
                <input type="checkbox" id="am-anti-invite" <?= $s['anti_invite'] ? 'checked' : '' ?>>
                <span class="am-toggle-track"></span>
            </label>
        </div>
    </div>

    <!-- ── Anti-Links ──────────────────────────────────────────── -->
    <div class="am-card">
        <div class="am-card-header">
            <div class="am-card-header-left">
                <p class="am-card-title">Anti-Links</p>
                <p class="am-card-desc">Automatically delete all URLs and links. Link Channels are exempt.</p>
            </div>
            <label class="am-toggle">
                <input type="checkbox" id="am-anti-links" <?= $s['anti_links'] ? 'checked' : '' ?>>
                <span class="am-toggle-track"></span>
            </label>
        </div>
    </div>

    <!-- ── Anti-Spam ───────────────────────────────────────────── -->
    <div class="am-card">
        <div class="am-card-header">
            <div class="am-card-header-left">
                <p class="am-card-title">Anti-Spam</p>
                <p class="am-card-desc">Detect and punish users who send too many messages in a short time.</p>
            </div>
            <label class="am-toggle">
                <input type="checkbox" id="am-anti-spam" <?= $s['anti_spam'] ? 'checked' : '' ?>>
                <span class="am-toggle-track"></span>
            </label>
        </div>
        <div class="am-card-body <?= $s['anti_spam'] ? '' : 'is-hidden' ?>" id="am-spam-body">
            <div class="am-field">
                <span class="am-field-label">Max messages</span>
                <input type="number" id="am-spam-max" class="am-input am-input-sm" min="2" max="50"
                    value="<?= (int)$s['spam_max_msg'] ?>">
                <span style="font-size:12px;color:#94a3b8">messages per</span>
                <input type="number" id="am-spam-window" class="am-input am-input-sm" min="1" max="120"
                    value="<?= (int)$s['spam_window_s'] ?>">
                <span style="font-size:12px;color:#94a3b8">seconds</span>
            </div>
            <div class="am-field">
                <span class="am-field-label">Action on trigger</span>
                <select id="am-spam-action" class="am-input am-input-md">
                    <?php foreach (['delete' => 'Delete message only', 'warn' => 'Warn user', 'kick' => 'Kick user', 'ban' => 'Ban user'] as $v => $l): ?>
                        <option value="<?= $v ?>" <?= $s['spam_action'] === $v ? 'selected' : '' ?>><?= am_h($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- ── Link Channels ───────────────────────────────────────── -->
    <div class="am-card">
        <div class="am-card-header">
            <div class="am-card-header-left">
                <p class="am-card-title">Link Channels</p>
                <p class="am-card-desc">Channels where links are allowed even if Anti-Links is active.</p>
            </div>
        </div>
        <div class="am-card-body">
            <div class="am-tag-row" id="am-lc-tags">
                <?php foreach ($linkChannels as $ch): ?>
                <span class="am-tag" data-value="<?= am_h((string)$ch) ?>">
                    #<?= am_h((string)$ch) ?>
                    <button type="button" class="am-tag-rm" title="Remove" data-list="lc">×</button>
                </span>
                <?php endforeach; ?>
            </div>
            <div class="am-add-row">
                <input type="text" id="am-lc-input" class="am-add-input" placeholder="Channel ID eingeben…" maxlength="32">
                <button type="button" class="am-add-btn" id="am-lc-add">+ Add</button>
            </div>
        </div>
    </div>

    <!-- ── Blacklisted Words ────────────────────────────────────── -->
    <div class="am-card">
        <div class="am-card-header">
            <div class="am-card-header-left">
                <p class="am-card-title">Blacklisted Words</p>
                <p class="am-card-desc">Messages containing these words will be automatically deleted.</p>
            </div>
        </div>
        <div class="am-card-body">
            <div class="am-tag-row" id="am-bl-tags">
                <?php foreach ($blacklist as $word): ?>
                <span class="am-tag" data-value="<?= am_h((string)$word) ?>">
                    <?= am_h((string)$word) ?>
                    <button type="button" class="am-tag-rm" title="Remove" data-list="bl">×</button>
                </span>
                <?php endforeach; ?>
            </div>
            <div class="am-add-row">
                <input type="text" id="am-bl-input" class="am-add-input" placeholder="Wort oder Phrase eingeben…" maxlength="100">
                <button type="button" class="am-add-btn" id="am-bl-add">+ Add</button>
            </div>
        </div>
    </div>

    <!-- ── Log Channel ─────────────────────────────────────────── -->
    <div class="am-card">
        <div class="am-card-header">
            <div class="am-card-header-left">
                <p class="am-card-title">Log Channel</p>
                <p class="am-card-desc">Optional: Channel ID where automod actions are logged.</p>
            </div>
        </div>
        <div class="am-card-body">
            <input type="text" id="am-log-channel" class="am-input am-input-full" maxlength="32"
                placeholder="Channel ID (optional)"
                value="<?= am_h($s['log_channel_id']) ?>">
        </div>
    </div>

    <!-- Save -->
    <button type="button" id="am-save-btn" class="am-save-btn">Save Settings</button>

</div>

<script>
(function () {
    'use strict';

    var apiUrl = window.location.href;

    // ── Spam toggle body ──────────────────────────────────────────
    document.getElementById('am-anti-spam').addEventListener('change', function () {
        document.getElementById('am-spam-body').classList.toggle('is-hidden', !this.checked);
    });

    // ── Tag lists ─────────────────────────────────────────────────
    function getList(containerId) {
        var tags = document.getElementById(containerId).querySelectorAll('.am-tag');
        var values = [];
        tags.forEach(function (t) { values.push(t.dataset.value); });
        return values;
    }

    function addTag(containerId, value) {
        value = value.trim();
        if (!value) return false;
        // dedup
        var existing = getList(containerId);
        if (existing.indexOf(value) !== -1) return false;

        var listKey = containerId === 'am-lc-tags' ? 'lc' : 'bl';
        var label   = containerId === 'am-lc-tags' ? '#' + value : value;

        var span = document.createElement('span');
        span.className  = 'am-tag';
        span.dataset.value = value;
        span.innerHTML = esc(label)
            + '<button type="button" class="am-tag-rm" title="Remove" data-list="' + listKey + '">×</button>';
        span.querySelector('.am-tag-rm').addEventListener('click', handleRemove);

        document.getElementById(containerId).appendChild(span);
        return true;
    }

    function handleRemove(e) {
        e.currentTarget.closest('.am-tag').remove();
    }

    // Attach remove to existing tags
    document.querySelectorAll('.am-tag-rm').forEach(function (btn) {
        btn.addEventListener('click', handleRemove);
    });

    // Add button — Link Channels
    document.getElementById('am-lc-add').addEventListener('click', function () {
        var inp = document.getElementById('am-lc-input');
        if (addTag('am-lc-tags', inp.value)) inp.value = '';
    });
    document.getElementById('am-lc-input').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); document.getElementById('am-lc-add').click(); }
    });

    // Add button — Blacklist
    document.getElementById('am-bl-add').addEventListener('click', function () {
        var inp = document.getElementById('am-bl-input');
        if (addTag('am-bl-tags', inp.value)) inp.value = '';
    });
    document.getElementById('am-bl-input').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); document.getElementById('am-bl-add').click(); }
    });

    // ── Banner ────────────────────────────────────────────────────
    function showBanner(msg, isErr) {
        var b = document.getElementById('am-banner');
        b.textContent = msg;
        b.className = 'am-banner ' + (isErr ? 'err' : 'ok');
        b.style.display = 'block';
        clearTimeout(b._t);
        b._t = setTimeout(function () { b.style.display = 'none'; }, 3500);
    }

    // ── Save ──────────────────────────────────────────────────────
    document.getElementById('am-save-btn').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true;

        var payload = {
            action:         'save',
            anti_invite:    document.getElementById('am-anti-invite').checked ? 1 : 0,
            anti_links:     document.getElementById('am-anti-links').checked  ? 1 : 0,
            anti_spam:      document.getElementById('am-anti-spam').checked   ? 1 : 0,
            spam_max_msg:   parseInt(document.getElementById('am-spam-max').value,    10) || 5,
            spam_window_s:  parseInt(document.getElementById('am-spam-window').value, 10) || 5,
            spam_action:    document.getElementById('am-spam-action').value,
            link_channels:  getList('am-lc-tags'),
            blacklist:      getList('am-bl-tags'),
            log_channel_id: document.getElementById('am-log-channel').value.trim(),
        };

        fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            showBanner(d.ok ? 'Settings saved.' : ('Error: ' + (d.error || 'unknown')), !d.ok);
        })
        .catch(function () { showBanner('Network error.', true); })
        .finally(function () { btn.disabled = false; });
    });

    function esc(s) {
        return String(s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
}());
</script>
