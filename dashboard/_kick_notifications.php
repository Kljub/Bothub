<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/functions/module_toggle.php';

$pdo   = bh_get_pdo();
$botId = isset($currentBotId) && $currentBotId > 0 ? $currentBotId : (int)($_GET['bot_id'] ?? 0);

if ($botId <= 0) { ?>
<div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl p-5">
    <div class="text-sm text-rose-600 dark:text-rose-400">Bot nicht gefunden.</div>
</div>
<?php return; }

// ── Auto-migrate ──────────────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS kick_notifications (
        id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        bot_id           BIGINT UNSIGNED NOT NULL,
        guild_id         VARCHAR(30)     NOT NULL DEFAULT '',
        channel_id       VARCHAR(30)     NOT NULL,
        streamer_slug    VARCHAR(60)     NOT NULL,
        custom_message   TEXT            NULL,
        ping_role_id     VARCHAR(30)     NOT NULL DEFAULT '',
        is_enabled       TINYINT(1)      NOT NULL DEFAULT 1,
        is_live          TINYINT(1)      NOT NULL DEFAULT 0,
        last_notified_at DATETIME        NULL,
        created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_kick_notify (bot_id, guild_id, streamer_slug),
        KEY idx_kick_notify_bot (bot_id, is_enabled)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable) {}

