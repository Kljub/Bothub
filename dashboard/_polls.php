<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/functions/db_functions/polls.php';
require_once dirname(__DIR__) . '/functions/db_functions/commands.php';
require_once dirname(__DIR__) . '/functions/custom_commands.php';
require_once dirname(__DIR__) . '/functions/module_toggle.php';

$pdo   = bh_get_pdo();
$botId = isset($currentBotId) && $currentBotId > 0 ? $currentBotId : (int)($_GET['bot_id'] ?? 0);

if ($botId <= 0) { ?>
<div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl p-5">
    <div class="text-sm text-rose-600 dark:text-rose-400">Bot nicht gefunden.</div>
</div>
<?php return; }

// ── Auto-migrate ──────────────────────────────────────────────────────────
try { bhpo_ensure_tables($pdo); } catch (Throwable) {}
try {
    $seeded = bhpo_seed_commands($pdo, $botId);
    if ($seeded > 0) { try { bh_notify_slash_sync($botId); } catch (Throwable) {} }
} catch (Throwable) {}

$defaultReactions = ['🇦','🇧','🇨','🇩','🇪','🇫','🇬','🇭','🇮','🇯'];

// ── Handle AJAX ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bh_mod_handle_ajax($pdo, $botId);
    while (ob_get_level() > 0) { ob_end_clean(); }
    $raw  = (string)file_get_contents('php://input');
    $data = json_decode($raw, true);
    header('Content-Type: application/json; charset=utf-8');

    if (($data['action'] ?? '') === 'save') {
        $encodeList = fn(array $arr): string => json_encode(
            array_values(array_filter($arr, fn($i) => is_array($i) && isset($i['id']))),
            JSON_UNESCAPED_UNICODE
        );

        $managerRoles       = $encodeList((array)($data['manager_roles']        ?? []));
        $whitelistChannels  = $encodeList((array)($data['whitelisted_channels'] ?? []));
        $blacklistRoles     = $encodeList((array)($data['blacklisted_roles']    ?? []));
        $singleChoice       = (isset($data['single_choice'])    && $data['single_choice'])    ? 1 : 0;
        $embedTitle         = mb_substr(trim((string)($data['embed_title']   ?? '🗳️ Poll - {poll.question}')), 0, 256);
        $embedFooter        = mb_substr(trim((string)($data['embed_footer']  ?? '')), 0, 512);
        $embedColor         = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($data['embed_color'] ?? ''))
                                ? (string)$data['embed_color'] : '#EE3636';
        $showPosterName     = (isset($data['show_poster_name']) && $data['show_poster_name']) ? 1 : 0;
        $choiceReactions    = json_encode(
            array_values(array_filter(array_map('strval', (array)($data['choice_reactions'] ?? $defaultReactions)), fn($s) => $s !== '')),
            JSON_UNESCAPED_UNICODE
        );
        $cmdCreate          = (isset($data['cmd_poll_create'])   && $data['cmd_poll_create'])   ? 1 : 0;
        $cmdFind            = (isset($data['cmd_poll_find'])     && $data['cmd_poll_find'])     ? 1 : 0;
        $cmdList            = (isset($data['cmd_poll_list'])     && $data['cmd_poll_list'])     ? 1 : 0;
        $cmdDelete          = (isset($data['cmd_poll_delete'])   && $data['cmd_poll_delete'])   ? 1 : 0;
        $evtHandler         = (isset($data['evt_polls_handler']) && $data['evt_polls_handler']) ? 1 : 0;

        try {
            bhpo_save_settings($pdo, $botId, [
                'manager_roles'        => $managerRoles,
                'whitelisted_channels' => $whitelistChannels,
                'blacklisted_roles'    => $blacklistRoles,
                'single_choice'        => $singleChoice,
                'embed_title'          => $embedTitle,
                'embed_footer'         => $embedFooter,
                'embed_color'          => $embedColor,
                'show_poster_name'     => $showPosterName,
                'choice_reactions'     => $choiceReactions,
                'evt_polls_handler'    => $evtHandler,
            ]);
            bhcmd_set_module_enabled($pdo, $botId, 'poll-create', $cmdCreate);
            bhcmd_set_module_enabled($pdo, $botId, 'poll-find',   $cmdFind);
            bhcmd_set_module_enabled($pdo, $botId, 'poll-list',   $cmdList);
            bhcmd_set_module_enabled($pdo, $botId, 'poll-delete', $cmdDelete);
            try { bh_notify_slash_sync($botId); } catch (Throwable) {}
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
$s = [];
try { $s = bhpo_load_settings($pdo, $botId); } catch (Throwable) {}

$parseList = fn($raw) => is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];

