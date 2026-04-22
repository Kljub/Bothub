<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/functions/db_functions/auto_react.php';

$pdo   = bh_get_pdo();
$botId = isset($currentBotId) && $currentBotId > 0 ? $currentBotId : (int)($_GET['bot_id'] ?? 0);

if ($botId <= 0) { ?><div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl p-5"><div class="text-sm text-rose-600 dark:text-rose-400">Bot nicht gefunden.</div></div><?php return; }

try { bharc_ensure_table($pdo); } catch (Throwable) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    $raw  = (string)file_get_contents('php://input');
    $data = json_decode($raw, true);
    header('Content-Type: application/json; charset=utf-8');

    if (($data['action'] ?? '') === 'save') {
        $encodeItems = fn(array $arr): string => json_encode(
            array_values(array_filter($arr, fn($i) => is_array($i) && isset($i['id']))),
            JSON_UNESCAPED_UNICODE
        );
        $enabledCh   = $encodeItems((array)($data['enabled_channels'] ?? []));
        $reactionEmj = json_encode(
            array_values(array_slice(
                array_filter(array_map('strval', (array)($data['reaction_emojis'] ?? [])), fn($e) => $e !== ''),
                0, 10
            )),
            JSON_UNESCAPED_UNICODE
        );
        $ignoreEmb  = (isset($data['ignore_embeds']) && $data['ignore_embeds']) ? 1 : 0;
        $allowedR   = $encodeItems((array)($data['allowed_roles'] ?? []));
        $checkWords = json_encode(
            array_values(array_filter(array_map('strval', (array)($data['check_words'] ?? [])), fn($w) => $w !== '')),
            JSON_UNESCAPED_UNICODE
        );
        $evtHandler = (isset($data['evt_handler']) && $data['evt_handler']) ? 1 : 0;

        try {
            bharc_save($pdo, $botId, [
                'enabled_channels' => $enabledCh,
                'reaction_emojis'  => $reactionEmj,
                'ignore_embeds'    => $ignoreEmb,
                'allowed_roles'    => $allowedR,
                'check_words'      => $checkWords,
                'evt_handler'      => $evtHandler,
            ]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    echo json_encode(['ok' => false, 'error' => 'Unknown action']); exit;
}

$s = [];
try { $s = bharc_load($pdo, $botId); } catch (Throwable) {}

$parseList = fn($raw) => is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
$enabledCh    = $parseList($s['enabled_channels'] ?? '');
$reactionEmjs = $parseList($s['reaction_emojis']  ?? '');
$ignoreEmb    = (int)($s['ignore_embeds'] ?? 1);
$allowedRoles = $parseList($s['allowed_roles']  ?? '');
$checkWords   = $parseList($s['check_words']    ?? '');
$evtHandler   = (int)($s['evt_handler'] ?? 1);
?>

<div class="bh-section-label">AUTO-REACT</div>
<div class="bh-section-title">Basic Setup</div>

<div id="bh-alert" style="display:none"></div>

<!-- Basic Setup -->
<div class="bh-card">
    <div class="bh-card-title">Basic Setup</div>

    <!-- Enabled Channels -->
    <div class="bh-field">
        <div class="bh-label">Enabled Channels</div>
        <div class="bh-hint">The channels where reactions will be added to new messages.</div>
        <div class="arc-tags-box" id="arc-ch-box">
            <button type="button" class="arc-add-btn" id="arc-ch-add-btn">+</button>
        </div>
    </div>

    <!-- Reaction Emojis -->
    <div class="bh-field">
        <div class="bh-label">Reaction Emojis</div>
        <div class="bh-hint">Choose up to <strong style="color:#f1f5f9">10</strong> <span style="color:#ef4444">default unicode</span> or <span style="color:#ef4444">custom</span> emojis the bot should react to new messages with!</div>
        <div class="arc-tags-box" id="arc-emoji-box" style="min-height:48px">
            <?php foreach ($reactionEmjs as $e): ?>
            <span class="bh-tag">
                <?= htmlspecialchars((string)$e, ENT_QUOTES, 'UTF-8') ?>
                <button class="arc-emoji-rm" onclick="arcRemoveEmoji(this,'<?= htmlspecialchars(addslashes((string)$e), ENT_QUOTES, 'UTF-8') ?>')">×</button>
            </span>
            <?php endforeach; ?>
            <button type="button" class="arc-add-btn" id="arc-emoji-add-btn" onclick="arcAddEmoji()">+</button>
        </div>
    </div>
</div>

<!-- Condition Setup -->
<div class="bh-section-label" style="margin-top:28px">AUTO-ROLE CREATE</div>
<div class="bh-section-title">Condition Setup</div>

<div class="bh-card">
    <!-- Ignore Embeds -->
    <div class="bh-toggle-row">
        <div>
            <div class="bh-toggle-row__title">Ignore Embeds</div>
            <div class="bh-toggle-row__desc">When enabled, reactions will not be added to messages without a text content.</div>
        </div>
        <label class="bh-toggle" style="margin-left:16px">
            <input class="bh-toggle-input" type="checkbox" id="arc-ignore-embeds" <?= $ignoreEmb ? 'checked' : '' ?>>
            <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
        </label>
    </div>

    <!-- Allowed Roles -->
    <div class="bh-field" style="padding-top:16px">
        <div class="bh-label">Allowed Roles</div>
        <div class="bh-hint">When you add at least one role, only messages whose author has a specified role will get the reactions added.</div>
        <div class="arc-tags-box" id="arc-role-box">
            <?php foreach ($allowedRoles as $role):
                if (!is_array($role) || !isset($role['id'])) continue; ?>
            <span class="bh-tag" data-id="<?= htmlspecialchars($role['id'], ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($role['name'] ?? $role['id'], ENT_QUOTES, 'UTF-8') ?>
                <button class="bh-tag-rm" onclick="this.closest('.bh-tag').remove()">×</button>
            </span>
            <?php endforeach; ?>
            <button type="button" class="arc-add-btn" id="arc-role-add-btn">+</button>
        </div>
    </div>

    <!-- Check for Words -->
    <div class="bh-field">
        <div class="bh-label">Check for Words</div>
        <div class="bh-hint">When you add at least one word, reactions will only be added to messages that contain a specified word.</div>
        <div class="arc-tags-box" id="arc-words-box">
            <?php foreach ($checkWords as $w): ?>
            <span class="bh-tag" style="background:rgba(251,191,36,.12);color:#fbbf24">
                <?= htmlspecialchars((string)$w, ENT_QUOTES, 'UTF-8') ?>
                <button class="bh-tag-rm" onclick="this.closest('.bh-tag').remove()">×</button>
            </span>
            <?php endforeach; ?>
            <button type="button" class="arc-add-btn" id="arc-word-add-btn" onclick="arcAddWord()">+</button>
        </div>
    </div>
</div>

<div class="arc-save-bar">
    <button class="bh-btn bh-btn--primary" id="bh-btn bh-btn--primary" onclick="arcSave()">Save Changes</button>
</div>

<!-- Commands -->
<div style="margin-top:32px">
    <div class="bh-section-label">MODULE</div>
    <div class="arc-module-title">Commands</div>
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
    <div class="arc-module-title">Events</div>
    <div class="bh-cmd-grid">
        <div class="bh-cmd-card">
            <div>
                <div class="bh-cmd-name">Auto-React</div>
                <div class="bh-cmd-desc">When a new message is sent</div>
            </div>
            <label class="bh-toggle" style="margin-left:16px">
                <input class="bh-toggle-input" type="checkbox" id="arc-evt-handler" <?= $evtHandler ? 'checked' : '' ?>>
                <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
            </label>
        </div>
        <div class="bh-cmd-card">
            <div><div class="bh-cmd-name">Add Event</div><div class="bh-cmd-desc">This event will have access to all the variables and settings of this module.</div></div>
            <button class="bh-btn bh-btn--primary">Add</button>
        </div>
    </div>
</div>

<script>
(function () {
    const BOT_ID = <?= (int)$botId ?>;
    let chItems   = <?= json_encode($enabledCh,    JSON_UNESCAPED_UNICODE) ?>;
    let roleItems = <?= json_encode($allowedRoles,  JSON_UNESCAPED_UNICODE) ?>;

    // Render initial channel tags
    renderChTags();
    renderRoleTags();

    function renderChTags() {
        const box = document.getElementById('arc-ch-box');
        box.querySelectorAll('.bh-tag').forEach(t => t.remove());
        const btn = document.getElementById('arc-ch-add-btn');
        for (const item of chItems) {
            const tag = document.createElement('span');
            tag.className = 'bh-tag arc-tag--channel';
            tag.dataset.id = item.id;
            tag.innerHTML = `${esc(item.name||item.id)}<button class="bh-tag-rm">×</button>`;
            tag.querySelector('.bh-tag-rm').onclick = () => { chItems = chItems.filter(i=>i.id!==item.id); renderChTags(); };
            box.insertBefore(tag, btn);
        }
    }

    function renderRoleTags() {
        const box = document.getElementById('arc-role-box');
        box.querySelectorAll('.bh-tag').forEach(t => t.remove());
        const btn = document.getElementById('arc-role-add-btn');
        for (const item of roleItems) {
            if (!item || !item.id) continue;
            const tag = document.createElement('span');
            tag.className = 'bh-tag';
            tag.dataset.id = item.id;
            tag.innerHTML = `${esc(item.name||item.id)}<button class="bh-tag-rm">×</button>`;
            tag.querySelector('.bh-tag-rm').onclick = () => { roleItems = roleItems.filter(i=>i.id!==item.id); renderRoleTags(); };
            box.insertBefore(tag, btn);
        }
    }

    document.getElementById('arc-ch-add-btn').addEventListener('click', function (e) {
        e.stopPropagation();
        BhPerm.openPicker(this, BOT_ID, 'channels', chItems, (item) => {
            if (!chItems.find(i=>i.id===item.id)) { chItems.push(item); renderChTags(); }
        });
    });

    document.getElementById('arc-role-add-btn').addEventListener('click', function (e) {
        e.stopPropagation();
        BhPerm.openPicker(this, BOT_ID, 'roles', roleItems, (item) => {
            if (!roleItems.find(i=>i.id===item.id)) { roleItems.push(item); renderRoleTags(); }
        });
    });

    window.arcRemoveEmoji = function (btn, emoji) {
        btn.closest('.bh-tag')?.remove();
    };

    window.arcAddEmoji = function () {
        const box = document.getElementById('arc-emoji-box');
        if (box.querySelectorAll('.bh-tag').length >= 10) { flash('Max 10 emojis.', false); return; }
        const emoji = prompt('Enter emoji (unicode or custom emoji name):');
        if (!emoji?.trim()) return;
        const chip = document.createElement('span');
        chip.className = 'bh-tag';
        chip.innerHTML = `${esc(emoji.trim())}<button class="arc-emoji-rm" onclick="arcRemoveEmoji(this,'')">×</button>`;
        box.insertBefore(chip, document.getElementById('arc-emoji-add-btn'));
    };

    window.arcAddWord = function () {
        const box = document.getElementById('arc-words-box');
        const word = prompt('Enter word to check for:');
        if (!word?.trim()) return;
        const tag = document.createElement('span');
        tag.className = 'bh-tag';
        tag.style.cssText = 'background:rgba(251,191,36,.12);color:#fbbf24';
        tag.innerHTML = `${esc(word.trim())}<button class="bh-tag-rm" onclick="this.closest('.bh-tag').remove()">×</button>`;
        box.insertBefore(tag, document.getElementById('arc-word-add-btn'));
    };

    function collectEmojis() {
        return [...document.getElementById('arc-emoji-box').querySelectorAll('.bh-tag')]
            .map(c => c.childNodes[0]?.textContent?.trim()).filter(Boolean);
    }

    function collectWords() {
        return [...document.getElementById('arc-words-box').querySelectorAll('.bh-tag')]
            .map(t => t.childNodes[0]?.textContent?.trim()).filter(Boolean);
    }

    window.arcSave = async function () {
        const btn = document.getElementById('bh-btn bh-btn--primary');
        btn.disabled = true;
        try {
            const res = await fetch(location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action:           'save',
                    enabled_channels: chItems,
                    reaction_emojis:  collectEmojis(),
                    ignore_embeds:    document.getElementById('arc-ignore-embeds').checked,
                    allowed_roles:    roleItems,
                    check_words:      collectWords(),
                    evt_handler:      document.getElementById('arc-evt-handler').checked,
                }),
            });
            const json = await res.json();
            flash(json.ok ? 'Saved!' : (json.error || 'Error'), json.ok);
        } catch (e) { flash('Network error.', false); }
        btn.disabled = false;
    };

    function flash(msg, ok) {
        const el = document.getElementById('bh-alert');
        el.className = 'bh-alert ' + (ok ? 'bh-alert--ok' : 'bh-alert--err');
        el.textContent = msg;
        el.style.display = '';
        clearTimeout(el._t);
        el._t = setTimeout(() => el.style.display = 'none', 4000);
    }

    function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
})();
</script>
