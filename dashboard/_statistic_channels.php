<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/functions/db_functions/statistic_channels.php';
require_once dirname(__DIR__) . '/functions/module_toggle.php';

$pdo   = bh_get_pdo();
$botId = isset($currentBotId) && $currentBotId > 0 ? $currentBotId : (int)($_GET['bot_id'] ?? 0);

if ($botId <= 0) { ?><div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl p-5"><div class="text-sm text-rose-600 dark:text-rose-400">Bot nicht gefunden.</div></div><?php return; }

try { bhsc_ensure_table($pdo); } catch (Throwable) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bh_mod_handle_ajax($pdo, $botId);
    while (ob_get_level() > 0) { ob_end_clean(); }
    $raw  = (string)file_get_contents('php://input');
    $data = json_decode($raw, true);
    header('Content-Type: application/json; charset=utf-8');

    $validStatTypes = ['total_members','human_members','bot_members','online_members',
                       'server_channels','server_roles','banned_members','server_emojis',
                       'server_stickers','boost_tier','scheduled_events'];

    if (($data['action'] ?? '') === 'add') {
        $channelId   = trim((string)($data['channel_id']   ?? ''));
        $guildId     = trim((string)($data['guild_id']     ?? ''));
        $channelName = mb_substr(trim((string)($data['channel_name'] ?? 'Members: {value}')), 0, 100);
        $statType    = in_array($data['stat_type'] ?? '', $validStatTypes, true)
                         ? $data['stat_type'] : 'total_members';
        $autoLock    = (isset($data['auto_lock']) && $data['auto_lock']) ? 1 : 0;

        if ($channelId === '') { echo json_encode(['ok' => false, 'error' => 'Channel ID is required.']); exit; }

        try {
            $newId = bhsc_add($pdo, $botId, $guildId, $channelId, $channelName, $statType, $autoLock);
            echo json_encode(['ok' => true, 'id' => $newId]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if (($data['action'] ?? '') === 'delete') {
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid ID.']); exit; }
        try {
            bhsc_delete($pdo, $botId, $id);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
        exit;
    }

    if (($data['action'] ?? '') === 'toggle') {
        $id = (int)($data['id'] ?? 0);
        $active = (isset($data['is_active']) && $data['is_active']) ? 1 : 0;
        if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid ID.']); exit; }
        try {
            bhsc_toggle($pdo, $botId, $id, $active);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']); exit;
}

$counters = [];
try { $counters = bhsc_list($pdo, $botId); } catch (Throwable) {}

$statTypeLabels = [
    'total_members'   => 'Total Server Members',
    'human_members'   => 'Human Members',
    'bot_members'     => 'Bot Members',
    'online_members'  => 'Online Members',
    'server_channels' => 'Server Channels',
    'server_roles'    => 'Server Roles',
    'banned_members'  => 'Banned Members',
    'server_emojis'   => 'Server Emojis',
    'server_stickers' => 'Server Stickers',
    'boost_tier'      => 'Boost Tier',
    'scheduled_events'=> 'Scheduled Events',
];

$esc = fn(string $v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$modEnabled = bh_mod_is_enabled($pdo, $botId, 'module:statistic-channels');
?>

<?= bh_mod_render($modEnabled, $botId, 'module:statistic-channels', 'Statistic Channels', 'Statistik-Kanäle für diesen Bot ein- oder ausschalten.') ?>
<div id="bh-mod-body">
<div class="bh-section-label">STATISTIC CHANNELS</div>
<div class="bh-section-title">New Counter Setup</div>

<div id="bh-alert" style="display:none"></div>

<div class="bh-card">
    <div class="bh-card-title">Statistic Channel Setup</div>
    <div class="bh-card-desc">Set up a new locked voice channel to track a selected metric of your server in.</div>

    <!-- Channel Selection Type -->
    <div class="bh-field">
        <div class="bh-label">Channel Selection Type</div>
        <div class="bh-hint">Select a voice channel from a dropdown (the bot fetches all available voice channels), or paste the <span style="color:#ef4444">Channel ID</span> manually.</div>
        <select id="sc-sel-type" class="bh-select" style="max-width:220px" onchange="scToggleInputType()">
            <option value="dropdown">Bot Dropdown</option>
            <option value="id">Paste Channel ID</option>
        </select>
    </div>

    <!-- Bot Dropdown mode -->
    <div id="sc-dropdown-mode" style="margin-top:10px">
        <div id="sc-guild-wrap" style="display:none;margin-bottom:10px">
            <div class="bh-label">Server</div>
            <select id="sc-guild-select" class="bh-select" onchange="scLoadVoiceChannels(this.value)">
                <option value="">— Server wählen —</option>
            </select>
        </div>
        <div class="bh-label">Voice Channel</div>
        <select id="sc-channel-select" class="bh-select">
            <option value="">— Channels werden geladen… —</option>
        </select>
        <div id="sc-load-error" style="display:none;font-size:11px;color:#f87171;margin-top:4px"></div>
    </div>

    <!-- Paste ID mode -->
    <div id="sc-id-mode" style="display:none;margin-top:10px">
        <div class="bh-label">Channel ID</div>
        <input type="text" id="sc-channel-id" class="bh-input" placeholder="Voice Channel ID (e.g. 123456789012345678)">
    </div>

    <!-- Channel Name -->
    <div class="bh-field" style="margin-top:20px">
        <div class="bh-label">Channel Name</div>
        <div class="bh-hint">Choose a name for your counter channel. Use <span style="color:#ef4444">{value}</span> as the placeholder for the live value.</div>
        <div class="bh-label">Channel Name</div>
        <input type="text" id="sc-channel-name" class="bh-input" placeholder="Online Members: {value}" value="Online Members: {value}">
    </div>

    <!-- Statistic Type -->
    <div class="bh-field">
        <div class="bh-label">Statistic Type</div>
        <div class="bh-hint">Choose the type of data your channel will track.</div>
        <select id="sc-stat-type" class="bh-select" onchange="scUpdateNamePlaceholder()">
            <?php foreach ($statTypeLabels as $val => $label): ?>
            <option value="<?= $esc($val) ?>"><?= $esc($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Auto-Lock -->
    <div class="bh-toggle-row" style="border-top:1px solid #1a2030;padding-top:16px">
        <div>
            <div class="bh-toggle-row__title">Auto-Lock Channel</div>
            <div class="bh-toggle-row__desc">While having this setting enabled, permissions for the <strong>@everyone</strong> role will be denied to enter your statistic channel.</div>
        </div>
        <label class="bh-toggle" style="margin-left:16px">
            <input class="bh-toggle-input" type="checkbox" id="sc-auto-lock" checked>
            <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
        </label>
    </div>

    <button class="sc-add-btn" id="sc-add-btn" onclick="scAdd()">Add Counter</button>
</div>

<!-- Existing counters -->
<?php if (!empty($counters)): ?>
<div class="bh-section-label" style="margin-top:28px">EXISTING COUNTERS</div>
<?php endif; ?>
<div id="sc-list">
<?php foreach ($counters as $c): ?>
<div class="bh-list-card" id="sc-row-<?= (int)$c['id'] ?>">
    <div>
        <div class="bh-list-name"><?= $esc((string)$c['channel_name']) ?></div>
        <div class="bh-list-meta">
            Channel: <code style="color:#a5b4fc"><?= $esc((string)$c['channel_id']) ?></code>
            &nbsp;·&nbsp; Type: <strong><?= $esc($statTypeLabels[$c['stat_type']] ?? $c['stat_type']) ?></strong>
            <?php if ($c['cached_value'] !== ''): ?>&nbsp;·&nbsp; Last: <strong><?= $esc((string)$c['cached_value']) ?></strong><?php endif; ?>
            <?php if (!(int)$c['is_active']): ?>&nbsp;·&nbsp; <span style="color:#f87171">Paused</span><?php endif; ?>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
        <label class="bh-toggle">
            <input class="bh-toggle-input" type="checkbox" <?= (int)$c['is_active'] ? 'checked' : '' ?>
                onchange="scToggle(<?= (int)$c['id'] ?>, this.checked)">
            <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
        </label>
        <button class="sc-list-del-btn" onclick="scDelete(<?= (int)$c['id'] ?>)">Delete</button>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Commands -->
<div style="margin-top:32px">
    <div class="bh-section-label">MODULE</div>
    <div class="sc-module-title">Commands</div>
    <div class="bh-cmd-grid">
        <div class="bh-cmd-card">
            <div><div class="bh-cmd-name">Add Command</div><div class="bh-cmd-desc">This command will have access to all the variables and settings of this module.</div></div>
            <button class="bh-btn bh-btn--primary">Add</button>
        </div>
    </div>
</div>

<!-- Events -->
<div style="margin-top:28px;margin-bottom:32px">
    <div class="bh-section-label">MODULE</div>
    <div class="sc-module-title">Events</div>
    <div class="bh-cmd-grid">
        <div class="bh-cmd-card">
            <div><div class="bh-cmd-name">Add Event</div><div class="bh-cmd-desc">This event will have access to all the variables and settings of this module.</div></div>
            <button class="bh-btn bh-btn--primary">Add</button>
        </div>
    </div>
</div>
</div><!-- /bh-mod-body -->

<script>
(function () {
    const BOT_ID = <?= (int)$botId ?>;
    const VOICE_API = '/api/v1/bot_guild_voice_channels.php';

    const namePlaceholders = {
        total_members:    'Total Members: {value}',
        human_members:    'Human Members: {value}',
        bot_members:      'Bot Members: {value}',
        online_members:   'Online Members: {value}',
        server_channels:  'Channels: {value}',
        server_roles:     'Roles: {value}',
        banned_members:   'Banned: {value}',
        server_emojis:    'Emojis: {value}',
        server_stickers:  'Stickers: {value}',
        boost_tier:       'Boost Tier: {value}',
        scheduled_events: 'Events: {value}',
    };

    // ── Mode toggle ──────────────────────────────────────────────────────
    window.scToggleInputType = function () {
        const mode = document.getElementById('sc-sel-type').value;
        document.getElementById('sc-dropdown-mode').style.display = mode === 'dropdown' ? '' : 'none';
        document.getElementById('sc-id-mode').style.display        = mode === 'id'       ? '' : 'none';
    };

    // ── Load voice channels via bot API ──────────────────────────────────
    window.scLoadVoiceChannels = async function (guildId) {
        const chanSel = document.getElementById('sc-channel-select');
        const errEl   = document.getElementById('sc-load-error');
        errEl.style.display = 'none';
        chanSel.disabled = true;

        try {
            let url = VOICE_API + '?bot_id=' + BOT_ID;
            if (guildId) url += '&guild_id=' + encodeURIComponent(guildId);

            const res  = await fetch(url);
            const json = await res.json();

            if (!json.ok) {
                errEl.textContent = json.error || 'Failed to load channels.';
                errEl.style.display = '';
                chanSel.innerHTML = '<option value="">— Fehler beim Laden —</option>';
                return;
            }

            // Multiple guilds — show guild selector first
            if (json.needs_guild) {
                const guildSel = document.getElementById('sc-guild-select');
                guildSel.innerHTML = '<option value="">— Server wählen —</option>';
                for (const g of json.guilds) {
                    const opt = document.createElement('option');
                    opt.value = g.id;
                    opt.textContent = g.name;
                    guildSel.appendChild(opt);
                }
                document.getElementById('sc-guild-wrap').style.display = '';
                chanSel.innerHTML = '<option value="">— Server wählen —</option>';
                return;
            }

            // Populate channel dropdown
            chanSel.dataset.guildId = json.guild_id || '';
            if (json.channels.length === 0) {
                chanSel.innerHTML = '<option value="">— Keine Voice Channels gefunden —</option>';
                return;
            }
            chanSel.innerHTML = '<option value="">— Voice Channel wählen —</option>';
            for (const c of json.channels) {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = '🔊 ' + c.name;
                chanSel.appendChild(opt);
            }

            if (!guildId) {
                document.getElementById('sc-guild-wrap').style.display = 'none';
            }

        } catch (e) {
            errEl.textContent = 'Network error: ' + e.message;
            errEl.style.display = '';
            chanSel.innerHTML = '<option value="">— Fehler beim Laden —</option>';
        } finally {
            chanSel.disabled = false;
        }
    };

    // Auto-load on page open
    scLoadVoiceChannels('');

    // ── Name placeholder update ──────────────────────────────────────────
    window.scUpdateNamePlaceholder = function () {
        const t  = document.getElementById('sc-stat-type').value;
        const ni = document.getElementById('sc-channel-name');
        ni.placeholder = namePlaceholders[t] || 'Value: {value}';
        if (!ni.value || Object.values(namePlaceholders).includes(ni.value)) {
            ni.value = namePlaceholders[t] || 'Value: {value}';
        }
    };

    // ── Add counter ──────────────────────────────────────────────────────
    window.scAdd = async function () {
        const btn = document.getElementById('sc-add-btn');
        btn.disabled = true;

        const mode = document.getElementById('sc-sel-type').value;
        let channelId = '';
        let guildId   = '';

        if (mode === 'dropdown') {
            const sel = document.getElementById('sc-channel-select');
            channelId = sel.value;
            guildId   = sel.dataset.guildId || '';
        } else {
            channelId = document.getElementById('sc-channel-id').value.trim();
        }

        if (!channelId) {
            flash('Please select or enter a voice channel.', false);
            btn.disabled = false;
            return;
        }

        const payload = {
            action:       'add',
            channel_id:   channelId,
            guild_id:     guildId,
            channel_name: document.getElementById('sc-channel-name').value.trim(),
            stat_type:    document.getElementById('sc-stat-type').value,
            auto_lock:    document.getElementById('sc-auto-lock').checked,
        };

        try {
            const res  = await fetch(location.href, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
            const json = await res.json();
            if (json.ok) {
                flash('Counter added!', true);
                appendRow(json.id, payload);
                // Reset inputs
                document.getElementById('sc-channel-select').innerHTML = '<option value="">— Channels laden —</option>';
                document.getElementById('sc-channel-id').value = '';
            } else {
                flash(json.error || 'Error.', false);
            }
        } catch (e) { flash('Network error.', false); }
        btn.disabled = false;
    };

    function appendRow(id, p) {
        const list = document.getElementById('sc-list');
        const labels = <?= json_encode($statTypeLabels) ?>;
        const div = document.createElement('div');
        div.className = 'bh-list-card';
        div.id = 'sc-row-' + id;
        div.innerHTML = `
            <div>
                <div class="bh-list-name">${esc(p.channel_name)}</div>
                <div class="bh-list-meta">Channel: <code style="color:#a5b4fc">${esc(p.channel_id)}</code> &nbsp;·&nbsp; Type: <strong>${esc(labels[p.stat_type]||p.stat_type)}</strong></div>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
                <label class="bh-toggle">
                    <input class="bh-toggle-input" type="checkbox" checked onchange="scToggle(${id},this.checked)">
                    <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                </label>
                <button class="sc-list-del-btn" onclick="scDelete(${id})">Delete</button>
            </div>`;
        list.prepend(div);

        // Show "EXISTING COUNTERS" label if it wasn't there
        if (!document.querySelector('.bh-section-label[data-existing]')) {
            const lbl = document.createElement('div');
            lbl.className = 'bh-section-label';
            lbl.dataset.existing = '1';
            lbl.style.marginTop = '28px';
            lbl.textContent = 'EXISTING COUNTERS';
            list.parentNode.insertBefore(lbl, list);
        }
    }

    window.scDelete = async function (id) {
        if (!confirm('Delete this counter?')) return;
        const res  = await fetch(location.href, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'delete',id}) });
        const json = await res.json();
        if (json.ok) { document.getElementById('sc-row-'+id)?.remove(); flash('Deleted.',true); }
        else flash(json.error||'Error.',false);
    };

    window.scToggle = async function (id, active) {
        try {
            const res  = await fetch(location.href, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'toggle',id,is_active:active}) });
            const json = await res.json();
            if (!json.ok) flash(json.error || 'Update failed.', false);
        } catch(e) { flash('Network error.', false); }
    };

    function flash(msg, ok) {
        const el = document.getElementById('bh-alert');
        el.className = 'bh-alert ' + (ok ? 'bh-alert--ok' : 'bh-alert--err');
        el.textContent = msg; el.style.display = '';
        clearTimeout(el._t); el._t = setTimeout(()=>el.style.display='none',4000);
    }

    function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
})();
</script>
