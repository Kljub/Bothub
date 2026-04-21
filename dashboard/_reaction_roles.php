<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/functions/db_functions/reaction_roles.php';
require_once dirname(__DIR__) . '/functions/module_toggle.php';

$pdo   = bh_get_pdo();
$botId = isset($currentBotId) && $currentBotId > 0 ? $currentBotId : (int)($_GET['bot_id'] ?? 0);

if ($botId <= 0) {
?>
<div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl p-5">
    <div class="text-sm text-rose-600 dark:text-rose-400">Bot nicht gefunden.</div>
</div>
<?php
return;
}

// ── Auto-migrate table ────────────────────────────────────────────────────
try { bhrr_ensure_table($pdo); } catch (Throwable) {}

// ── Handle AJAX ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bh_mod_handle_ajax($pdo, $botId);
    while (ob_get_level() > 0) { ob_end_clean(); }
    $raw    = (string)file_get_contents('php://input');
    $data   = json_decode($raw, true);
    $action = (string)($data['action'] ?? '');
    header('Content-Type: application/json; charset=utf-8');

    if ($action === 'add') {
        $messageId   = trim((string)($data['message_id']   ?? ''));
        $channelId   = trim((string)($data['channel_id']   ?? ''));
        $emoji       = trim((string)($data['emoji']        ?? ''));
        $rolesToAdd  = array_values(array_filter((array)($data['roles_to_add']      ?? []), fn($r) => is_array($r) && isset($r['id'])));
        $rolesToRm   = array_values(array_filter((array)($data['roles_to_remove']   ?? []), fn($r) => is_array($r) && isset($r['id'])));
        $blacklisted = array_values(array_filter((array)($data['blacklisted_roles'] ?? []), fn($r) => is_array($r) && isset($r['id'])));
        $restrictOne = (isset($data['restrict_one'])    && $data['restrict_one'])    ? 1 : 0;
        $removeReact = (!isset($data['remove_reaction']) || $data['remove_reaction']) ? 1 : 0;

        if ($messageId === '' || $emoji === '') {
            echo json_encode(['ok' => false, 'error' => 'Message ID und Emoji sind Pflichtfelder.']);
            exit;
        }

        try {
            $newId = bhrr_add(
                $pdo, $botId, $messageId, $channelId, $emoji,
                json_encode(array_map(fn($r) => ['id' => $r['id'], 'name' => $r['name'] ?? $r['id']], $rolesToAdd),  JSON_UNESCAPED_UNICODE),
                json_encode(array_map(fn($r) => ['id' => $r['id'], 'name' => $r['name'] ?? $r['id']], $rolesToRm),   JSON_UNESCAPED_UNICODE),
                json_encode(array_map(fn($r) => ['id' => $r['id'], 'name' => $r['name'] ?? $r['id']], $blacklisted), JSON_UNESCAPED_UNICODE),
                $restrictOne,
                $removeReact
            );
            echo json_encode(['ok' => true, 'id' => $newId]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($data['id'] ?? 0);
        try {
            bhrr_delete($pdo, $botId, $id);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

// ── Load existing rules ───────────────────────────────────────────────────
$entries = [];
try { $entries = bhrr_list($pdo, $botId); } catch (Throwable) {}

$esc = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$modEnabled = bh_mod_is_enabled($pdo, $botId, 'module:reaction-roles');
?>

<?= bh_mod_render($modEnabled, $botId, 'module:reaction-roles', 'Reaction Roles', 'Reaction Roles für diesen Bot ein- oder ausschalten.') ?>
<div id="bh-mod-body">
<div class="px-1">

    <!-- ── Setup Card ─────────────────────────────────────────── -->
    <div class="mb-2">
        <div class="rr-section-label">Reaction Roles</div>
        <div class="rr-section-title">Setup</div>
    </div>

    <div class="rr-card" id="rr-add-card">
        <div class="rr-card-title">Reaction Roles Setup</div>
        <div class="rr-card-desc">Configure new reaction roles below.</div>

        <div class="rr-field">
            <label class="rr-label">Existing Message ID</label>
            <span class="rr-label-desc">Enter the Discord message ID of the message people will need to react to to get role(s).</span>
            <input type="text" class="rr-input" id="rr-message-id" placeholder="123456789012345678" maxlength="32">
        </div>

        <div class="rr-field">
            <label class="rr-label">Channel <span style="color:#4f5f80;font-weight:400;">(optional)</span></label>
            <span class="rr-label-desc">The channel where the message lives. Used for context.</span>
            <div class="rr-roles-box" id="rr-channel-box">
                <button type="button" class="rr-add-btn" id="rr-channel-pick-btn" title="Channel auswählen">+</button>
            </div>
        </div>

        <div class="rr-field">
            <label class="rr-label">Reaction Emoji</label>
            <span class="rr-label-desc">The event will trigger when someone reacts with this emoji. Provide a default emoji or the name of a custom Discord emoji.</span>
            <input type="text" class="rr-input" id="rr-emoji" placeholder="🔥  or  emoji_name" maxlength="128">
        </div>

        <div class="rr-field">
            <label class="rr-label">Roles to Add</label>
            <span class="rr-label-desc">Choose up to 3 roles that will be given when a user reacts to the message.</span>
            <div class="rr-roles-box" id="rr-roles-add-box">
                <button type="button" class="rr-add-btn" id="rr-add-role-add" title="Rolle hinzufügen">+</button>
            </div>
        </div>

        <div class="rr-field">
            <label class="rr-label">Roles to Remove</label>
            <span class="rr-label-desc">Choose up to 3 roles that will be removed when a user reacts to the message.</span>
            <div class="rr-roles-box" id="rr-roles-remove-box">
                <button type="button" class="rr-add-btn" id="rr-add-role-remove" title="Rolle hinzufügen">+</button>
            </div>
        </div>

        <div class="rr-toggle-row">
            <div class="rr-toggle-label-wrap">
                <div class="rr-toggle-title">Restrict to one Reaction</div>
                <div class="rr-toggle-desc">Will only allow users to react once on the reaction role message.</div>
            </div>
            <label class="toggle">
                <input type="checkbox" id="rr-restrict-one">
                <span class="toggle-slider"></span>
            </label>
        </div>

        <div class="rr-toggle-row">
            <div class="rr-toggle-label-wrap">
                <div class="rr-toggle-title">Remove Reaction</div>
                <div class="rr-toggle-desc">Removes the user's reaction from the message once the roles were given.</div>
            </div>
            <label class="toggle">
                <input type="checkbox" id="rr-remove-reaction" checked>
                <span class="toggle-slider"></span>
            </label>
        </div>

        <div class="rr-field" style="margin-top:14px;">
            <label class="rr-label">Blacklisted Roles</label>
            <span class="rr-label-desc">Users who have one of the chosen roles will be ignored by the reaction role system.</span>
            <div class="rr-roles-box" id="rr-roles-blacklist-box">
                <button type="button" class="rr-add-btn" id="rr-add-role-blacklist" title="Rolle hinzufügen">+</button>
            </div>
        </div>

        <div class="mt-4 flex justify-end">
            <button type="button" class="rr-save-btn" id="rr-add-submit">Add</button>
        </div>
    </div>

    <!-- ── Existing entries ───────────────────────────────────── -->
    <div class="mt-8 mb-2">
        <div class="rr-section-label">Module</div>
        <div class="rr-section-title">Events</div>
    </div>

    <div id="rr-entries-list">
        <?php if (empty($entries)): ?>
            <div class="rr-empty" id="rr-empty-hint">Noch keine Reaction Roles konfiguriert.</div>
        <?php else: ?>
            <?php foreach ($entries as $entry): ?>
                <?php
                $rolesToAdd  = json_decode((string)($entry['roles_to_add']      ?? '[]'), true) ?: [];
                $rolesToRm   = json_decode((string)($entry['roles_to_remove']   ?? '[]'), true) ?: [];
                $blacklisted = json_decode((string)($entry['blacklisted_roles'] ?? '[]'), true) ?: [];
                ?>
                <div class="rr-entry" data-rr-id="<?= (int)$entry['id'] ?>">
                    <div class="rr-entry-emoji"><?= $esc((string)($entry['emoji'] ?? '❓')) ?></div>
                    <div class="rr-entry-body">
                        <div class="rr-entry-meta">
                            Message: <?= $esc((string)($entry['message_id'] ?? '')) ?>
                            <?php if (!empty($entry['channel_id'])): ?> · Channel: <?= $esc((string)$entry['channel_id']) ?><?php endif; ?>
                        </div>
                        <div class="rr-entry-roles">
                            <?php foreach ($rolesToAdd as $r): ?>
                                <span class="rr-entry-role-chip rr-entry-role-chip--add">+ <?= $esc((string)($r['name'] ?? $r['id'] ?? '?')) ?></span>
                            <?php endforeach; ?>
                            <?php foreach ($rolesToRm as $r): ?>
                                <span class="rr-entry-role-chip rr-entry-role-chip--remove">- <?= $esc((string)($r['name'] ?? $r['id'] ?? '?')) ?></span>
                            <?php endforeach; ?>
                            <?php foreach ($blacklisted as $r): ?>
                                <span class="rr-entry-role-chip rr-entry-role-chip--bl">✕ <?= $esc((string)($r['name'] ?? $r['id'] ?? '?')) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="rr-entry-flags">
                            <span class="<?= (int)$entry['restrict_one']     ? 'rr-entry-flag--on' : '' ?>">Restrict one: <?= (int)$entry['restrict_one']     ? 'on' : 'off' ?></span>
                            <span class="<?= (int)$entry['remove_reaction']  ? 'rr-entry-flag--on' : '' ?>">Remove reaction: <?= (int)$entry['remove_reaction'] ? 'on' : 'off' ?></span>
                        </div>
                    </div>
                    <button type="button" class="rr-entry-delete" data-rr-delete="<?= (int)$entry['id'] ?>">Löschen</button>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>
</div><!-- /bh-mod-body -->

<script>
(function () {
    'use strict';

    var BOT_ID = <?= (int)$botId ?>;

    // ── State ─────────────────────────────────────────────────────────────
    var rolesAdd       = [];
    var rolesRemove    = [];
    var rolesBlacklist = [];
    var selectedChannelId   = '';
    var selectedChannelName = '';

    var MAX_ROLES = 3;

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Role box renderer ──────────────────────────────────────────────────
    function renderRoleBox(boxId, list, addBtnId, maxItems) {
        var box    = document.getElementById(boxId);
        var addBtn = document.getElementById(addBtnId);
        if (!box) return;

        // Remove existing tags (keep addBtn)
        Array.from(box.children).forEach(function (ch) {
            if (ch !== addBtn) ch.remove();
        });

        list.forEach(function (role, idx) {
            var tag = document.createElement('span');
            tag.className = 'rr-role-tag';
            tag.innerHTML = escHtml(role.name || role.id)
                + '<button type="button" class="rr-role-tag-rm" title="Entfernen">×</button>';
            tag.querySelector('.rr-role-tag-rm').addEventListener('click', function () {
                list.splice(idx, 1);
                renderRoleBox(boxId, list, addBtnId, maxItems);
            });
            box.insertBefore(tag, addBtn);
        });

        if (addBtn) {
            addBtn.style.display = list.length >= maxItems ? 'none' : '';
        }
    }

    function initBox(boxId, list, addBtnId) {
        var addBtn = document.getElementById(addBtnId);
        if (!addBtn) return;
        addBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (list.length >= MAX_ROLES) return;
            if (typeof BhPerm === 'undefined') return;
            BhPerm.openPicker(addBtn, BOT_ID, 'roles', list, function (role) {
                if (list.find(function (r) { return r.id === role.id; })) return;
                list.push({ id: role.id, name: role.name });
                renderRoleBox(boxId, list, addBtnId, MAX_ROLES);
            });
        });
        renderRoleBox(boxId, list, addBtnId, MAX_ROLES);
    }

    initBox('rr-roles-add-box',       rolesAdd,       'rr-add-role-add');
    initBox('rr-roles-remove-box',    rolesRemove,    'rr-add-role-remove');
    initBox('rr-roles-blacklist-box', rolesBlacklist, 'rr-add-role-blacklist');

    // ── Channel picker ────────────────────────────────────────────────────
    (function () {
        var box     = document.getElementById('rr-channel-box');
        var pickBtn = document.getElementById('rr-channel-pick-btn');
        if (!box || !pickBtn) return;

        function renderChannelTag(id, name) {
            var existing = box.querySelector('.rr-role-tag');
            if (existing) existing.remove();

            var tag = document.createElement('span');
            tag.className = 'rr-role-tag';
            tag.innerHTML = escHtml('#' + (name || id))
                + '<button type="button" class="rr-role-tag-rm" title="Entfernen">×</button>';
            tag.querySelector('.rr-role-tag-rm').addEventListener('click', function () {
                selectedChannelId   = '';
                selectedChannelName = '';
                tag.remove();
                pickBtn.style.display = '';
            });
            box.insertBefore(tag, pickBtn);
            pickBtn.style.display = 'none';
        }

        pickBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (typeof BhPerm === 'undefined') return;
            BhPerm.openPicker(pickBtn, BOT_ID, 'channels', [], function (item) {
                selectedChannelId   = item.id;
                selectedChannelName = item.name || item.id;
                renderChannelTag(selectedChannelId, selectedChannelName);
            });
        });
    }());

    // ── Add submit ────────────────────────────────────────────────────────
    document.getElementById('rr-add-submit').addEventListener('click', async function () {
        var messageId = document.getElementById('rr-message-id').value.trim();
        var channelId = selectedChannelId;
        var emoji     = document.getElementById('rr-emoji').value.trim();
        var restrictOne   = document.getElementById('rr-restrict-one').checked;
        var removeReaction = document.getElementById('rr-remove-reaction').checked;

        if (!messageId || !emoji) {
            alert('Bitte Message ID und Emoji ausfüllen.');
            return;
        }

        var btn = this;
        btn.disabled = true;
        btn.textContent = '…';

        try {
            var res = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action:           'add',
                    message_id:       messageId,
                    channel_id:       channelId,
                    emoji:            emoji,
                    roles_to_add:     rolesAdd,
                    roles_to_remove:  rolesRemove,
                    blacklisted_roles: rolesBlacklist,
                    restrict_one:     restrictOne,
                    remove_reaction:  removeReaction,
                }),
            });
            var data = await res.json();
            if (data.ok) {
                // Reload page to show new entry
                window.location.reload();
            } else {
                alert(data.error || 'Fehler beim Speichern.');
                btn.disabled = false;
                btn.textContent = 'Add';
            }
        } catch (_) {
            alert('Netzwerkfehler.');
            btn.disabled = false;
            btn.textContent = 'Add';
        }
    });

    // ── Delete ────────────────────────────────────────────────────────────
    document.querySelectorAll('[data-rr-delete]').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            if (!confirm('Diese Reaction Role wirklich löschen?')) return;
            var id = parseInt(this.dataset.rrDelete, 10);
            try {
                var res = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', id: id }),
                });
                var data = await res.json();
                if (data.ok) {
                    var entry = document.querySelector('[data-rr-id="' + id + '"]');
                    if (entry) {
                        entry.style.opacity = '0';
                        entry.style.transition = 'opacity .2s';
                        setTimeout(function () {
                            entry.remove();
                            if (!document.querySelector('[data-rr-id]')) {
                                var hint = document.getElementById('rr-empty-hint');
                                if (!hint) {
                                    hint = document.createElement('div');
                                    hint.id = 'rr-empty-hint';
                                    hint.className = 'rr-empty';
                                    hint.textContent = 'Noch keine Reaction Roles konfiguriert.';
                                    document.getElementById('rr-entries-list').appendChild(hint);
                                }
                            }
                        }, 220);
                    }
                } else {
                    alert(data.error || 'Fehler beim Löschen.');
                }
            } catch (_) {
                alert('Netzwerkfehler.');
            }
        });
    });

}());
</script>
