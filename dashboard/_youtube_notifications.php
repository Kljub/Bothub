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

// ── Auto-migrate: ensure table exists ─────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS youtube_notifications (
        id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        bot_id           BIGINT UNSIGNED NOT NULL,
        guild_id         VARCHAR(30)     NOT NULL,
        channel_id       VARCHAR(30)     NOT NULL,
        yt_channel_id    VARCHAR(64)     NOT NULL,
        yt_channel_name  VARCHAR(128)    NOT NULL DEFAULT '',
        ping_role_id     VARCHAR(30)     NOT NULL DEFAULT '',
        custom_message   TEXT            NULL,
        is_enabled       TINYINT(1)      NOT NULL DEFAULT 1,
        last_video_id    VARCHAR(64)     NOT NULL DEFAULT '',
        last_notified_at DATETIME        NULL,
        created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_yt_notify (bot_id, guild_id, yt_channel_id),
        KEY idx_yt_notify_bot (bot_id, is_enabled)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable) {}

// ── Handle AJAX ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bh_mod_handle_ajax($pdo, $botId);
    while (ob_get_level() > 0) { ob_end_clean(); }
    $raw    = (string)file_get_contents('php://input');
    $data   = json_decode($raw, true);
    $action = (string)($data['action'] ?? '');
    header('Content-Type: application/json; charset=utf-8');

    // ── Add channel ───────────────────────────────────────────────────────────
    if ($action === 'add') {
        $ytChannelId   = trim((string)($data['yt_channel_id']   ?? ''));
        $ytChannelName = trim((string)($data['yt_channel_name'] ?? ''));
        $channelId     = trim((string)($data['channel_id']      ?? ''));
        $guildId       = trim((string)($data['guild_id']        ?? ''));
        $pingRoleId    = trim((string)($data['ping_role_id']    ?? ''));
        $customMessage = trim((string)($data['custom_message']  ?? ''));

        if ($ytChannelId === '') {
            echo json_encode(['ok' => false, 'error' => 'YouTube Channel ID is required.']);
            exit;
        }
        if ($channelId === '') {
            echo json_encode(['ok' => false, 'error' => 'Discord Channel is required.']);
            exit;
        }
        // YouTube channel IDs start with UC and are 24 chars, or could be handle/@name
        if (!preg_match('/^(UC[a-zA-Z0-9_\-]{22}|@?[a-zA-Z0-9_\-\.]{3,64})$/', $ytChannelId)) {
            echo json_encode(['ok' => false, 'error' => 'Ungültige YouTube Channel ID. Nutze eine ID wie UCxxxxxxx oder einen Channel Handle.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO youtube_notifications
                    (bot_id, guild_id, channel_id, yt_channel_id, yt_channel_name, ping_role_id, custom_message)
                 VALUES (:bot_id, :guild_id, :channel_id, :yt_channel_id, :yt_channel_name, :ping_role_id, :custom_message)"
            );
            $stmt->execute([
                ':bot_id'          => $botId,
                ':guild_id'        => $guildId,
                ':channel_id'      => $channelId,
                ':yt_channel_id'   => $ytChannelId,
                ':yt_channel_name' => $ytChannelName,
                ':ping_role_id'    => $pingRoleId,
                ':custom_message'  => $customMessage !== '' ? $customMessage : null,
            ]);
            echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Duplicate') || str_contains($msg, '1062')) {
                echo json_encode(['ok' => false, 'error' => 'Dieser YouTube-Kanal wurde für diesen Server bereits hinzugefügt.']);
            } else {
                echo json_encode(['ok' => false, 'error' => $msg]);
            }
        }
        exit;
    }

    // ── Delete ────────────────────────────────────────────────────────────────
    if ($action === 'delete') {
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid ID.']); exit; }
        try {
            $stmt = $pdo->prepare("DELETE FROM youtube_notifications WHERE id = ? AND bot_id = ?");
            $stmt->execute([$id, $botId]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ── Toggle enabled ────────────────────────────────────────────────────────
    if ($action === 'toggle') {
        $id        = (int)($data['id']         ?? 0);
        $isEnabled = (isset($data['is_enabled']) && $data['is_enabled']) ? 1 : 0;
        if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid ID.']); exit; }
        try {
            $stmt = $pdo->prepare("UPDATE youtube_notifications SET is_enabled = ? WHERE id = ? AND bot_id = ?");
            $stmt->execute([$isEnabled, $id, $botId]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

// ── Load data ─────────────────────────────────────────────────────────────────
$notifications = [];
try {
    $stmt = $pdo->prepare(
        "SELECT * FROM youtube_notifications WHERE bot_id = ? ORDER BY created_at DESC"
    );
    $stmt->execute([$botId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {}

$esc = fn(string $v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$modEnabled = bh_mod_is_enabled($pdo, $botId, 'module:youtube');
?>

<?= bh_mod_render($modEnabled, $botId, 'module:youtube', 'YouTube Notifications', 'YouTube-Benachrichtigungen für diesen Bot ein- oder ausschalten.') ?>
<style>
.bh-info-tip{display:inline-flex;align-items:center;justify-content:center;width:15px;height:15px;border-radius:50%;background:rgba(99,102,241,.18);color:#818cf8;font-size:10px;font-weight:700;cursor:default;flex-shrink:0;vertical-align:middle;margin-left:6px;line-height:1}
.bh-info-tip:hover{background:rgba(99,102,241,.32)}
.bh-info-float-tip{position:fixed;transform:translate(-50%,calc(-100% - 8px));background:#1e293b;color:#e2e8f0;font-size:11px;font-weight:400;white-space:nowrap;padding:6px 10px;border-radius:7px;border:1px solid #374461;box-shadow:0 4px 12px rgba(0,0,0,.4);pointer-events:none;opacity:0;transition:opacity .15s;z-index:9999}
.bh-info-float-tip::after{content:'';position:absolute;top:100%;left:50%;transform:translateX(-50%);border:5px solid transparent;border-top-color:#374461}
</style>
<script>
(function(){
  var float = document.createElement('div');
  float.className = 'bh-info-float-tip';
  float.textContent = 'YouTube Channel ID nötig (z.B. UCxxxxxx). Bot prüft alle 5 Min. via RSS-Feed — kein API-Key erforderlich.';
  document.body.appendChild(float);

  var icon = document.createElement('span');
  icon.className = 'bh-info-tip';
  icon.textContent = 'i';

  icon.addEventListener('mouseenter', function(){
    var r = icon.getBoundingClientRect();
    float.style.left = (r.left + r.width / 2) + 'px';
    float.style.top  = (r.top + window.scrollY) + 'px';
    float.style.opacity = '1';
  });
  icon.addEventListener('mouseleave', function(){ float.style.opacity = '0'; });

  var title = document.querySelector('.bh-mod-feature__title');
  if(title) title.appendChild(icon);
})();
</script>
<div id="bh-mod-body">
<div id="bh-alert" style="display:none"></div>

<!-- ── Add Channel Card ─────────────────────────────────────────────────────── -->
<div class="bh-card" style="padding:0;margin-bottom:20px">
    <div class="bh-card-hdr">
        <div class="bh-card-title" style="margin:0">YouTube-Kanal hinzufügen</div>
    </div>
    <div class="bh-card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
            <div>
                <label class="bh-label" for="yn-yt-channel-id">YouTube Channel ID</label>
                <input type="text" id="yn-yt-channel-id" class="bh-input"
                    placeholder="UCxxxxxxxxxxxxxxxxxxxxxx" maxlength="64">
            </div>
            <div>
                <label class="bh-label" for="yn-yt-channel-name">
                    Anzeigename <span style="font-weight:400;color:#4f5f80">(optional)</span>
                </label>
                <input type="text" id="yn-yt-channel-name" class="bh-input"
                    placeholder="Kanalname" maxlength="128">
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
            <div>
                <label class="bh-label" for="yn-channel-select">Discord Kanal</label>
                <select id="yn-channel-select" class="bh-select">
                    <option value="">— Lade Kanäle… —</option>
                </select>
                <div id="yn-channel-error" style="display:none;font-size:11px;color:#f87171;margin-top:3px"></div>
            </div>
            <div>
                <label class="bh-label" for="yn-role-select">
                    Ping-Rolle <span style="font-weight:400;color:#4f5f80">(optional)</span>
                </label>
                <select id="yn-role-select" class="bh-select">
                    <option value="">— Keine Rolle —</option>
                </select>
            </div>
        </div>

        <div style="margin-bottom:14px">
            <label class="bh-label" for="yn-custom-message">
                Custom Message <span style="font-weight:400;color:#4f5f80">(optional)</span>
            </label>
            <textarea id="yn-custom-message" rows="2" class="bh-textarea"
                placeholder="z.B. @everyone {channel} hat ein neues Video! {title} → {url}"></textarea>
        </div>

        <div class="bh-vars">
            <div class="bh-vars-title">Verfügbare Variablen — klicken zum Kopieren</div>
            <div class="bh-vars-list">
                <span class="bh-var-chip" onclick="ynCopyVar(this,'{channel}')">{channel}</span>
                <span class="bh-var-chip" onclick="ynCopyVar(this,'{title}')">{title}</span>
                <span class="bh-var-chip" onclick="ynCopyVar(this,'{url}')">{url}</span>
            </div>
        </div>

        <button onclick="ynAdd()" id="yn-add-btn" class="bh-btn bh-btn--primary">
            Kanal hinzufügen
        </button>
    </div>
</div>

<!-- ── Channels List ─────────────────────────────────────────────────────────── -->
<div class="bh-card" style="padding:0">
    <div class="bh-card-hdr">
        <div class="bh-card-title" style="margin:0">Konfigurierte Kanäle</div>
        <span id="yn-count-badge" class="bh-tag"><?= count($notifications) ?></span>
    </div>
    <div class="bh-card-body">
        <div id="yn-list">
        <?php if (empty($notifications)): ?>
            <div id="yn-empty" class="text-sm text-gray-400 dark:text-gray-500 text-center py-6">
                Noch kein YouTube-Kanal konfiguriert. Füge einen oben hinzu.
            </div>
        <?php else: ?>
            <?php foreach ($notifications as $n): ?>
            <?php
                $ytId       = (string)$n['yt_channel_id'];
                $ytName     = (string)($n['yt_channel_name'] ?: $ytId);
                $chanId     = (string)$n['channel_id'];
                $lastVideo  = (string)$n['last_video_id'];
                $isEnabled  = (int)$n['is_enabled'] === 1;
                $lastNotify = $n['last_notified_at'] ? date('d.m.Y H:i', strtotime((string)$n['last_notified_at'])) : '—';
            ?>
            <div class="yn-row" id="yn-row-<?= (int)$n['id'] ?>">
                <div style="display:flex;align-items:center;gap:12px;min-width:0">
                    <span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:#FF000022;flex-shrink:0">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="#FF0000">
                            <path d="M23.495 6.205a3.007 3.007 0 0 0-2.088-2.088c-1.87-.501-9.396-.501-9.396-.501s-7.507-.01-9.396.501A3.007 3.007 0 0 0 .527 6.205a31.247 31.247 0 0 0-.522 5.805 31.247 31.247 0 0 0 .522 5.783 3.007 3.007 0 0 0 2.088 2.088c1.868.502 9.396.502 9.396.502s7.506 0 9.396-.502a3.007 3.007 0 0 0 2.088-2.088 31.247 31.247 0 0 0 .5-5.783 31.247 31.247 0 0 0-.5-5.805zM9.609 15.601V8.408l6.264 3.602z"/>
                        </svg>
                    </span>
                    <div style="min-width:0">
                        <div style="font-size:13px;font-weight:600;color:inherit;display:flex;align-items:center;gap:6px">
                            <a href="https://www.youtube.com/channel/<?= $esc($ytId) ?>" target="_blank" rel="noopener"
                               style="color:#FF0000;text-decoration:none"><?= $esc($ytName) ?></a>
                            <?php if (!$isEnabled): ?>
                            <span style="display:inline-block;background:#374151;color:#9ca3af;font-size:10px;font-weight:600;padding:1px 6px;border-radius:4px">DEAKTIVIERT</span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:11px;color:#6b7280;margin-top:2px">
                            ID: <code style="color:#fca5a5"><?= $esc($ytId) ?></code>
                            &nbsp;·&nbsp; Discord: <code style="color:#a5b4fc"><?= $esc($chanId) ?></code>
                            &nbsp;·&nbsp; Letztes Video: <?= $lastVideo ? $esc($lastVideo) : '—' ?>
                            &nbsp;·&nbsp; Zuletzt benachrichtigt: <?= $esc($lastNotify) ?>
                        </div>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;margin-left:12px">
                    <label class="bh-toggle" title="Enable / Disable">
                        <input class="bh-toggle-input" type="checkbox" <?= $isEnabled ? 'checked' : '' ?>
                            onchange="ynToggle(<?= (int)$n['id'] ?>, this.checked)">
                        <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                    </label>
                    <button onclick="ynDelete(<?= (int)$n['id'] ?>)" class="yn-del-btn">
                        Löschen
                    </button>
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
    const BOT_ID       = <?= (int)$botId ?>;
    const CH_API       = '/api/v1/bot_guild_channels.php';
    const ROLES_API    = '/api/v1/bot_guild_roles.php';
    let loadedGuildId  = '';

    // ── Copy variable chip ────────────────────────────────────────────────────
    window.ynCopyVar = function (el, v) {
        const orig = el.textContent;
        const done = () => {
            el.classList.add('is-copied');
            el.textContent = '✓ Kopiert';
            setTimeout(() => { el.classList.remove('is-copied'); el.textContent = orig; }, 1200);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(v).then(done).catch(() => { const t = document.createElement('textarea'); t.value = v; document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove(); done(); });
        } else {
            const t = document.createElement('textarea'); t.value = v; document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove(); done();
        }
    };

    // ── Flash ─────────────────────────────────────────────────────────────────
    function flash(msg, ok) {
        const el = document.getElementById('bh-alert');
        el.className = 'bh-alert bh-alert--' + (ok ? 'ok' : 'err');
        el.textContent = msg;
        el.style.display = '';
        clearTimeout(el._t);
        el._t = setTimeout(() => { el.style.display = 'none'; }, 4500);
    }

    // ── Load text channels ────────────────────────────────────────────────────
    async function loadTextChannels(guildId) {
        const sel   = document.getElementById('yn-channel-select');
        const errEl = document.getElementById('yn-channel-error');
        errEl.style.display = 'none';
        sel.disabled = true;
        sel.innerHTML = '<option value="">— Lade… —</option>';

        try {
            let url = CH_API + '?bot_id=' + BOT_ID;
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
                await loadTextChannels(loadedGuildId);
                return;
            }

            loadedGuildId = json.guild_id || guildId || '';

            const channels = Array.isArray(json.channels) ? json.channels : [];
            sel.innerHTML = '<option value="">— Kanal wählen —</option>';
            for (const c of channels) {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = '#' + c.name;
                sel.appendChild(opt);
            }
        } catch (e) {
            errEl.textContent = 'Netzwerkfehler: ' + e.message;
            errEl.style.display = '';
            sel.innerHTML = '<option value="">— Fehler —</option>';
        } finally {
            sel.disabled = false;
        }
    }

    // ── Load roles ────────────────────────────────────────────────────────────
    async function loadRoles(guildId) {
        const sel = document.getElementById('yn-role-select');
        sel.disabled = true;
        sel.innerHTML = '<option value="">— Lade… —</option>';

        try {
            let url = ROLES_API + '?bot_id=' + BOT_ID;
            if (guildId) url += '&guild_id=' + encodeURIComponent(guildId);

            const res  = await fetch(url);
            const json = await res.json();

            sel.innerHTML = '<option value="">— Keine Rolle —</option>';
            const roles = Array.isArray(json.roles) ? json.roles : [];
            for (const r of roles) {
                if (r.name === '@everyone') continue;
                const opt = document.createElement('option');
                opt.value = r.id;
                opt.textContent = '@' + r.name;
                sel.appendChild(opt);
            }
        } catch (_) {
            sel.innerHTML = '<option value="">— Fehler —</option>';
        } finally {
            sel.disabled = false;
        }
    }

    // Auto-load on page open
    loadTextChannels('').then(() => {
        if (loadedGuildId) loadRoles(loadedGuildId);
    });

    // ── Add ───────────────────────────────────────────────────────────────────
    window.ynAdd = async function () {
        const btn         = document.getElementById('yn-add-btn');
        const ytChannelId = document.getElementById('yn-yt-channel-id').value.trim();
        const ytChanName  = document.getElementById('yn-yt-channel-name').value.trim();
        const chanSel     = document.getElementById('yn-channel-select');
        const channelId   = chanSel.value;
        const roleSel     = document.getElementById('yn-role-select');
        const pingRoleId  = roleSel.value;
        const customMsg   = document.getElementById('yn-custom-message').value.trim();

        if (!ytChannelId) { flash('YouTube Channel ID ist erforderlich.', false); return; }
        if (!channelId)   { flash('Bitte wähle einen Discord-Kanal.', false); return; }

        btn.disabled = true;
        try {
            const res  = await fetch(location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action:          'add',
                    yt_channel_id:   ytChannelId,
                    yt_channel_name: ytChanName,
                    channel_id:      channelId,
                    guild_id:        loadedGuildId,
                    ping_role_id:    pingRoleId,
                    custom_message:  customMsg,
                }),
            });
            const json = await res.json();
            if (json.ok) {
                flash('Kanal hinzugefügt!', true);
                appendRow(json.id, ytChannelId, ytChanName || ytChannelId, channelId, pingRoleId, customMsg);
                document.getElementById('yn-yt-channel-id').value   = '';
                document.getElementById('yn-yt-channel-name').value = '';
                document.getElementById('yn-custom-message').value  = '';
                chanSel.value = '';
                roleSel.value = '';
                updateCount(1);
                const empty = document.getElementById('yn-empty');
                if (empty) empty.remove();
            } else {
                flash(json.error || 'Fehler beim Speichern.', false);
            }
        } catch (e) {
            flash('Netzwerkfehler.', false);
        }
        btn.disabled = false;
    };

    function appendRow(id, ytId, ytName, chanId, pingRole, customMsg) {
        const list = document.getElementById('yn-list');
        const div  = document.createElement('div');
        div.className = 'yn-row';
        div.id = 'yn-row-' + id;
        div.style.cssText = '';
        div.innerHTML = `
            <div style="display:flex;align-items:center;gap:12px;min-width:0">
                <span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:#FF000022;flex-shrink:0">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="#FF0000">
                        <path d="M23.495 6.205a3.007 3.007 0 0 0-2.088-2.088c-1.87-.501-9.396-.501-9.396-.501s-7.507-.01-9.396.501A3.007 3.007 0 0 0 .527 6.205a31.247 31.247 0 0 0-.522 5.805 31.247 31.247 0 0 0 .522 5.783 3.007 3.007 0 0 0 2.088 2.088c1.868.502 9.396.502 9.396.502s7.506 0 9.396-.502a3.007 3.007 0 0 0 2.088-2.088 31.247 31.247 0 0 0 .5-5.783 31.247 31.247 0 0 0-.5-5.805zM9.609 15.601V8.408l6.264 3.602z"/>
                    </svg>
                </span>
                <div style="min-width:0">
                    <div style="font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px">
                        <a href="https://www.youtube.com/channel/${escHtml(ytId)}" target="_blank" rel="noopener"
                           style="color:#FF0000;text-decoration:none">${escHtml(ytName)}</a>
                    </div>
                    <div style="font-size:11px;color:#6b7280;margin-top:2px">
                        ID: <code style="color:#fca5a5">${escHtml(ytId)}</code>
                        &nbsp;·&nbsp; Discord: <code style="color:#a5b4fc">${escHtml(chanId)}</code>
                        &nbsp;·&nbsp; Letztes Video: —
                        &nbsp;·&nbsp; Zuletzt benachrichtigt: —
                    </div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;margin-left:12px">
                <label class="bh-toggle" title="Enable / Disable">
                    <input class="bh-toggle-input" type="checkbox" checked onchange="ynToggle(${id}, this.checked)">
                    <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                </label>
                <button onclick="ynDelete(${id})" class="yn-del-btn">Löschen</button>
            </div>
        `;
        list.prepend(div);
    }

    // ── Delete ────────────────────────────────────────────────────────────────
    window.ynDelete = async function (id) {
        if (!confirm('Diesen YouTube-Kanal wirklich entfernen?')) return;
        try {
            const res  = await fetch(location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', id }),
            });
            const json = await res.json();
            if (json.ok) {
                const row = document.getElementById('yn-row-' + id);
                if (row) row.remove();
                updateCount(-1);
                flash('Gelöscht.', true);
                if (document.getElementById('yn-list').children.length === 0) {
                    document.getElementById('yn-list').innerHTML =
                        '<div id="yn-empty" class="text-sm text-gray-400 dark:text-gray-500 text-center py-6">Noch kein YouTube-Kanal konfiguriert. Füge einen oben hinzu.</div>';
                }
            } else {
                flash(json.error || 'Fehler.', false);
            }
        } catch (e) {
            flash('Netzwerkfehler.', false);
        }
    };

    // ── Toggle ────────────────────────────────────────────────────────────────
    window.ynToggle = async function (id, enabled) {
        try {
            await fetch(location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'toggle', id, is_enabled: enabled }),
            });
        } catch (_) {}
    };

    // ── Count badge ───────────────────────────────────────────────────────────
    function updateCount(delta) {
        const badge = document.getElementById('yn-count-badge');
        if (!badge) return;
        badge.textContent = Math.max(0, (parseInt(badge.textContent, 10) || 0) + delta);
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
})();
</script>