$managerRoles      = $parseList($s['manager_roles']        ?? '');
$whitelistChannels = $parseList($s['whitelisted_channels'] ?? '');
$blacklistRoles    = $parseList($s['blacklisted_roles']    ?? '');
$singleChoice      = (int)($s['single_choice']     ?? 0);
$embedTitle        = (string)($s['embed_title']    ?? '🗳️ Poll - {poll.question}');
$embedFooter       = (string)($s['embed_footer']   ?? 'Participate in the poll by reacting with one of the options specified below. We thank you for your feedback!');
$embedColor        = (string)($s['embed_color']    ?? '#EE3636');
$showPosterName    = (int)($s['show_poster_name']  ?? 1);
$choiceReactions   = $parseList($s['choice_reactions'] ?? '');
if (empty($choiceReactions)) $choiceReactions = $defaultReactions;
try {
    $cmdCreate = bhcmd_is_enabled($pdo, $botId, 'poll-create');
    $cmdFind   = bhcmd_is_enabled($pdo, $botId, 'poll-find');
    $cmdList   = bhcmd_is_enabled($pdo, $botId, 'poll-list');
    $cmdDelete = bhcmd_is_enabled($pdo, $botId, 'poll-delete');
} catch (Throwable) {
    $cmdCreate = $cmdFind = $cmdList = $cmdDelete = 1;
}
$evtHandler        = (int)($s['evt_polls_handler'] ?? 1);

