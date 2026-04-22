<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/functions/db_functions/sticky_messages.php';
require_once dirname(__DIR__) . '/functions/db_functions/commands.php';

$pdo   = bh_get_pdo();
$botId = isset($currentBotId) && $currentBotId > 0 ? $currentBotId : (int)($_GET['bot_id'] ?? 0);

if ($botId <= 0) { ?><div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl p-5"><div class="text-sm text-rose-600 dark:text-rose-400">Bot nicht gefunden.</div></div><?php return; }

try { bhsm_ensure_tables($pdo); } catch (Throwable) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    $raw  = (string)file_get_contents('php://input');
    $data = json_decode($raw, true);
    header('Content-Type: application/json; charset=utf-8');

    if (($data['action'] ?? '') === 'save') {
        $managerRole  = trim((string)($data['manager_role_id'] ?? ''));
        $isEmbed      = (isset($data['is_embed']) && $data['is_embed']) ? 1 : 0;
        $plainText    = trim((string)($data['plain_text']     ?? ''));
        $author       = mb_substr(trim((string)($data['embed_author']    ?? '')), 0, 256);
        $thumb        = mb_substr(trim((string)($data['embed_thumbnail'] ?? '')), 0, 512);
        $title        = mb_substr(trim((string)($data['embed_title']     ?? 'Sticky Messages')), 0, 256);
        $body         = trim((string)($data['embed_body'] ?? ''));
        $image        = mb_substr(trim((string)($data['embed_image'] ?? '')), 0, 512);
        $color        = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($data['embed_color'] ?? ''))
                            ? (string)$data['embed_color'] : '#f48342';
        $embedUrl     = mb_substr(trim((string)($data['embed_url']    ?? '')), 0, 512);
        $footer       = mb_substr(trim((string)($data['embed_footer'] ?? 'Sticky messages module')), 0, 512);
        $repostCount  = max(1, (int)($data['repost_count'] ?? 10));
        $showAuthor   = (isset($data['show_author'])   && $data['show_author'])   ? 1 : 0;
        $addReaction  = (isset($data['add_reaction'])  && $data['add_reaction'])  ? 1 : 0;
        $reactionEmoji = mb_substr(trim((string)($data['reaction_emoji'] ?? '👍')), 0, 128);
        $cmdSticky    = (isset($data['cmd_sticky_post']) && $data['cmd_sticky_post']) ? 1 : 0;
        $evtHandler   = (isset($data['evt_handler'])     && $data['evt_handler'])     ? 1 : 0;

        try {
            bhsm_save($pdo, $botId, [
                'manager_role' => $managerRole, 'is_embed' => $isEmbed,
                'plain_text' => $plainText, 'author' => $author, 'thumb' => $thumb,
                'title' => $title, 'body' => $body, 'image' => $image, 'color' => $color,
                'embed_url' => $embedUrl, 'footer' => $footer, 'repost_count' => $repostCount,
                'show_author' => $showAuthor, 'add_reaction' => $addReaction,
                'reaction_emoji' => $reactionEmoji, 'evt_handler' => $evtHandler,
            ]);
            bhcmd_set_module_enabled($pdo, $botId, 'sticky-post', $cmdSticky);
            require_once dirname(__DIR__) . '/functions/custom_commands.php';
            bh_notify_slash_sync($botId);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    echo json_encode(['ok' => false, 'error' => 'Unknown action']); exit;
}

$s = [];
try { $s = bhsm_load($pdo, $botId); } catch (Throwable) {}

$managerRoleId = (string)($s['manager_role_id'] ?? '');
$isEmbed       = (int)($s['is_embed']       ?? 1);
$plainText     = (string)($s['plain_text']   ?? '');
$embedAuthor   = (string)($s['embed_author'] ?? '');
$embedThumb    = (string)($s['embed_thumbnail'] ?? '');
$embedTitle    = (string)($s['embed_title']  ?? 'Sticky Messages');
$embedBody     = (string)($s['embed_body']   ?? 'This is an example sticky message! This message is only a default value, and can be edited using a modal when you run the command.');
$embedImage    = (string)($s['embed_image']  ?? '');
$embedColor    = (string)($s['embed_color']  ?? '#f48342');
$embedUrl      = (string)($s['embed_url']    ?? '');
$embedFooter   = (string)($s['embed_footer'] ?? 'Sticky messages module');
$repostCount   = (int)($s['repost_count']    ?? 10);
$showAuthor    = (int)($s['show_author']     ?? 1);
$addReaction   = (int)($s['add_reaction']    ?? 1);
$reactionEmoji = (string)($s['reaction_emoji'] ?? '👍');
try { $cmdStickyPost = bhcmd_is_enabled($pdo, $botId, 'sticky-post'); } catch (Throwable) { $cmdStickyPost = 1; }
$evtHandler    = (int)($s['evt_handler']     ?? 1);

$esc = fn(string $v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>

<div class="bh-section-label">STICKY MESSAGES</div>
<div class="bh-section-title">Setup</div>

<div id="bh-alert" style="display:none"></div>

<!-- Setup Card -->
<div class="bh-card">
    <!-- Manager Role -->
    <div class="bh-field">
        <div class="bh-label">Manager Role</div>
        <div class="bh-hint">Only people with this selected role will be able to create/delete sticky messages.</div>
        <div id="sm-role-box" style="background:#151b2b;border:1px solid #2e3850;border-radius:8px;padding:8px 10px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;min-height:42px">
            <?php if ($managerRoleId): ?>
            <span class="bh-tag" id="sm-role-tag" data-id="<?= $esc($managerRoleId) ?>">
                Role: <?= $esc($managerRoleId) ?>
                <button class="bh-tag-rm" onclick="smRemoveRole()">×</button>
            </span>
            <?php endif; ?>
            <button type="button" class="bh-add-btn" id="sm-role-add-btn" <?= $managerRoleId ? 'style="display:none"' : '' ?>>+</button>
        </div>
    </div>
</div>

<!-- Default Sticky Message -->
<div class="bh-card">
    <div class="bh-card-title">Default Sticky Message</div>
    <div class="bh-card-desc">Edit a default plain text or embed message to use as a sticky message. People will be able to edit these values when they run <span style="color:#ef4444">/sticky-post</span>!</div>

    <div class="bh-toggle-row">
        <span class="bh-toggle-row__title">Message</span>
        <div style="display:flex;align-items:center;gap:8px">
            <span style="font-size:12px;color:#4f5f80">Embed</span>
            <label class="bh-toggle">
                <input class="bh-toggle-input" type="checkbox" id="sm-is-embed" <?= $isEmbed ? 'checked' : '' ?>>
                <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
            </label>
        </div>
    </div>

    <div id="sm-embed-panel" <?= !$isEmbed ? 'style="display:none"' : '' ?>>
        <div class="sm-embed-panel">
            <div class="sm-embed-inner">
                <div id="sm-embed-stripe" class="sm-embed-stripe" style="background:<?= $esc($embedColor) ?>" onclick="document.getElementById('sm-color-picker').click()"></div>
                <div class="sm-embed-body">
                    <div class="sm-embed-row">
                        <div>
                            <div class="bh-embed-label">Author</div>
                            <input type="text" id="sm-embed-author" class="bh-embed-input" placeholder="Author" value="<?= $esc($embedAuthor) ?>">
                        </div>
                        <div>
                            <div class="bh-embed-label">Thumbnail URL</div>
                            <input type="text" id="sm-embed-thumb" class="bh-embed-input" placeholder="{server_icon}" value="<?= $esc($embedThumb) ?>">
                        </div>
                    </div>
                    <div>
                        <div class="bh-embed-label">Title</div>
                        <input type="text" id="sm-embed-title" class="bh-embed-input" placeholder="Sticky Messages" value="<?= $esc($embedTitle) ?>">
                    </div>
                    <div>
                        <div class="bh-embed-label">Description</div>
                        <textarea id="sm-embed-body" class="bh-embed-textarea" maxlength="2000"><?= $esc($embedBody) ?></textarea>
                    </div>
                    <div>
                        <div class="bh-embed-label">Image URL</div>
                        <input type="text" id="sm-embed-image" class="bh-embed-input" placeholder="https://..." value="<?= $esc($embedImage) ?>">
                    </div>
                    <div style="margin-top:4px">
                        <div class="bh-embed-label">Footer</div>
                        <input type="text" id="sm-embed-footer" class="bh-embed-input" placeholder="Sticky messages module" value="<?= $esc($embedFooter) ?>">
                    </div>
                    <div class="sm-embed-footer-row">
                        <div>
                            <div class="bh-embed-label">Color</div>
                            <div style="display:flex;align-items:center;gap:8px">
                                <span id="sm-color-swatch" class="sm-color-btn" style="background:<?= $esc($embedColor) ?>" onclick="document.getElementById('sm-color-picker').click()"></span>
                                <input type="color" id="sm-color-picker" value="<?= $esc($embedColor) ?>" style="display:none">
                                <input type="text" id="sm-color-hex" class="bh-embed-input" value="<?= $esc($embedColor) ?>" style="width:90px;font-family:monospace">
                            </div>
                        </div>
                        <div>
                            <div class="bh-embed-label">Embed URL</div>
                            <input type="text" id="sm-embed-url" class="bh-embed-input" placeholder="https://..." value="<?= $esc($embedUrl) ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="sm-plain-panel" <?= $isEmbed ? 'style="display:none"' : '' ?>>
        <textarea id="sm-plain-text" class="bh-input" style="min-height:80px;resize:vertical" placeholder="Your sticky message text…"><?= $esc($plainText) ?></textarea>
    </div>
</div>

<!-- Settings Card -->
<div class="bh-card">
    <!-- Repost count -->
    <div class="bh-field">
        <div class="bh-label">Repost Message Count</div>
        <div class="bh-hint">Choose how many messages can be sent after the latest sticky message before posting a new one.</div>
        <div>
            <div class="bh-label">Message Count</div>
            <input type="number" id="sm-repost-count" class="bh-input" value="<?= (int)$repostCount ?>" min="1" max="500" style="max-width:180px">
        </div>
    </div>

    <!-- Show Author -->
    <div class="bh-toggle-row">
        <div>
            <div class="bh-toggle-row__title">Show Author</div>
            <div class="bh-toggle-row__desc">Shows the poster of the sticky message as an author. <strong>Only works with an embed stickied message!</strong></div>
        </div>
        <label class="bh-toggle" style="margin-left:16px">
            <input class="bh-toggle-input" type="checkbox" id="sm-show-author" <?= $showAuthor ? 'checked' : '' ?>>
            <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
        </label>
    </div>

    <!-- Add Reaction -->
    <div class="bh-toggle-row">
        <div>
            <div class="bh-toggle-row__title">Add Reaction</div>
            <div class="bh-toggle-row__desc">Choose whether or not a default reaction should be added to the sticky message.</div>
        </div>
        <label class="bh-toggle" style="margin-left:16px">
            <input class="bh-toggle-input" type="checkbox" id="sm-add-reaction" <?= $addReaction ? 'checked' : '' ?>>
            <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
        </label>
    </div>

    <!-- Reaction Emoji -->
    <div class="bh-field" style="padding-top:16px">
        <div class="bh-label">Reaction Emoji</div>
        <div class="bh-hint">Choose which emoji to add to the sticky message by default. You can use <strong>default emojis</strong> or a custom emoji using <code style="color:#a5b4fc">emoji_name:emoji_id</code>.</div>
        <div>
            <div class="bh-label">Emoji</div>
            <input type="text" id="sm-reaction-emoji" class="bh-input" value="<?= $esc($reactionEmoji) ?>" style="max-width:180px">
        </div>
    </div>
</div>

<!-- Commands -->
<div style="margin-top:8px">
    <div class="bh-section-label">MODULE</div>
    <div class="sm-module-title">Commands</div>
    <div class="bh-cmd-grid">
        <div class="bh-cmd-card">
            <div>
                <div class="bh-cmd-name">/sticky-post</div>
                <div class="bh-cmd-desc">Post a new sticky message to a channel!</div>
            </div>
            <label class="bh-toggle" style="margin-left:16px">
                <input class="bh-toggle-input" type="checkbox" id="sm-cmd-sticky-post" <?= $cmdStickyPost ? 'checked' : '' ?>>
                <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
            </label>
        </div>
        <div class="bh-cmd-card">
            <div><div class="bh-cmd-name">Add Command</div><div class="bh-cmd-desc">This command will have access to all the variables and settings of this module.</div></div>
            <button class="bh-btn bh-btn--primary">Add</button>
        </div>
    </div>
</div>

<!-- Events -->
<div style="margin-top:28px;margin-bottom:8px">
    <div class="bh-section-label">MODULE</div>
    <div class="sm-module-title">Events</div>
    <div class="bh-cmd-grid">
        <div class="bh-cmd-card">
            <div>
                <div class="bh-cmd-name">Sticky Messages (Poster)</div>
                <div class="bh-cmd-desc">When a new message is sent</div>
            </div>
            <label class="bh-toggle" style="margin-left:16px">
                <input class="bh-toggle-input" type="checkbox" id="sm-evt-handler" <?= $evtHandler ? 'checked' : '' ?>>
                <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
            </label>
        </div>
        <div class="bh-cmd-card">
            <div><div class="bh-cmd-name">Add Event</div><div class="bh-cmd-desc">This event will have access to all the variables and settings of this module.</div></div>
            <button class="bh-btn bh-btn--primary">Add</button>
        </div>
    </div>
</div>

<div style="display:flex;justify-content:flex-end;margin-top:24px;margin-bottom:32px">
    <button class="sm-save-btn" id="sm-save-btn" onclick="smSave()">Save Changes</button>
</div>

<script>
(function () {
    const BOT_ID = <?= (int)$botId ?>;
    let managerRoleId = <?= json_encode($managerRoleId) ?>;

    // Color sync
    const cp = document.getElementById('sm-color-picker');
    const ch = document.getElementById('sm-color-hex');
    const sw = document.getElementById('sm-color-swatch');
    const es = document.getElementById('sm-embed-stripe');
    function applyColor(hex) { sw.style.background = hex; if(es) es.style.background = hex; ch.value = hex; cp.value = hex; }
    cp.addEventListener('input', () => applyColor(cp.value));
    ch.addEventListener('change', () => { const v = ch.value.trim(); if(/^#[0-9a-fA-F]{6}$/.test(v)) applyColor(v); });

    // Embed toggle
    document.getElementById('sm-is-embed').addEventListener('change', function () {
        document.getElementById('sm-embed-panel').style.display = this.checked ? '' : 'none';
        document.getElementById('sm-plain-panel').style.display = this.checked ? 'none' : '';
    });

    // Manager role picker
    document.getElementById('sm-role-add-btn').addEventListener('click', function (e) {
        e.stopPropagation();
        BhPerm.openPicker(this, BOT_ID, 'roles', [], (item) => {
            managerRoleId = item.id;
            const box = document.getElementById('sm-role-box');
            box.querySelectorAll('.bh-tag').forEach(t => t.remove());
            const tag = document.createElement('span');
            tag.className = 'bh-tag';
            tag.id = 'sm-role-tag';
            tag.dataset.id = item.id;
            tag.innerHTML = `${esc(item.name || item.id)}<button class="bh-tag-rm" onclick="smRemoveRole()">×</button>`;
            box.insertBefore(tag, document.getElementById('sm-role-add-btn'));
            document.getElementById('sm-role-add-btn').style.display = 'none';
        });
    });

    window.smRemoveRole = function () {
        managerRoleId = '';
        document.getElementById('sm-role-tag')?.remove();
        document.getElementById('sm-role-add-btn').style.display = '';
    };

    window.smSave = async function () {
        const btn = document.getElementById('sm-save-btn');
        btn.disabled = true;
        try {
            const res = await fetch(location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action:          'save',
                    manager_role_id: managerRoleId,
                    is_embed:        document.getElementById('sm-is-embed').checked,
                    plain_text:      document.getElementById('sm-plain-text').value.trim(),
                    embed_author:    document.getElementById('sm-embed-author').value.trim(),
                    embed_thumbnail: document.getElementById('sm-embed-thumb').value.trim(),
                    embed_title:     document.getElementById('sm-embed-title').value.trim(),
                    embed_body:      document.getElementById('sm-embed-body').value.trim(),
                    embed_image:     document.getElementById('sm-embed-image').value.trim(),
                    embed_color:     document.getElementById('sm-color-hex').value.trim(),
                    embed_url:       document.getElementById('sm-embed-url').value.trim(),
                    embed_footer:    document.getElementById('sm-embed-footer').value.trim(),
                    repost_count:    parseInt(document.getElementById('sm-repost-count').value) || 10,
                    show_author:     document.getElementById('sm-show-author').checked,
                    add_reaction:    document.getElementById('sm-add-reaction').checked,
                    reaction_emoji:  document.getElementById('sm-reaction-emoji').value.trim(),
                    cmd_sticky_post: document.getElementById('sm-cmd-sticky-post').checked,
                    evt_handler:     document.getElementById('sm-evt-handler').checked,
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
