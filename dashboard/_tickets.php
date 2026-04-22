<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/functions/db_functions/tickets.php';
require_once dirname(__DIR__) . '/functions/module_toggle.php';

$pdo   = bh_get_pdo();
$botId = isset($currentBotId) && $currentBotId > 0 ? $currentBotId : (int)($_GET['bot_id'] ?? 0);

if ($botId <= 0) { ?>
<div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl p-5">
    <div class="text-sm text-rose-600 dark:text-rose-400">Bot nicht gefunden.</div>
</div>
<?php return; }

try { bht_ensure_tables($pdo); bht_ensure_features_table($pdo); } catch (Throwable) {}

// ── AJAX ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bh_mod_handle_ajax($pdo, $botId);
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
            bht_save_settings($pdo, $botId, [
                'support_role_id' => (string)($data['support_role_id'] ?? ''),
                'category_id'     => (string)($data['category_id']     ?? ''),
                'log_channel_id'  => (string)($data['log_channel_id']  ?? ''),
                'open_message'    => (string)($data['open_message']     ?? ''),
                'dm_message'      => (string)($data['dm_message']       ?? ''),
            ]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'toggle_feature') {
        $key     = (string)($data['feature_key'] ?? '');
        $enabled = (bool)($data['enabled'] ?? true);
        if ($key === '') {
            echo json_encode(['ok' => false, 'error' => 'Missing feature_key']);
            exit;
        }
        try {
            bht_save_feature($pdo, $botId, $key, $enabled);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

// ── Load data ─────────────────────────────────────────────────────
$s        = bht_get_settings($pdo, $botId);
$tickets  = bht_list_tickets($pdo, $botId);
$features = bht_get_features($pdo, $botId);

function tk_h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Returns true (enabled) when feature_key not yet saved (default on). */
function tk_feat(array $features, string $key): bool {
    return isset($features[$key]) ? $features[$key] : true;
}

$commands = [
    ['key' => 'cmd_setup',         'label' => '/ticket setup',         'desc' => 'Send a panel to a channel'],
    ['key' => 'cmd_create',        'label' => '/ticket create',        'desc' => 'Create a ticket'],
    ['key' => 'cmd_close',         'label' => '/ticket close',         'desc' => 'Close the current ticket'],
    ['key' => 'cmd_reopen',        'label' => '/ticket reopen',        'desc' => 'Reopen the current ticket'],
    ['key' => 'cmd_delete',        'label' => '/ticket delete',        'desc' => 'Delete the current ticket'],
    ['key' => 'cmd_add',           'label' => '/ticket add',           'desc' => 'Add someone to the ticket'],
    ['key' => 'cmd_remove',        'label' => '/ticket remove',        'desc' => 'Remove someone from the ticket'],
    ['key' => 'cmd_automation',    'label' => '/ticket automation',    'desc' => 'Edit automated actions for this ticket'],
    ['key' => 'cmd_update_counts', 'label' => '/ticket update-counts', 'desc' => 'Update ticket counts of the ticket system'],
];

$events = [
    ['key' => 'evt_inactive_tickets',   'label' => 'Inactive Tickets Handler',          'desc' => 'When a timed event is executed'],
    ['key' => 'evt_creator_leaves_1',   'label' => 'Creator Leaves Handler',             'desc' => 'When a user leaves or is kicked from the server'],
    ['key' => 'evt_creator_leaves_2',   'label' => 'Creator Leaves Handler',             'desc' => 'When a user leaves or is kicked from the server'],
    ['key' => 'evt_transcripts_new',    'label' => 'Transcripts Handler - New Messages', 'desc' => 'When a new message is sent'],
    ['key' => 'evt_transcripts_update', 'label' => 'Transcripts Handler - Updated Messages', 'desc' => 'When a message is updated or edited'],
    ['key' => 'evt_transcripts_delete', 'label' => 'Transcripts Handler - Deleted Messages', 'desc' => 'When a message is deleted'],
];
$modEnabled = bh_mod_is_enabled($pdo, $botId, 'module:tickets');
?>

<link rel="stylesheet" href="/assets/css/command-switches.css">

<?= bh_mod_render($modEnabled, $botId, 'module:tickets', 'Ticket System', 'Ticket-Funktionalität und alle Ticket-Commands für diesen Bot ein- oder ausschalten.') ?>
<div id="bh-mod-body">
<div class="space-y-2" id="tk-root">

    <!-- Header -->
    <div class="mb-6">
        <p class="text-xs uppercase tracking-widest text-gray-400 dark:text-gray-500 mb-1">Moderation</p>
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Ticket System</h1>
        <p class="text-sm text-gray-400 dark:text-gray-500 mt-1">Configure the ticket system for your Discord server. Use <code>/ticket panel</code> to send the ticket panel to a channel.</p>
    </div>

    <div id="tk-banner" class="tk-banner"></div>

    <!-- ── Configuration ────────────────────────────────────────── -->
    <div class="bh-card">
        <div class="bh-card-hdr">
            <div>
                <p class="bh-card-title">Configuration</p>
                <p class="tk-card-desc">Set the support role, ticket category and log channel.</p>
            </div>
        </div>
        <div class="bh-card-body">
            <div class="tk-grid">
                <div class="bh-field">
                    <label class="bh-label" for="tk-support-role">Support Role ID</label>
                    <input type="text" id="tk-support-role" class="bh-input" maxlength="32"
                        placeholder="Role ID" value="<?= tk_h($s['support_role_id']) ?>">
                </div>
                <div class="bh-field">
                    <label class="bh-label" for="tk-category">Ticket Category ID</label>
                    <input type="text" id="tk-category" class="bh-input" maxlength="32"
                        placeholder="Category ID" value="<?= tk_h($s['category_id']) ?>">
                </div>
                <div class="bh-field">
                    <label class="bh-label" for="tk-log-channel">Log Channel ID</label>
                    <input type="text" id="tk-log-channel" class="bh-input" maxlength="32"
                        placeholder="Log Channel ID (optional)" value="<?= tk_h($s['log_channel_id']) ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- ── Messages ─────────────────────────────────────────────── -->
    <div class="bh-card">
        <div class="bh-card-hdr">
            <div>
                <p class="bh-card-title">Messages</p>
                <p class="tk-card-desc">Customize the messages shown when a ticket is opened or closed.</p>
            </div>
        </div>
        <div class="bh-card-body">
            <div class="tk-grid">
                <div class="bh-field tk-field-full">
                    <label class="bh-label" for="tk-open-msg">Open ticket message</label>
                    <textarea id="tk-open-msg" class="bh-input" maxlength="2000"
                        placeholder="Message shown inside the ticket channel when created…"><?= tk_h($s['open_message']) ?></textarea>
                </div>
                <div class="bh-field tk-field-full">
                    <label class="bh-label" for="tk-dm-msg">DM close message</label>
                    <textarea id="tk-dm-msg" class="bh-input" maxlength="2000"
                        placeholder="Message sent to the ticket creator via DM when ticket is closed…"><?= tk_h($s['dm_message']) ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- Save -->
    <button type="button" id="tk-save-btn" class="tk-save-btn">Save Settings</button>

    <!-- ── Commands ─────────────────────────────────────────────── -->
    <div style="margin-top:32px">
        <p class="text-xs uppercase tracking-widest text-gray-400 dark:text-gray-500 mb-1">Module</p>
        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">Commands</h2>
    </div>

    <div class="tk-feature-grid">
        <?php foreach ($commands as $cmd): ?>
        <div class="tk-feature-item">
            <div class="tk-feature-info">
                <div class="tk-feature-name"><?= tk_h($cmd['label']) ?></div>
                <div class="tk-feature-desc"><?= tk_h($cmd['desc']) ?></div>
            </div>
            <label class="command-switch <?= tk_feat($features, $cmd['key']) ? 'is-on' : '' ?>"
                   data-feature="<?= tk_h($cmd['key']) ?>">
                <input type="checkbox" class="command-switch__input" <?= tk_feat($features, $cmd['key']) ? 'checked' : '' ?>>
                <span class="command-switch__track"></span>
                <span class="command-switch__thumb"></span>
            </label>
        </div>
        <?php endforeach; ?>
        <!-- Add Command placeholder -->
        <div class="tk-feature-item tk-feature-add">
            <div class="tk-feature-info">
                <div class="tk-feature-name">Add Command</div>
                <div class="tk-feature-desc">This command will have access to all the variables and settings of this module.</div>
            </div>
            <button type="button" class="tk-add-btn" disabled title="Coming soon">Add</button>
        </div>
    </div>

    <!-- ── Events ───────────────────────────────────────────────── -->
    <div style="margin-top:32px">
        <p class="text-xs uppercase tracking-widest text-gray-400 dark:text-gray-500 mb-1">Module</p>
        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">Events</h2>
    </div>

    <div class="tk-feature-grid">
        <?php foreach ($events as $evt): ?>
        <div class="tk-feature-item">
            <div class="tk-feature-info">
                <div class="tk-feature-name"><?= tk_h($evt['label']) ?></div>
                <div class="tk-feature-desc"><?= tk_h($evt['desc']) ?></div>
            </div>
            <label class="command-switch <?= tk_feat($features, $evt['key']) ? 'is-on' : '' ?>"
                   data-feature="<?= tk_h($evt['key']) ?>">
                <input type="checkbox" class="command-switch__input" <?= tk_feat($features, $evt['key']) ? 'checked' : '' ?>>
                <span class="command-switch__track"></span>
                <span class="command-switch__thumb"></span>
            </label>
        </div>
        <?php endforeach; ?>
        <!-- Add Event placeholder -->
        <div class="tk-feature-item tk-feature-add">
            <div class="tk-feature-info">
                <div class="tk-feature-name">Add Event</div>
                <div class="tk-feature-desc">This event will have access to all the variables and settings of this module.</div>
            </div>
            <button type="button" class="tk-add-btn" disabled title="Coming soon">Add</button>
        </div>
    </div>

    <!-- ── Active Tickets ────────────────────────────────────────── -->
    <div class="bh-card" style="margin-top:32px">
        <div class="bh-card-hdr">
            <div>
                <p class="bh-card-title">Ticket Overview</p>
                <p class="tk-card-desc">Last 100 tickets across all servers for this bot.</p>
            </div>
            <span style="font-size:12px;color:#94a3b8"><?= count($tickets) ?> total</span>
        </div>
        <div class="bh-card-body" style="padding:0">
            <?php if (empty($tickets)): ?>
                <div class="tk-empty">No tickets yet. Users can create tickets with <code>/ticket create</code> or the panel button.</div>
            <?php else: ?>
            <div style="overflow-x:auto">
            <table class="tk-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Channel</th>
                        <th>Creator</th>
                        <th>Claimed by</th>
                        <th>Created</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tickets as $t): ?>
                    <tr>
                        <td><?= str_pad((string)(int)$t['ticket_num'], 4, '0', STR_PAD_LEFT) ?></td>
                        <td><code><?= tk_h($t['channel_id']) ?></code></td>
                        <td><code><?= tk_h($t['creator_id']) ?></code></td>
                        <td><?= $t['claimed_by'] ? '<code>' . tk_h($t['claimed_by']) . '</code>' : '<span style="color:#94a3b8">—</span>' ?></td>
                        <td style="white-space:nowrap"><?= tk_h((string)$t['created_at']) ?></td>
                        <td>
                            <?php if ($t['resolved']): ?>
                                <span class="tk-badge tk-badge-closed">Closed</span>
                            <?php else: ?>
                                <span class="tk-badge tk-badge-open">Open</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>
</div><!-- /bh-mod-body -->

<script>
(function () {
    'use strict';

    var apiUrl = window.location.href;

    function showBanner(msg, isErr) {
        var b = document.getElementById('tk-banner');
        b.textContent = msg;
        b.className = 'tk-banner ' + (isErr ? 'err' : 'ok');
        b.style.display = 'block';
        clearTimeout(b._t);
        b._t = setTimeout(function () { b.style.display = 'none'; }, 3500);
    }

    // ── Settings save ──────────────────────────────────────────────
    document.getElementById('tk-save-btn').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true;

        var payload = {
            action:          'save',
            support_role_id: document.getElementById('tk-support-role').value.trim(),
            category_id:     document.getElementById('tk-category').value.trim(),
            log_channel_id:  document.getElementById('tk-log-channel').value.trim(),
            open_message:    document.getElementById('tk-open-msg').value,
            dm_message:      document.getElementById('tk-dm-msg').value,
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

    // ── Feature toggles ────────────────────────────────────────────
    document.querySelectorAll('.command-switch[data-feature]').forEach(function (label) {
        var input = label.querySelector('.command-switch__input');
        if (!input) return;

        input.addEventListener('change', function () {
            var featureKey = label.getAttribute('data-feature');
            var enabled    = input.checked;

            label.classList.toggle('is-on', enabled);

            fetch(apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'toggle_feature', feature_key: featureKey, enabled: enabled }),
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) showBanner('Error: ' + (d.error || 'unknown'), true);
            })
            .catch(function () { showBanner('Network error.', true); });
        });
    });
}());
</script>