$esc = fn(string $v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$modEnabled = bh_mod_is_enabled($pdo, $botId, 'module:polls');
?>

<?= bh_mod_render($modEnabled, $botId, 'module:polls', 'Polls', 'Poll-Funktion und alle Poll-Commands für diesen Bot ein- oder ausschalten.') ?>
<div id="bh-mod-body">
<div class="px-1">

    <!-- ── Permission Settings ──────────────────────────────────── -->
    <div class="mb-2">
        <div class="bh-section-label">Poll</div>
        <div class="bh-section-title">Permission Settings</div>
    </div>

    <div id="bh-alert" class="bh-alert" style="display:none;"></div>

    <div class="bh-card">
        <div class="bh-card-title">Permission Settings</div>

        <!-- Manager Roles -->
        <div class="bh-field">
            <div>
                <div class="bh-label">Manager Roles</div>
                <div class="bh-hint">Define the required roles to use the poll-management commands of the module.</div>
            </div>
            <div class="polls-tags-box" id="polls-manager-roles-box">
                <button type="button" class="polls-add-btn" id="polls-add-manager-role" title="Rolle hinzufügen">+</button>
            </div>
        </div>

        <!-- Whitelisted Channels -->
        <div class="bh-field">
            <div>
                <div class="bh-label">Whitelisted Channels</div>
                <div class="bh-hint">Only users that attempt to create polls will be able to post polls in those channels.</div>
            </div>
            <div class="polls-tags-box" id="polls-whitelist-channels-box">
                <button type="button" class="polls-add-btn" id="polls-add-whitelist-channel" title="Channel hinzufügen">+</button>
            </div>
        </div>

        <!-- Blacklisted Roles -->
        <div class="bh-field">
            <div>
                <div class="bh-label">Blacklisted Roles</div>
                <div class="bh-hint">Users who have one of those roles will not be allowed to vote in polls.</div>
            </div>
            <div class="polls-tags-box" id="polls-blacklist-roles-box">
                <button type="button" class="polls-add-btn" id="polls-add-blacklist-role" title="Rolle hinzufügen">+</button>
            </div>
        </div>

        <!-- Single Choice -->
        <div class="bh-field">
            <div>
                <div class="bh-label">Single Choice</div>
                <div class="bh-hint">When enabled, users will only be able to choose a single option of the poll. This restriction is reset once a new poll is posted.</div>
            </div>
            <div class="bh-toggle-row">
                <label class="toggle">
                    <input type="checkbox" id="polls-single-choice" <?= $singleChoice ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>
        </div>
    </div>

    <!-- ── Poll Settings ─────────────────────────────────────────── -->
    <div class="mb-2 mt-6">
        <div class="bh-section-label">Poll</div>
        <div class="bh-section-title">Poll Settings</div>
    </div>

    <div class="bh-card">
        <div class="bh-card-title">Poll Settings</div>

        <!-- Embed Title -->
        <div class="bh-field">
            <div>
                <div class="bh-label">Embed Title</div>
                <div class="bh-hint">The title of the main poll messages that will be posted. The <code style="color:#f87171;background:rgba(239,68,68,.1);padding:1px 5px;border-radius:4px;">{poll.question}</code> variable can be used to return the configured question.</div>
            </div>
            <div>
                <div style="font-size:10px;color:#4f5f80;margin-bottom:4px;">Embed Title</div>
                <input type="text" class="bh-input" id="polls-embed-title"
                       value="<?= $esc($embedTitle) ?>" maxlength="256"
                       placeholder="🗳️ Poll - {poll.question}">
            </div>
        </div>

        <!-- Embed Footer -->
        <div class="bh-field">
            <div>
                <div class="bh-label">Embed Footer</div>
                <div class="bh-hint">The footer and closing rules of the main poll messages that will be posted.</div>
            </div>
            <div>
                <div style="font-size:10px;color:#4f5f80;margin-bottom:4px;">Embed Footer</div>
                <textarea class="bh-input" id="polls-embed-footer" maxlength="512" rows="3"><?= $esc($embedFooter) ?></textarea>
            </div>
        </div>

        <!-- Embed Color -->
        <div class="bh-field">
            <div>
                <div class="bh-label">Embed Color</div>
                <div class="bh-hint">The color of the poll embed messages that will be posted, and the follow-up option reaction comments.</div>
            </div>
            <div class="polls-color-wrap">
                <div class="polls-color-swatch" id="polls-color-swatch" style="background:<?= $esc($embedColor) ?>;"></div>
                <input type="color" id="polls-color-picker" value="<?= $esc($embedColor) ?>" style="display:none;">
                <input type="text" class="polls-color-input" id="polls-color-text"
                       value="<?= $esc($embedColor) ?>" maxlength="7" placeholder="#EE3636">
            </div>
        </div>

        <!-- Show Poster's Name -->
        <div class="bh-field">
            <div>
                <div class="bh-label">Show Poster's Name</div>
                <div class="bh-hint">Choose whether or not to show the name of the user who posted the poll in the main message.</div>
            </div>
            <div class="bh-toggle-row">
                <label class="toggle">
                    <input type="checkbox" id="polls-show-poster" <?= $showPosterName ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>
        </div>

        <!-- Choice Reactions -->
        <div class="bh-field">
            <div>
                <div class="bh-label">Choice Reactions</div>
                <div class="bh-hint">
                    These emojis will be used as reaction options on the poll, in the exact order as you add them.
                    It is <strong style="color:#f1f5f9;">not recommended</strong> to change this, unless you know exactly what you are doing!
                    You can use <em>unicode</em> emojis supported in Discord.
                </div>
            </div>
            <div class="polls-emoji-list" id="polls-emoji-list">
                <!-- rendered by JS -->
                <button type="button" class="polls-emoji-add-btn" id="polls-emoji-add-btn" title="Emoji hinzufügen">+</button>
            </div>
        </div>
    </div>

    <!-- ── Commands ──────────────────────────────────────────────── -->
    <div class="mb-2 mt-6">
        <div class="bh-section-label">Module</div>
        <div class="bh-section-title">Commands</div>
    </div>

    <div class="bh-cmd-grid">
        <div class="bh-cmd-card">
            <div>
                <div class="bh-cmd-name">/poll-create</div>
                <div class="bh-cmd-desc">Create a new poll and configure a question and choices.</div>
            </div>
            <label class="toggle" style="flex-shrink:0;">
                <input type="checkbox" id="polls-cmd-create" <?= $cmdCreate ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
            </label>
        </div>
        <div class="bh-cmd-card">
            <div>
                <div class="bh-cmd-name">/poll-find</div>
                <div class="bh-cmd-desc">Find a poll by looking in the final results.</div>
            </div>
            <label class="toggle" style="flex-shrink:0;">
                <input type="checkbox" id="polls-cmd-find" <?= $cmdFind ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
            </label>
        </div>
        <div class="bh-cmd-card">
            <div>
                <div class="bh-cmd-name">/poll-list</div>
                <div class="bh-cmd-desc">Show all currently active polls in this server.</div>
            </div>
            <label class="toggle" style="flex-shrink:0;">
                <input type="checkbox" id="polls-cmd-list" <?= $cmdList ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
            </label>
        </div>
        <div class="bh-cmd-card">
            <div>
                <div class="bh-cmd-name">/poll-delete</div>
                <div class="bh-cmd-desc">Delete a poll and its embed message. Requires Manage Server or a manager role.</div>
            </div>
            <label class="toggle" style="flex-shrink:0;">
                <input type="checkbox" id="polls-cmd-delete" <?= $cmdDelete ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
            </label>
        </div>
        <div class="bh-cmd-card polls-cmd-card--add">
            <div>
                <div class="bh-cmd-name">Add Command</div>
                <div class="bh-cmd-desc">This command will have access to all the variables and settings of this module.</div>
            </div>
            <button type="button" class="bh-btn bh-btn--primary">Add</button>
        </div>
    </div>

    <!-- ── Events ────────────────────────────────────────────────── -->
    <div class="mb-2 mt-6">
        <div class="bh-section-label">Module</div>
        <div class="bh-section-title">Events</div>
    </div>

    <div class="bh-cmd-grid">
        <div class="bh-cmd-card">
            <div>
                <div class="bh-cmd-name">Polls Handler</div>
                <div class="bh-cmd-desc">When a reaction is added to a message.</div>
            </div>
            <label class="toggle" style="flex-shrink:0;">
                <input type="checkbox" id="polls-evt-handler" <?= $evtHandler ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
            </label>
        </div>
        <div class="bh-cmd-card polls-cmd-card--add">
            <div>
                <div class="bh-cmd-name">Add Event</div>
                <div class="bh-cmd-desc">This event will have access to all the variables and settings of this module.</div>
            </div>
            <button type="button" class="bh-btn bh-btn--primary">Add</button>
        </div>
    </div>

    <!-- ── Save ──────────────────────────────────────────────────── -->
    <div class="polls-save-bar">
        <button type="button" class="bh-btn bh-btn--primary" id="bh-btn bh-btn--primary">Save</button>
    </div>

</div>
</div><!-- /bh-mod-body -->

<script>
(function () {
    'use strict';

    var BOT_ID = <?= (int)$botId ?>;

    // ── Initial state from PHP ─────────────────────────────────────────────
    var state = {
        managerRoles:       <?= json_encode($managerRoles,      JSON_UNESCAPED_UNICODE) ?>,
        whitelistChannels:  <?= json_encode($whitelistChannels, JSON_UNESCAPED_UNICODE) ?>,
        blacklistRoles:     <?= json_encode($blacklistRoles,    JSON_UNESCAPED_UNICODE) ?>,
        choiceReactions:    <?= json_encode($choiceReactions,   JSON_UNESCAPED_UNICODE) ?>,
    };

    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Generic role/channel tag box ───────────────────────────────────────
    function renderTagBox(boxId, list, addBtnId, isChannel) {
        var box    = document.getElementById(boxId);
        var addBtn = document.getElementById(addBtnId);
        if (!box) return;

        Array.from(box.children).forEach(function (ch) { if (ch !== addBtn) ch.remove(); });

        list.forEach(function (item, idx) {
            var tag = document.createElement('span');
            tag.className = 'bh-tag' + (isChannel ? ' polls-tag--channel' : '');
            tag.innerHTML = esc(item.name || item.id)
                + '<button type="button" class="bh-tag-rm" title="Entfernen">×</button>';
            tag.querySelector('.bh-tag-rm').addEventListener('click', function () {
                list.splice(idx, 1);
                renderTagBox(boxId, list, addBtnId, isChannel);
            });
            box.insertBefore(tag, addBtn);
        });
    }

    function initTagBox(boxId, list, addBtnId, type) {
        renderTagBox(boxId, list, addBtnId, type === 'channels');
        var addBtn = document.getElementById(addBtnId);
        if (!addBtn) return;
        addBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (typeof BhPerm === 'undefined') return;
            BhPerm.openPicker(addBtn, BOT_ID, type, list, function (item) {
                var id = item.id || item.key;
                if (list.find(function (i) { return (i.id || i.key) === id; })) return;
                list.push({ id: item.id, name: item.name });
                renderTagBox(boxId, list, addBtnId, type === 'channels');
            });
        });
    }

    initTagBox('polls-manager-roles-box',    state.managerRoles,      'polls-add-manager-role',      'roles');
    initTagBox('polls-whitelist-channels-box', state.whitelistChannels, 'polls-add-whitelist-channel', 'channels');
    initTagBox('polls-blacklist-roles-box',   state.blacklistRoles,    'polls-add-blacklist-role',    'roles');

    // ── Emoji reactions ────────────────────────────────────────────────────
    function renderEmojiList() {
        var list   = document.getElementById('polls-emoji-list');
        var addBtn = document.getElementById('polls-emoji-add-btn');
        if (!list) return;

        Array.from(list.children).forEach(function (ch) { if (ch !== addBtn) ch.remove(); });

        state.choiceReactions.forEach(function (emoji, idx) {
            var chip = document.createElement('span');
            chip.className = 'bh-tag';
            chip.innerHTML = esc(emoji)
                + '<button type="button" class="bh-tag-rm" title="Entfernen">×</button>';
            chip.querySelector('.bh-tag-rm').addEventListener('click', function () {
                state.choiceReactions.splice(idx, 1);
                renderEmojiList();
            });
            list.insertBefore(chip, addBtn);
        });
    }

    renderEmojiList();

    document.getElementById('polls-emoji-add-btn').addEventListener('click', function () {
        var emoji = prompt('Emoji eingeben (z.B. 🔥 oder einen Unicode-Emoji):');
        if (!emoji || !emoji.trim()) return;
        state.choiceReactions.push(emoji.trim());
        renderEmojiList();
    });

    // ── Color picker ──────────────────────────────────────────────────────
    var swatch  = document.getElementById('polls-color-swatch');
    var picker  = document.getElementById('polls-color-picker');
    var textInp = document.getElementById('polls-color-text');

    swatch.addEventListener('click', function () { picker.click(); });
    picker.addEventListener('input', function () {
        swatch.style.background = picker.value;
        textInp.value = picker.value;
    });
    textInp.addEventListener('input', function () {
        var v = textInp.value.trim();
        if (/^#[0-9a-fA-F]{6}$/.test(v)) {
            swatch.style.background = v;
            picker.value = v;
        }
    });

    // ── Save ──────────────────────────────────────────────────────────────
    document.getElementById('bh-btn bh-btn--primary').addEventListener('click', async function () {
        var btn = this;
        btn.disabled = true;
        btn.textContent = '…';

        var payload = {
            action:              'save',
            manager_roles:       state.managerRoles,
            whitelisted_channels: state.whitelistChannels,
            blacklisted_roles:   state.blacklistRoles,
            single_choice:       document.getElementById('polls-single-choice').checked,
            embed_title:         document.getElementById('polls-embed-title').value,
            embed_footer:        document.getElementById('polls-embed-footer').value,
            embed_color:         document.getElementById('polls-color-text').value || '#EE3636',
            show_poster_name:    document.getElementById('polls-show-poster').checked,
            choice_reactions:    state.choiceReactions,
            cmd_poll_create:     document.getElementById('polls-cmd-create').checked,
            cmd_poll_find:       document.getElementById('polls-cmd-find').checked,
            cmd_poll_list:       document.getElementById('polls-cmd-list').checked,
            cmd_poll_delete:     document.getElementById('polls-cmd-delete').checked,
            evt_polls_handler:   document.getElementById('polls-evt-handler').checked,
        };

        try {
            var res  = await fetch(window.location.href, {
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
        var el = document.getElementById('bh-alert');
        el.className = 'bh-alert bh-alert--' + type;
        el.textContent = msg;
        el.style.display = 'block';
        setTimeout(function () { el.style.display = 'none'; }, 3500);
    }

}());
</script>