// ── AJAX ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bh_mod_handle_ajax($pdo, $botId);
    while (ob_get_level() > 0) ob_end_clean();
    $raw    = (string)file_get_contents('php://input');
    $data   = json_decode($raw, true);
    $action = (string)($data['action'] ?? '');
    header('Content-Type: application/json; charset=utf-8');

    if ($action === 'add') {
        $slug      = mb_strtolower(trim((string)($data['streamer_slug'] ?? '')));
        $channelId = trim((string)($data['channel_id']     ?? ''));
        $guildId   = trim((string)($data['guild_id']       ?? ''));
        $customMsg = trim((string)($data['custom_message'] ?? ''));
        $pingRole  = trim((string)($data['ping_role_id']   ?? ''));

        if ($slug === '') {
            echo json_encode(['ok' => false, 'error' => 'Kick-Benutzername ist erforderlich.']); exit;
        }
        if ($channelId === '') {
            echo json_encode(['ok' => false, 'error' => 'Discord-Kanal ist erforderlich.']); exit;
        }
        if (!preg_match('/^[a-zA-Z0-9_\-]{1,60}$/', $slug)) {
            echo json_encode(['ok' => false, 'error' => 'Ungültiger Kick-Benutzername.']); exit;
        }

        try {
            $pdo->prepare("
                INSERT INTO kick_notifications
                    (bot_id, guild_id, channel_id, streamer_slug, custom_message, ping_role_id)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([$botId, $guildId, $channelId, $slug, $customMsg ?: null, $pingRole]);
            echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
        } catch (Throwable $e) {
            $msg = str_contains($e->getMessage(), '1062') || str_contains($e->getMessage(), 'Duplicate')
                ? 'Dieser Streamer ist für diesen Server bereits eingetragen.'
                : $e->getMessage();
            echo json_encode(['ok' => false, 'error' => $msg]);
        }
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'Ungültige ID.']); exit; }
        try {
            $pdo->prepare("DELETE FROM kick_notifications WHERE id = ? AND bot_id = ?")->execute([$id, $botId]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'toggle') {
        $id  = (int)($data['id'] ?? 0);
        $val = (isset($data['is_enabled']) && $data['is_enabled']) ? 1 : 0;
        if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'Ungültige ID.']); exit; }
        try {
            $pdo->prepare("UPDATE kick_notifications SET is_enabled = ? WHERE id = ? AND bot_id = ?")->execute([$val, $id, $botId]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unbekannte Aktion']); exit;
}

// ── Load data ─────────────────────────────────────────────────────────────────
$notifications = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM kick_notifications WHERE bot_id = ? ORDER BY created_at DESC");
    $stmt->execute([$botId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {}

$esc        = fn(string $v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$modEnabled = bh_mod_is_enabled($pdo, $botId, 'module:kick');
?>

<?= bh_mod_render($modEnabled, $botId, 'module:kick', 'Kick Notifications', 'Kick-Benachrichtigungen für diesen Bot ein- oder ausschalten.') ?>
<div id="bh-mod-body">

<div id="kn-flash" style="display:none"></div>

<!-- ── Hinweis ───────────────────────────────────────────────────────────────── -->
<div class="rounded-xl border border-emerald-200 dark:border-emerald-700/60 bg-emerald-50 dark:bg-emerald-500/10 px-4 py-3 flex items-start gap-3 mb-6">
    <svg class="mt-0.5 shrink-0 text-emerald-500" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>
    </svg>
    <div class="text-sm text-emerald-700 dark:text-emerald-300">
        Kick Notifications nutzt die öffentliche Kick API — keine App-Credentials erforderlich.
    </div>
</div>

<!-- ── Streamer hinzufügen ───────────────────────────────────────────────────── -->
<div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl" style="margin-bottom:24px">
    <div class="p-5 border-b border-gray-100 dark:border-gray-700/60">
        <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Streamer hinzufügen</h2>
    </div>
    <div class="p-5">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
            <div>
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Kick Benutzername</label>
                <input type="text" id="kn-slug"
                    class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm text-gray-800 dark:text-gray-100 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-violet-500"
                    placeholder="streamer_name" maxlength="60">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Discord Kanal</label>
                <select id="kn-channel-select"
                    class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm text-gray-800 dark:text-gray-100 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-violet-500">
                    <option value="">— Kanäle werden geladen… —</option>
                </select>
                <div id="kn-channel-error" style="display:none;font-size:11px;color:#f87171;margin-top:3px"></div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
            <div>
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Ping-Rolle <span class="font-normal text-gray-400">(optional)</span>
                </label>
                <select id="kn-role-select"
                    class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm text-gray-800 dark:text-gray-100 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-violet-500">
                    <option value="">— Keine Rolle —</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Custom Message <span class="font-normal text-gray-400">(optional)</span>
                </label>
                <input type="text" id="kn-custom-message"
                    class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm text-gray-800 dark:text-gray-100 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-violet-500"
                    placeholder="{streamer} ist jetzt live! 🎮" maxlength="300">
            </div>
        </div>

        <div style="margin-bottom:4px;font-size:11px;color:#6b7280">
            Variablen: <code style="color:#a5b4fc">{streamer}</code> · <code style="color:#a5b4fc">{title}</code> · <code style="color:#a5b4fc">{category}</code> · <code style="color:#a5b4fc">{url}</code>
        </div>

        <div style="margin-top:16px">
            <button onclick="knAdd()" id="kn-add-btn"
                class="inline-flex items-center gap-1.5 rounded-lg bg-violet-600 hover:bg-violet-700 text-white text-xs font-semibold px-4 py-2 transition-colors">
                Streamer hinzufügen
            </button>
        </div>
    </div>
</div>

<!-- ── Streamer-Liste ────────────────────────────────────────────────────────── -->
<div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl">
    <div class="p-5 border-b border-gray-100 dark:border-gray-700/60">
        <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">
            Konfigurierte Streamer
            <span class="ml-2 text-xs font-normal text-gray-400">(<?= count($notifications) ?>)</span>
        </h2>
    </div>
    <div class="p-5">
        <div id="kn-list">
        <?php if (empty($notifications)): ?>
            <div id="kn-empty" class="text-sm text-gray-400 dark:text-gray-500 text-center py-6">
                Noch keine Streamer konfiguriert. Füge oben einen hinzu.
            </div>
        <?php else: ?>
            <?php foreach ($notifications as $n): ?>
            <?php
                $slug      = (string)$n['streamer_slug'];
                $chanId    = (string)$n['channel_id'];
                $roleId    = (string)$n['ping_role_id'];
                $isLive    = (int)$n['is_live'] === 1;
                $isEnabled = (int)$n['is_enabled'] === 1;
                $lastNotify = $n['last_notified_at']
                    ? date('d.m.Y H:i', strtotime((string)$n['last_notified_at'])) : '—';
            ?>
            <div class="kn-row" id="kn-row-<?= (int)$n['id'] ?>">
                <div style="display:flex;align-items:center;gap:10px;min-width:0">
                    <!-- Kick logo -->
                    <span class="kn-avatar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="#53fc18"><path d="M3 2h4v7l5-7h5l-6 8 7 12h-5.5l-5-8.5V22H3V2z"/></svg>
                    </span>
                    <div style="min-width:0">
                        <div style="font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                            <a href="https://kick.com/<?= $esc($slug) ?>" target="_blank" rel="noopener" class="kn-link"><?= $esc($slug) ?></a>
                            <?php if ($isLive): ?>
                            <span class="kn-badge kn-badge--live">LIVE</span>
                            <?php else: ?>
                            <span class="kn-badge kn-badge--offline">OFFLINE</span>
                            <?php endif; ?>
                        </div>
                        <div class="kn-meta">
                            Kanal: <code style="color:#a5b4fc"><?= $esc($chanId) ?></code>
                            <?php if ($roleId !== ''): ?>
                             · Rolle: <code style="color:#a5b4fc"><?= $esc($roleId) ?></code>
                            <?php endif; ?>
                             · Zuletzt: <?= $esc($lastNotify) ?>
                            <?php if (!$isEnabled): ?>
                             · <span style="color:#f87171">Deaktiviert</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;margin-left:12px">
                    <label class="bh-toggle" title="Aktivieren / Deaktivieren">
                        <input type="checkbox" <?= $isEnabled ? 'checked' : '' ?>
                            onchange="knToggle(<?= (int)$n['id'] ?>, this.checked)">
                        <span class="bh-toggle__track"></span>
                        <span class="bh-toggle__thumb"></span>
                    </label>
                    <button onclick="knDelete(<?= (int)$n['id'] ?>)" class="kn-del-btn">Löschen</button>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>
    </div>
</div>

</div><!-- /bh-mod-body -->


<script>
(function () {
    const BOT_ID = <?= (int)$botId ?>;
    let loadedGuildId = '';

    // ── Flash ─────────────────────────────────────────────────────────────────
    function flash(msg, ok) {
        const el = document.getElementById('kn-flash');
        el.className = ok ? 'kn-ok' : 'kn-err';
        el.textContent = msg;
        el.style.display = '';
        clearTimeout(el._t);
        el._t = setTimeout(() => { el.style.display = 'none'; }, 4500);
    }

    // ── Load channels ─────────────────────────────────────────────────────────
    async function loadChannels(guildId) {
        const sel  = document.getElementById('kn-channel-select');
        const errEl = document.getElementById('kn-channel-error');
        errEl.style.display = 'none';
        sel.disabled = true;
        sel.innerHTML = '<option value="">— Wird geladen… —</option>';

        try {
            let url = '/api/v1/bot_guild_channels.php?bot_id=' + BOT_ID;
            if (guildId) url += '&guild_id=' + encodeURIComponent(guildId);
            const res  = await fetch(url);
            const json = await res.json();

            if (!json.ok) {
                errEl.textContent = json.error || 'Fehler beim Laden der Kanäle.';
                errEl.style.display = '';
                sel.innerHTML = '<option value="">— Fehler —</option>';
                return;
            }
            if (json.needs_guild && json.guilds?.length > 0) {
                loadedGuildId = json.guilds[0].id;
                await loadChannels(loadedGuildId);
                return;
            }
            loadedGuildId = json.guild_id || guildId || '';
            const channels = Array.isArray(json.channels) ? json.channels : [];
            sel.innerHTML = '<option value="">— Kanal auswählen —</option>';
            channels.forEach(c => {
                const o = document.createElement('option');
                o.value = c.id;
                o.textContent = '#' + c.name;
                sel.appendChild(o);
            });
        } catch (e) {
            errEl.textContent = 'Netzwerkfehler: ' + e.message;
            errEl.style.display = '';
        } finally {
            sel.disabled = false;
        }
    }

    // ── Load roles ────────────────────────────────────────────────────────────
    async function loadRoles() {
        const sel = document.getElementById('kn-role-select');
        try {
            const res  = await fetch('/api/v1/bot_guild_roles.php?bot_id=' + BOT_ID);
            const json = await res.json();
            if (!json.ok) return;
            const roles = Array.isArray(json.roles) ? json.roles : [];
            roles.forEach(r => {
                const o = document.createElement('option');
                o.value = r.id;
                o.textContent = '@' + r.name;
                sel.appendChild(o);
            });
        } catch (_) {}
    }

    loadChannels('');
    loadRoles();

    // ── Add ───────────────────────────────────────────────────────────────────
    window.knAdd = async function () {
        const btn      = document.getElementById('kn-add-btn');
        const slug     = document.getElementById('kn-slug').value.trim().toLowerCase();
        const chanSel  = document.getElementById('kn-channel-select');
        const chanId   = chanSel.value;
        const roleId   = document.getElementById('kn-role-select').value;
        const customMsg = document.getElementById('kn-custom-message').value.trim();

        if (!slug)   { flash('Kick-Benutzername ist erforderlich.', false); return; }
        if (!chanId) { flash('Bitte einen Discord-Kanal auswählen.', false); return; }

        btn.disabled = true;
        try {
            const res  = await fetch(location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'add', streamer_slug: slug,
                    channel_id: chanId, guild_id: loadedGuildId,
                    custom_message: customMsg, ping_role_id: roleId,
                }),
            });
            const json = await res.json();
            if (!json.ok) { flash(json.error || 'Fehler.', false); return; }

            flash('Streamer hinzugefügt!', true);
            knAppendRow(json.id, slug, chanId, roleId);
            document.getElementById('kn-slug').value           = '';
            document.getElementById('kn-custom-message').value = '';
            chanSel.value = '';
            document.getElementById('kn-role-select').value = '';
            document.getElementById('kn-empty')?.remove();
        } catch (e) {
            flash('Netzwerkfehler.', false);
        } finally {
            btn.disabled = false;
        }
    };

    function knAppendRow(id, slug, chanId, roleId) {
        const div = document.createElement('div');
        div.className = 'kn-row';
        div.id = 'kn-row-' + id;
        const roleHtml = roleId ? ` · Rolle: <code style="color:#a5b4fc">${esc(roleId)}</code>` : '';
        div.innerHTML = `
            <div style="display:flex;align-items:center;gap:10px;min-width:0">
                <span class="kn-avatar">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="#53fc18"><path d="M3 2h4v7l5-7h5l-6 8 7 12h-5.5l-5-8.5V22H3V2z"/></svg>
                </span>
                <div style="min-width:0">
                    <div style="font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px">
                        <a href="https://kick.com/${esc(slug)}" target="_blank" rel="noopener" class="kn-link">${esc(slug)}</a>
                        <span class="kn-badge kn-badge--offline">OFFLINE</span>
                    </div>
                    <div class="kn-meta">Kanal: <code style="color:#a5b4fc">${esc(chanId)}</code>${roleHtml} · Zuletzt: —</div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;margin-left:12px">
                <label class="bh-toggle" title="Aktivieren / Deaktivieren">
                    <input type="checkbox" checked onchange="knToggle(${id}, this.checked)">
                    <span class="bh-toggle__track"></span>
                    <span class="bh-toggle__thumb"></span>
                </label>
                <button onclick="knDelete(${id})" class="kn-del-btn">Löschen</button>
            </div>`;
        document.getElementById('kn-list').prepend(div);
    }

    // ── Delete ────────────────────────────────────────────────────────────────
    window.knDelete = async function (id) {
        if (!confirm('Streamer-Benachrichtigung wirklich entfernen?')) return;
        try {
            const res  = await fetch(location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', id }),
            });
            const json = await res.json();
            if (!json.ok) { flash(json.error || 'Fehler.', false); return; }
            document.getElementById('kn-row-' + id)?.remove();
            flash('Entfernt.', true);
            if (!document.querySelector('#kn-list .kn-row')) {
                document.getElementById('kn-list').innerHTML =
                    '<div id="kn-empty" class="text-sm text-gray-400 dark:text-gray-500 text-center py-6">Noch keine Streamer konfiguriert.</div>';
            }
        } catch (e) {
            flash('Netzwerkfehler.', false);
        }
    };

    // ── Toggle ────────────────────────────────────────────────────────────────
    window.knToggle = async function (id, enabled) {
        try {
            await fetch(location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'toggle', id, is_enabled: enabled }),
            });
        } catch (_) {}
    };

    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
})();
</script>
