<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/functions/db_functions/autoresponder.php';
require_once dirname(__DIR__) . '/functions/module_toggle.php';

$pdo   = bh_get_pdo();
$botId = isset($currentBotId) && $currentBotId > 0 ? $currentBotId : (int)($_GET['bot_id'] ?? 0);

if ($botId <= 0) { ?>
<div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl p-5">
    <div class="text-sm text-rose-600 dark:text-rose-400">Bot nicht gefunden.</div>
</div>
<?php return; }

// ── Auto-migrate ──────────────────────────────────────────────────────────
try { bhar_ensure_tables($pdo); } catch (Throwable) {}

// ── Handle AJAX ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bh_mod_handle_ajax($pdo, $botId);
    while (ob_get_level() > 0) { ob_end_clean(); }
    $raw    = (string)file_get_contents('php://input');
    $data   = json_decode($raw, true);
    $action = (string)($data['action'] ?? '');
    header('Content-Type: application/json; charset=utf-8');

    if ($action === 'add') {
        $validTypes = ['contains', 'starts_with', 'exact'];
        $triggerType = in_array($data['trigger_type'] ?? '', $validTypes, true)
            ? $data['trigger_type'] : 'contains';

        $keywords = array_values(array_slice(
            array_filter(array_map('strval', (array)($data['keywords'] ?? [])), fn($k) => $k !== ''),
            0, 12
        ));
        if (empty($keywords)) {
            echo json_encode(['ok' => false, 'error' => 'At least one keyword is required.']);
            exit;
        }

        $isEmbed    = (isset($data['is_embed']) && $data['is_embed']) ? 1 : 0;
        $plainText  = trim((string)($data['plain_text'] ?? ''));
        $author     = mb_substr(trim((string)($data['embed_author']    ?? '')), 0, 256);
        $thumb      = mb_substr(trim((string)($data['embed_thumbnail'] ?? '')), 0, 512);
        $title      = mb_substr(trim((string)($data['embed_title']     ?? '')), 0, 256);
        $body       = trim((string)($data['embed_body'] ?? ''));
        $image      = mb_substr(trim((string)($data['embed_image'] ?? '')), 0, 512);
        $color      = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($data['embed_color'] ?? ''))
                        ? (string)$data['embed_color'] : '#ef4444';
        $embedUrl   = mb_substr(trim((string)($data['embed_url'] ?? '')), 0, 512);
        $cooldown   = max(0, (int)($data['channel_cooldown'] ?? 10));
        $mention    = (isset($data['mention_user']) && $data['mention_user']) ? 1 : 0;

        $validFilters = ['all_except', 'selected'];
        $chFilterType = in_array($data['channel_filter_type'] ?? '', $validFilters, true)
            ? $data['channel_filter_type'] : 'all_except';
        $roleFilterType = in_array($data['role_filter_type'] ?? '', $validFilters, true)
            ? $data['role_filter_type'] : 'all_except';

        $encodeItems = fn(array $arr): string => json_encode(
            array_values(array_filter($arr, fn($i) => is_array($i) && isset($i['id']))),
            JSON_UNESCAPED_UNICODE
        );
        $filteredCh   = $encodeItems((array)($data['filtered_channels'] ?? []));
        $filteredRoles = $encodeItems((array)($data['filtered_roles'] ?? []));

        try {
            $newId = bhar_add($pdo, $botId, [
                'trigger_type' => $triggerType,
                'keywords' => json_encode($keywords, JSON_UNESCAPED_UNICODE),
                'is_embed' => $isEmbed, 'plain_text' => $plainText,
                'author' => $author, 'thumb' => $thumb, 'title' => $title,
                'body' => $body, 'image' => $image, 'color' => $color, 'embed_url' => $embedUrl,
                'cooldown' => $cooldown, 'mention' => $mention,
                'ch_filter_type' => $chFilterType, 'filtered_channels' => $filteredCh,
                'role_filter_type' => $roleFilterType, 'filtered_roles' => $filteredRoles,
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
            bhar_delete($pdo, $botId, $id);
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
            bhar_toggle($pdo, $botId, $id, $isActive);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

// ── Load existing ─────────────────────────────────────────────────────────
$autoresponders = [];
try { $autoresponders = bhar_list($pdo, $botId); } catch (Throwable) {}

$esc = fn(string $v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$modEnabled = bh_mod_is_enabled($pdo, $botId, 'module:autoresponder');
?>

<?= bh_mod_render($modEnabled, $botId, 'module:autoresponder', 'Autoresponder', 'Autoresponder-Funktion für diesen Bot ein- oder ausschalten.') ?>
<div id="bh-mod-body">
<div class="ar-section-label">AUTORESPONDER</div>
<div class="ar-section-title">New Autoresponder Message</div>

<div id="ar-flash" style="display:none"></div>

<div class="ar-card">
    <div class="ar-card-title">New Autoresponder Message</div>
    <div class="ar-card-desc">Set up a new embedded or plain text message your bot will send as a reply, based on trigger keywords and phrases!</div>

    <!-- Trigger Detection Type -->
    <div class="ar-field">
        <div class="ar-field-label">Trigger Detection Type</div>
        <div class="ar-field-desc">Choose a setting about how your bot should handle detecting trigger keywords in a message. You can either require a user's message to start or end with one of your keywords exactly, or choose to trigger your reply every time a message contains your keywords anywhere.</div>
        <select id="ar-trigger-type" class="ar-select">
            <option value="contains">Message Contains a Keyword</option>
            <option value="starts_with">Message Starts With a Keyword</option>
            <option value="exact">Exact Match</option>
        </select>
    </div>

    <!-- Trigger Keywords -->
    <div class="ar-field">
        <div class="ar-field-label">Trigger Keywords</div>
        <div class="ar-field-desc">Set keywords individually you want your bot to send a reply to, based on the criteria above! You are currently able to set up <strong style="color:#f1f5f9">12</strong> keywords for each Autoresponder slot!</div>
        <div class="ar-keywords-box" id="ar-kw-box">
            <input type="text" class="ar-keyword-input" id="ar-kw-input" placeholder="Type a keyword and press Enter…" maxlength="100">
            <button type="button" class="ar-add-kw-btn" id="ar-kw-add-btn" onclick="arAddKeyword()">+</button>
        </div>
    </div>

    <!-- Autoresponder Message -->
    <div class="ar-field">
        <div class="ar-field-label">Autoresponder Message</div>
        <div class="ar-field-desc">This embed or plain text message will be sent as a reply to a user, if their message contains one of the keywords. All custom and default variables can be used in the fields below!</div>

        <div class="ar-embed-toggle-row">
            <span class="ar-embed-toggle-label">Message</span>
            <div style="display:flex;align-items:center;gap:8px">
                <span style="font-size:12px;color:#4f5f80">Embed</span>
                <label class="bh-toggle">
                    <input type="checkbox" id="ar-is-embed" checked>
                    <span class="bh-toggle__track"></span>
                    <span class="bh-toggle__thumb"></span>
                </label>
            </div>
        </div>

        <!-- Embed panel -->
        <div id="ar-embed-panel">
            <div class="ar-embed-panel">
                <div class="ar-embed-inner">
                    <div id="ar-embed-stripe" class="ar-embed-stripe" style="background:#ef4444" onclick="document.getElementById('ar-color-picker').click()"></div>
                    <div class="ar-embed-body">
                        <div class="ar-embed-row">
                            <div>
                                <div class="ar-embed-label">Author</div>
                                <input type="text" id="ar-embed-author" class="ar-embed-input" placeholder="Author name">
                            </div>
                            <div>
                                <div class="ar-embed-label">Thumbnail URL</div>
                                <input type="text" id="ar-embed-thumb" class="ar-embed-input" placeholder="https://...">
                            </div>
                        </div>
                        <div>
                            <div class="ar-embed-label">Title</div>
                            <input type="text" id="ar-embed-title" class="ar-embed-input" placeholder="Autoresponder Message" value="Autoresponder Message">
                        </div>
                        <div>
                            <div class="ar-embed-label">Description</div>
                            <textarea id="ar-embed-body" class="ar-embed-textarea" placeholder="Hey there {user}! …">Hey there {user}! Enjoy your time in the server, let our server staff team know if you have any questions or concerns!</textarea>
                        </div>
                        <div>
                            <div class="ar-embed-label">Image URL</div>
                            <input type="text" id="ar-embed-image" class="ar-embed-input" placeholder="https://...">
                        </div>
                        <div class="ar-embed-footer-row">
                            <div>
                                <div class="ar-embed-label">Color</div>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <span id="ar-color-swatch" class="ar-color-btn" style="background:#ef4444" onclick="document.getElementById('ar-color-picker').click()"></span>
                                    <input type="color" id="ar-color-picker" value="#ef4444" style="display:none">
                                    <input type="text" id="ar-color-hex" class="ar-embed-input" value="#ef4444" placeholder="#ef4444" style="width:90px;font-family:monospace">
                                </div>
                            </div>
                            <div>
                                <div class="ar-embed-label">Embed URL</div>
                                <input type="text" id="ar-embed-url" class="ar-embed-input" placeholder="https://...">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Plain text panel -->
        <div id="ar-plain-panel" style="display:none">
            <textarea id="ar-plain-text" style="width:100%;background:#151b2b;border:1px solid #2e3850;border-radius:8px;color:#c8d0e0;font-size:13px;padding:10px 12px;outline:none;resize:vertical;min-height:80px;box-sizing:border-box;font-family:inherit" placeholder="Your autoresponder reply…"></textarea>
        </div>
    </div>

    <!-- Channel Cooldown -->
    <div class="ar-field">
        <div class="ar-field-label">Channel Cooldown</div>
        <div class="ar-field-desc">To avoid users spamming keywords constantly, set up a channel-wide cooldown in seconds, that defines the minimal time-delay between autoresponder messages sent to the given channel.</div>
        <input type="number" id="ar-cooldown" class="ar-select" value="10" min="0" style="max-width:200px">
    </div>

    <!-- Mention User -->
    <div class="ar-field">
        <div class="ar-field-label">Mention User</div>
        <div class="ar-field-desc">Whether or not the bot's reply should mention the user who triggered the message based on the provided keywords. This will determine whether or no they hear a ping sound in the Discord client.</div>
        <select id="ar-mention-user" class="ar-select">
            <option value="1">Mention User</option>
            <option value="0">Do Not Mention</option>
        </select>
    </div>

    <!-- Channel Selection Type -->
    <div class="ar-field">
        <div class="ar-field-label">Channel Selection - Type</div>
        <div class="ar-field-desc">Choose how you want to select filtered channels for this autoresponder message. Selecting the <strong style="color:#f1f5f9">All channels except…</strong> choice will send your message to any channels your bot has access to, except for the channels you whitelist below. The <strong style="color:#f1f5f9">Selected channels…</strong> choice will only trigger your message in the channels you select below!</div>
        <select id="ar-ch-filter-type" class="ar-select">
            <option value="all_except">All channels except…</option>
            <option value="selected">Selected channels only</option>
        </select>
    </div>

    <!-- Channel Selection Channels -->
    <div class="ar-field">
        <div class="ar-field-label">Channel Selection - Channels</div>
        <div class="ar-field-desc">Select the channels you want to use to trigger the message based on the defined selection type above.</div>
        <div class="ar-tags-box" id="ar-ch-box">
            <button type="button" class="ar-add-btn" id="ar-ch-add-btn" title="Channel hinzufügen">+</button>
        </div>
    </div>

    <!-- Role Selection Type -->
    <div class="ar-field">
        <div class="ar-field-label">Role Selection - Type</div>
        <div class="ar-field-desc">Choose how you want to select filtered roles for triggering this autoresponder reply. Selecting the <strong style="color:#f1f5f9">All roles except…</strong> choice will send a reply for members with all roles, except for the roles you whitelist below. The <strong style="color:#f1f5f9">Selected roles…</strong> choice will only invoke your trigger for members that own one of the roles you select below!</div>
        <select id="ar-role-filter-type" class="ar-select">
            <option value="all_except">All roles except…</option>
            <option value="selected">Selected roles only</option>
        </select>
    </div>

    <!-- Role Selection Roles -->
    <div class="ar-field">
        <div class="ar-field-label">Role Selection - Roles</div>
        <div class="ar-field-desc">Select the roles you want users to have to trigger the reply based on your criteria above!</div>
        <div class="ar-tags-box" id="ar-role-box">
            <button type="button" class="ar-add-btn" id="ar-role-add-btn" title="Rolle hinzufügen">+</button>
        </div>
    </div>

    <button class="ar-submit-btn" id="ar-submit-btn" onclick="arSubmit()">Add</button>
</div>

<!-- ── Existing autoresponders ────────────────────────────────────────── -->
<?php if (!empty($autoresponders)): ?>
<div class="ar-section-label" style="margin-top:28px">EXISTING AUTORESPONDERS</div>
<?php endif; ?>
<div id="ar-list">
<?php foreach ($autoresponders as $ar):
    $kws = [];
    try { $kws = json_decode((string)($ar['keywords'] ?? ''), true) ?: []; } catch(Throwable) {}
    $kwPreview = implode(', ', array_slice($kws, 0, 4)) . (count($kws) > 4 ? ' …' : '');
    $typeLabel = match($ar['trigger_type'] ?? 'contains') {
        'starts_with' => 'Starts with',
        'exact'       => 'Exact match',
        default       => 'Contains',
    };
?>
<div class="ar-list-card" id="ar-row-<?= (int)$ar['id'] ?>">
    <div>
        <div class="ar-list-name"><?= $esc($kwPreview ?: '(no keywords)') ?></div>
        <div class="ar-list-meta">
            Type: <strong><?= $esc($typeLabel) ?></strong>
            &nbsp;·&nbsp; <?= (int)$ar['is_embed'] ? 'Embed' : 'Plain text' ?>
            &nbsp;·&nbsp; Cooldown: <?= (int)$ar['channel_cooldown'] ?>s
            <?php if (!(int)$ar['is_active']): ?>
                &nbsp;·&nbsp; <span style="color:#f87171">Disabled</span>
            <?php endif; ?>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
        <label class="bh-toggle" title="Enable / Disable">
            <input type="checkbox" <?= (int)$ar['is_active'] ? 'checked' : '' ?>
                onchange="arToggle(<?= (int)$ar['id'] ?>, this.checked)">
            <span class="bh-toggle__track"></span>
            <span class="bh-toggle__thumb"></span>
        </label>
        <button class="ar-list-del-btn" onclick="arDelete(<?= (int)$ar['id'] ?>)">Delete</button>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- ── Module: Commands ────────────────────────────────────────────────── -->
<div style="margin-top:32px">
    <div class="ar-module-label">MODULE</div>
    <div class="ar-module-title">Commands</div>
    <div class="ar-cmd-grid">
        <div class="ar-cmd-card">
            <div>
                <div class="ar-cmd-name">Add Command</div>
                <div class="ar-cmd-desc">This command will have access to all the variables and settings of this module.</div>
            </div>
            <button class="ar-cmd-add-btn">Add</button>
        </div>
    </div>
</div>

<!-- ── Module: Events ─────────────────────────────────────────────────── -->
<div style="margin-top:28px;margin-bottom:32px">
    <div class="ar-module-label">MODULE</div>
    <div class="ar-module-title">Events</div>
    <div class="ar-cmd-grid">
        <div class="ar-cmd-card">
            <div>
                <div class="ar-cmd-name">Add Event</div>
                <div class="ar-cmd-desc">This event will have access to all the variables and settings of this module.</div>
            </div>
            <button class="ar-cmd-add-btn">Add</button>
        </div>
    </div>
</div>
</div><!-- /bh-mod-body -->

<script>
(function () {
    const BOT_ID   = <?= (int)$botId ?>;
    const MAX_KW   = 12;
    let keywords   = [];
    let chItems    = [];
    let roleItems  = [];

    // ── Color sync ────────────────────────────────────────────────────────
    const colorPicker = document.getElementById('ar-color-picker');
    const colorHex    = document.getElementById('ar-color-hex');
    const colorSwatch = document.getElementById('ar-color-swatch');
    const embedStripe = document.getElementById('ar-embed-stripe');

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
    const isEmbedCb  = document.getElementById('ar-is-embed');
    const embedPanel = document.getElementById('ar-embed-panel');
    const plainPanel = document.getElementById('ar-plain-panel');
    isEmbedCb.addEventListener('change', () => {
        embedPanel.style.display = isEmbedCb.checked ? '' : 'none';
        plainPanel.style.display = isEmbedCb.checked ? 'none' : '';
    });

    // ── Keywords ──────────────────────────────────────────────────────────
    const kwInput = document.getElementById('ar-kw-input');
    kwInput.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); arAddKeyword(); }
    });

    window.arAddKeyword = function () {
        const val = kwInput.value.trim();
        if (!val || keywords.includes(val) || keywords.length >= MAX_KW) return;
        keywords.push(val);
        kwInput.value = '';
        renderKeywords();
    };

    function renderKeywords() {
        const box = document.getElementById('ar-kw-box');
        box.querySelectorAll('.ar-keyword-tag').forEach(t => t.remove());
        for (const kw of keywords) {
            const tag = document.createElement('span');
            tag.className = 'ar-keyword-tag';
            tag.innerHTML = `${escHtml(kw)}<button class="ar-keyword-rm" onclick="arRemoveKw('${escHtml(kw).replace(/'/g,"\\'")}')">×</button>`;
            box.insertBefore(tag, kwInput);
        }
    }

    window.arRemoveKw = function (kw) {
        keywords = keywords.filter(k => k !== kw);
        renderKeywords();
    };

    // ── Role / Channel pickers ────────────────────────────────────────────
    document.getElementById('ar-ch-add-btn').addEventListener('click', function (e) {
        e.stopPropagation();
        BhPerm.openPicker(this, BOT_ID, 'channels', chItems, (item) => {
            if (!chItems.find(i => i.id === item.id)) {
                chItems.push(item);
                renderTags('ar-ch-box', chItems, 'ar-ch-add-btn', (id) => {
                    chItems = chItems.filter(i => i.id !== id);
                    renderTags('ar-ch-box', chItems, 'ar-ch-add-btn', () => {});
                }, 'ar-tag--channel');
            }
        });
    });

    document.getElementById('ar-role-add-btn').addEventListener('click', function (e) {
        e.stopPropagation();
        BhPerm.openPicker(this, BOT_ID, 'roles', roleItems, (item) => {
            if (!roleItems.find(i => i.id === item.id)) {
                roleItems.push(item);
                renderTags('ar-role-box', roleItems, 'ar-role-add-btn', (id) => {
                    roleItems = roleItems.filter(i => i.id !== id);
                    renderTags('ar-role-box', roleItems, 'ar-role-add-btn', () => {});
                }, '');
            }
        });
    });

    function renderTags(boxId, items, addBtnId, onRemove, extraClass) {
        const box    = document.getElementById(boxId);
        const addBtn = document.getElementById(addBtnId);
        box.querySelectorAll('.ar-tag').forEach(t => t.remove());
        for (const item of items) {
            const tag = document.createElement('span');
            tag.className = 'ar-tag ' + (extraClass || '');
            tag.innerHTML = `${escHtml(item.name || item.id)}<button class="ar-tag-rm" data-id="${escHtml(item.id)}">×</button>`;
            tag.querySelector('.ar-tag-rm').addEventListener('click', () => onRemove(item.id));
            box.insertBefore(tag, addBtn);
        }
    }

    // ── Flash ─────────────────────────────────────────────────────────────
    function flash(msg, ok) {
        const el = document.getElementById('ar-flash');
        el.className = 'ar-flash ' + (ok ? 'ar-flash--ok' : 'ar-flash--err');
        el.textContent = msg;
        el.style.display = '';
        clearTimeout(el._t);
        el._t = setTimeout(() => { el.style.display = 'none'; }, 4000);
    }

    // ── Submit ────────────────────────────────────────────────────────────
    window.arSubmit = async function () {
        const btn = document.getElementById('ar-submit-btn');
        btn.disabled = true;

        const payload = {
            action:              'add',
            trigger_type:        document.getElementById('ar-trigger-type').value,
            keywords:            keywords,
            is_embed:            document.getElementById('ar-is-embed').checked,
            plain_text:          document.getElementById('ar-plain-text').value.trim(),
            embed_author:        document.getElementById('ar-embed-author').value.trim(),
            embed_thumbnail:     document.getElementById('ar-embed-thumb').value.trim(),
            embed_title:         document.getElementById('ar-embed-title').value.trim(),
            embed_body:          document.getElementById('ar-embed-body').value.trim(),
            embed_image:         document.getElementById('ar-embed-image').value.trim(),
            embed_color:         document.getElementById('ar-color-hex').value.trim(),
            embed_url:           document.getElementById('ar-embed-url').value.trim(),
            channel_cooldown:    parseInt(document.getElementById('ar-cooldown').value) || 10,
            mention_user:        document.getElementById('ar-mention-user').value === '1',
            channel_filter_type: document.getElementById('ar-ch-filter-type').value,
            filtered_channels:   chItems,
            role_filter_type:    document.getElementById('ar-role-filter-type').value,
            filtered_roles:      roleItems,
        };

        try {
            const res  = await fetch(location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const json = await res.json();
            if (json.ok) {
                flash('Autoresponder added!', true);
                appendRow(json.id, payload);
                // Reset
                keywords = []; chItems = []; roleItems = [];
                renderKeywords();
                renderTags('ar-ch-box',   chItems,   'ar-ch-add-btn',   () => {}, 'ar-tag--channel');
                renderTags('ar-role-box', roleItems, 'ar-role-add-btn', () => {}, '');
                document.getElementById('ar-embed-title').value = 'Autoresponder Message';
                document.getElementById('ar-embed-body').value  = '';
            } else {
                flash(json.error || 'Error saving.', false);
            }
        } catch (e) {
            flash('Network error.', false);
        }
        btn.disabled = false;
    };

    function appendRow(id, p) {
        const list = document.getElementById('ar-list');
        const typeLabel = { contains: 'Contains', starts_with: 'Starts with', exact: 'Exact match' }[p.trigger_type] || 'Contains';
        const kwPreview = p.keywords.slice(0, 4).join(', ') + (p.keywords.length > 4 ? ' …' : '');
        const div = document.createElement('div');
        div.className = 'ar-list-card';
        div.id = 'ar-row-' + id;
        div.innerHTML = `
            <div>
                <div class="ar-list-name">${escHtml(kwPreview || '(no keywords)')}</div>
                <div class="ar-list-meta">
                    Type: <strong>${escHtml(typeLabel)}</strong>
                    &nbsp;·&nbsp; ${p.is_embed ? 'Embed' : 'Plain text'}
                    &nbsp;·&nbsp; Cooldown: ${p.channel_cooldown}s
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
                <label class="bh-toggle">
                    <input type="checkbox" checked onchange="arToggle(${id}, this.checked)">
                    <span class="bh-toggle__track"></span>
                    <span class="bh-toggle__thumb"></span>
                </label>
                <button class="ar-list-del-btn" onclick="arDelete(${id})">Delete</button>
            </div>
        `;
        list.prepend(div);
    }

    window.arDelete = async function (id) {
        if (!confirm('Delete this autoresponder?')) return;
        try {
            const res  = await fetch(location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', id }),
            });
            const json = await res.json();
            if (json.ok) {
                const row = document.getElementById('ar-row-' + id);
                if (row) row.remove();
                flash('Deleted.', true);
            } else {
                flash(json.error || 'Error.', false);
            }
        } catch (e) { flash('Network error.', false); }
    };

    window.arToggle = async function (id, active) {
        try {
            await fetch(location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'toggle', id, is_active: active }),
            });
        } catch (_) {}
    };

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
})();
</script>
