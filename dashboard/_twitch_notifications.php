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

// ── Auto-migrate: ensure tables exist ─────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS twitch_notifications (
        id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        bot_id           BIGINT UNSIGNED NOT NULL,
        guild_id         VARCHAR(30)     NOT NULL,
        channel_id       VARCHAR(30)     NOT NULL,
        streamer_login   VARCHAR(50)     NOT NULL,
        streamer_id      VARCHAR(20)     NOT NULL DEFAULT '',
        custom_message   TEXT            NULL,
        is_enabled       TINYINT(1)      NOT NULL DEFAULT 1,
        is_live          TINYINT(1)      NOT NULL DEFAULT 0,
        last_notified_at DATETIME        NULL,
        created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_twitch_notify (bot_id, guild_id, streamer_login),
        KEY idx_twitch_notify_bot (bot_id, is_enabled)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS twitch_app_config (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        config_key   VARCHAR(64)  NOT NULL,
        config_value TEXT         NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_config_key (config_key)
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

    // ── Add streamer ──────────────────────────────────────────────────────────
    if ($action === 'add') {
        $streamerLogin = mb_strtolower(trim((string)($data['streamer_login'] ?? '')));
        $channelId     = trim((string)($data['channel_id']     ?? ''));
        $guildId       = trim((string)($data['guild_id']       ?? ''));
        $customMessage = trim((string)($data['custom_message'] ?? ''));

        if ($streamerLogin === '') {
            echo json_encode(['ok' => false, 'error' => 'Streamer username is required.']);
            exit;
        }
        if ($channelId === '') {
            echo json_encode(['ok' => false, 'error' => 'Channel ID is required.']);
            exit;
        }
        if (!preg_match('/^[a-zA-Z0-9_]{1,25}$/', $streamerLogin)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid Twitch username format.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO twitch_notifications
                    (bot_id, guild_id, channel_id, streamer_login, custom_message)
                 VALUES (:bot_id, :guild_id, :channel_id, :streamer_login, :custom_message)"
            );
            $stmt->execute([
                ':bot_id'         => $botId,
                ':guild_id'       => $guildId,
                ':channel_id'     => $channelId,
                ':streamer_login' => $streamerLogin,
                ':custom_message' => $customMessage !== '' ? $customMessage : null,
            ]);
            echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Duplicate') || str_contains($msg, '1062')) {
                echo json_encode(['ok' => false, 'error' => 'This streamer is already added for this server.']);
            } else {
                echo json_encode(['ok' => false, 'error' => $msg]);
            }
        }
        exit;
    }

    // ── Delete streamer ───────────────────────────────────────────────────────
    if ($action === 'delete') {
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid ID.']); exit; }
        try {
            $stmt = $pdo->prepare("DELETE FROM twitch_notifications WHERE id = ? AND bot_id = ?");
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
            $stmt = $pdo->prepare("UPDATE twitch_notifications SET is_enabled = ? WHERE id = ? AND bot_id = ?");
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
        "SELECT * FROM twitch_notifications WHERE bot_id = ? ORDER BY created_at DESC"
    );
    $stmt->execute([$botId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {}

$configSet = false;
try {
    $stmt = $pdo->query("SELECT config_value FROM twitch_app_config WHERE config_key = 'client_id' LIMIT 1");
    $row  = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    $configSet = is_array($row) && trim((string)($row['config_value'] ?? '')) !== '';
} catch (Throwable) {}

$esc = fn(string $v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$modEnabled = bh_mod_is_enabled($pdo, $botId, 'module:twitch');
?>

<?= bh_mod_render($modEnabled, $botId, 'module:twitch', 'Twitch Notifications', 'Twitch-Benachrichtigungen für diesen Bot ein- oder ausschalten.') ?>
<style>
.bh-info-tip{display:inline-flex;align-items:center;justify-content:center;width:15px;height:15px;border-radius:50%;background:rgba(99,102,241,.18);color:#818cf8;font-size:10px;font-weight:700;cursor:default;flex-shrink:0;vertical-align:middle;margin-left:6px;line-height:1}
.bh-info-tip:hover{background:rgba(99,102,241,.32)}
.bh-info-float-tip{position:fixed;transform:translate(-50%,calc(-100% - 8px));background:#1e293b;color:#e2e8f0;font-size:11px;font-weight:400;white-space:nowrap;padding:6px 10px;border-radius:7px;border:1px solid #374461;box-shadow:0 4px 12px rgba(0,0,0,.4);pointer-events:none;opacity:0;transition:opacity .15s;z-index:9999}
.bh-info-float-tip::after{content:'';position:absolute;top:100%;left:50%;transform:translateX(-50%);border:5px solid transparent;border-top-color:#374461}
</style>
<script>
(function(){
  var tipText = <?= json_encode($configSet
    ? 'Twitch API Credentials sind konfiguriert. Notifications sind aktiv.'
    : 'Twitch API Credentials fehlen — bitte im Admin Panel konfigurieren.'
  ) ?>;

  var float = document.createElement('div');
  float.className = 'bh-info-float-tip';
  float.textContent = tipText;
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
  icon.addEventListener('mouseleave', function(){
    float.style.opacity = '0';
  });

  var title = document.querySelector('.bh-mod-feature__title');
  if(title) title.appendChild(icon);
})();
</script>
<div id="bh-mod-body">
<div id="bh-alert" style="display:none"></div>

<!-- ── Add Streamer Card ────────────────────────────────────────────────────── -->
<div class="bh-card" style="padding:0;margin-bottom:20px">
    <div class="bh-card-hdr">
        <div class="bh-card-title" style="margin:0">Streamer hinzufügen</div>
    </div>
    <div class="bh-card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
            <div>
                <label class="bh-label" for="tn-streamer-login">Twitch Benutzername</label>
                <input type="text" id="tn-streamer-login" class="bh-input"
                    placeholder="streamer_name" maxlength="50">
            </div>
            <div>
                <label class="bh-label" for="tn-channel-select">Discord Kanal</label>
                <select id="tn-channel-select" class="bh-select">
                    <option value="">— Kanäle werden geladen… —</option>
                </select>
                <div id="tn-channel-error" style="display:none;font-size:11px;color:#f87171;margin-top:3px"></div>
            </div>
        </div>

        <div style="margin-bottom:14px">
            <label class="bh-label" for="tn-custom-message">
                Custom Message <span style="font-weight:400;color:#4f5f80">(optional)</span>
            </label>
            <textarea id="tn-custom-message" rows="2" class="bh-textarea"
                placeholder="{streamer} ist jetzt live auf Twitch! 🎮"></textarea>
        </div>

        <div class="bh-vars">
            <div class="bh-vars-title">Verfügbare Variablen — klicken zum Kopieren</div>
            <div class="bh-vars-list">
                <span class="bh-var-chip" onclick="tnCopyVar(this,'{streamer}')">{streamer}</span>
                <span class="bh-var-chip" onclick="tnCopyVar(this,'{title}')">{title}</span>
                <span class="bh-var-chip" onclick="tnCopyVar(this,'{game}')">{game}</span>
                <span class="bh-var-chip" onclick="tnCopyVar(this,'{url}')">{url}</span>
            </div>
        </div>

        <button onclick="tnAdd()" id="tn-add-btn" class="bh-btn bh-btn--primary">
            Streamer hinzufügen
        </button>
    </div>
</div>

<!-- ── Streamers List ───────────────────────────────────────────────────────── -->
<div class="bh-card" style="padding:0">
    <div class="bh-card-hdr">
        <div class="bh-card-title" style="margin:0">Konfigurierte Streamer</div>
        <span class="bh-tag"><?= count($notifications) ?></span>
    </div>
    <div class="bh-card-body">
        <div id="tn-list">
        <?php if (empty($notifications)): ?>
            <div id="tn-empty" class="text-sm text-gray-400 dark:text-gray-500 text-center py-6">
                Noch keine Streamer konfiguriert. Füge oben einen hinzu.
            </div>
        <?php else: ?>
            <?php foreach ($notifications as $n): ?>
            <?php
                $login    = (string)$n['streamer_login'];
                $chanId   = (string)$n['channel_id'];
                $isLive   = (int)$n['is_live'] === 1;
                $isEnabled = (int)$n['is_enabled'] === 1;
                $lastNotify = $n['last_notified_at'] ? date('d.m.Y H:i', strtotime((string)$n['last_notified_at'])) : '—';
            ?>
            <div class="tn-row" id="tn-row-<?= (int)$n['id'] ?>">
                <div style="display:flex;align-items:center;gap:12px;min-width:0">
                    <span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:#9147ff22;flex-shrink:0">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="#9147ff"><path d="M11.571 4.714h1.715v5.143H11.57zm4.715 0H18v5.143h-1.714zM6 0L1.714 4.286v15.428h5.143V24l4.286-4.286h3.428L22.286 12V0zm14.571 11.143l-3.428 3.428h-3.429l-3 3v-3H6.857V1.714h13.714z"/></svg>
                    </span>
                    <div style="min-width:0">
                        <div style="font-size:13px;font-weight:600;color:inherit;display:flex;align-items:center;gap:6px">
                            <a href="https://twitch.tv/<?= $esc($login) ?>" target="_blank" rel="noopener"
                               style="color:#9147ff;text-decoration:none;hover:underline"><?= $esc($login) ?></a>
                            <?php if ($isLive): ?>
                            <span style="display:inline-block;background:#ef4444;color:#fff;font-size:10px;font-weight:700;padding:1px 6px;border-radius:4px;letter-spacing:0.05em">LIVE</span>
                            <?php else: ?>
                            <span style="display:inline-block;background:#374151;color:#9ca3af;font-size:10px;font-weight:600;padding:1px 6px;border-radius:4px">OFFLINE</span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:11px;color:#6b7280;margin-top:2px">
                            Kanal: <code style="color:#a5b4fc"><?= $esc($chanId) ?></code>
                            &nbsp;·&nbsp; Zuletzt: <?= $esc($lastNotify) ?>
                            <?php if (!$isEnabled): ?>
                            &nbsp;·&nbsp; <span style="color:#f87171">Deaktiviert</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;margin-left:12px">
                    <label class="bh-toggle" title="Enable / Disable">
                        <input class="bh-toggle-input" type="checkbox" <?= $isEnabled ? 'checked' : '' ?>
                            onchange="tnToggle(<?= (int)$n['id'] ?>, this.checked)">
                        <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                    </label>
                    <button onclick="tnDelete(<?= (int)$n['id'] ?>)" class="tn-del-btn">
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
    const BOT_ID     = <?= (int)$botId ?>;
    const TEXT_CH_API = '/api/v1/bot_guild_channels.php';

    // ── Copy variable chip ────────────────────────────────────────────────────
    window.tnCopyVar = function (el, v) {
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

    // ── Load text channels into select ────────────────────────────────────────
    let loadedGuildId = '';

    async function loadTextChannels(guildId) {
        const sel    = document.getElementById('tn-channel-select');
        const errEl  = document.getElementById('tn-channel-error');
        errEl.style.display = 'none';
        sel.disabled = true;
        sel.innerHTML = '<option value="">— Loading… —</option>';

        try {
            let url = TEXT_CH_API + '?bot_id=' + BOT_ID;
            if (guildId) url += '&guild_id=' + encodeURIComponent(guildId);

            const res  = await fetch(url);
            const json = await res.json();

            if (!json.ok) {
                errEl.textContent = json.error || 'Failed to load channels.';
                errEl.style.display = '';
                sel.innerHTML = '<option value="">— Error loading —</option>';
                return;
            }

            if (json.needs_guild) {
                // Multiple guilds available — use first one automatically or show picker
                if (json.guilds && json.guilds.length > 0) {
                    loadedGuildId = json.guilds[0].id;
                    await loadTextChannels(loadedGuildId);
                } else {
                    sel.innerHTML = '<option value="">— No guilds found —</option>';
                }
                return;
            }

            loadedGuildId = json.guild_id || guildId || '';

            const channels = Array.isArray(json.channels) ? json.channels : [];
            if (channels.length === 0) {
                sel.innerHTML = '<option value="">— No text channels found —</option>';
                return;
            }

            sel.innerHTML = '<option value="">— Select channel —</option>';
            for (const c of channels) {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = '#' + c.name;
                sel.appendChild(opt);
            }
        } catch (e) {
            errEl.textContent = 'Network error: ' + e.message;
            errEl.style.display = '';
            sel.innerHTML = '<option value="">— Error loading —</option>';
        } finally {
            sel.disabled = false;
        }
    }

    // Auto-load on page open
    loadTextChannels('');

    // ── Add streamer ──────────────────────────────────────────────────────────
    window.tnAdd = async function () {
        const btn          = document.getElementById('tn-add-btn');
        const streamerLogin = document.getElementById('tn-streamer-login').value.trim().toLowerCase();
        const channelSel   = document.getElementById('tn-channel-select');
        const channelId    = channelSel.value;
        const channelName  = channelSel.options[channelSel.selectedIndex]?.text || channelId;
        const customMsg    = document.getElementById('tn-custom-message').value.trim();

        if (!streamerLogin) {
            flash('Twitch username is required.', false);
            return;
        }
        if (!channelId) {
            flash('Please select a Discord channel.', false);
            return;
        }

        btn.disabled = true;
        try {
            const res  = await fetch(location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action:         'add',
                    streamer_login: streamerLogin,
                    channel_id:     channelId,
                    guild_id:       loadedGuildId,
                    custom_message: customMsg,
                }),
            });
            const json = await res.json();
            if (json.ok) {
                flash('Streamer added!', true);
                appendRow(json.id, streamerLogin, channelId, channelName, customMsg);
                document.getElementById('tn-streamer-login').value  = '';
                document.getElementById('tn-custom-message').value  = '';
                channelSel.value = '';
                // Remove empty state placeholder
                const empty = document.getElementById('tn-empty');
                if (empty) empty.remove();
            } else {
                flash(json.error || 'Error saving.', false);
            }
        } catch (e) {
            flash('Network error.', false);
        }
        btn.disabled = false;
    };

    function appendRow(id, login, channelId, channelName, customMsg) {
        const list = document.getElementById('tn-list');
        const div  = document.createElement('div');
        div.className = 'tn-row';
        div.id = 'tn-row-' + id;
        div.style.cssText = '';
        div.innerHTML = `
            <div style="display:flex;align-items:center;gap:12px;min-width:0">
                <span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:#9147ff22;flex-shrink:0">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="#9147ff"><path d="M11.571 4.714h1.715v5.143H11.57zm4.715 0H18v5.143h-1.714zM6 0L1.714 4.286v15.428h5.143V24l4.286-4.286h3.428L22.286 12V0zm14.571 11.143l-3.428 3.428h-3.429l-3 3v-3H6.857V1.714h13.714z"/></svg>
                </span>
                <div style="min-width:0">
                    <div style="font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px">
                        <a href="https://twitch.tv/${escHtml(login)}" target="_blank" rel="noopener" style="color:#9147ff;text-decoration:none">${escHtml(login)}</a>
                        <span style="display:inline-block;background:#374151;color:#9ca3af;font-size:10px;font-weight:600;padding:1px 6px;border-radius:4px">OFFLINE</span>
                    </div>
                    <div style="font-size:11px;color:#6b7280;margin-top:2px">
                        Kanal: <code style="color:#a5b4fc">${escHtml(channelId)}</code>
                        &nbsp;·&nbsp; Zuletzt: —
                    </div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;margin-left:12px">
                <label class="bh-toggle" title="Aktivieren / Deaktivieren">
                    <input class="bh-toggle-input" type="checkbox" checked onchange="tnToggle(${id}, this.checked)">
                    <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                </label>
                <button onclick="tnDelete(${id})" class="tn-del-btn">Löschen</button>
            </div>
        `;
        list.prepend(div);
    }

    // ── Delete ────────────────────────────────────────────────────────────────
    window.tnDelete = async function (id) {
        if (!confirm('Streamer-Benachrichtigung wirklich entfernen?')) return;
        try {
            const res  = await fetch(location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', id }),
            });
            const json = await res.json();
            if (json.ok) {
                const row = document.getElementById('tn-row-' + id);
                if (row) row.remove();
                flash('Deleted.', true);
                if (document.getElementById('tn-list').children.length === 0) {
                    document.getElementById('tn-list').innerHTML =
                        '<div id="tn-empty" class="text-sm text-gray-400 dark:text-gray-500 text-center py-6">Noch keine Streamer konfiguriert. Füge oben einen hinzu.</div>';
                }
            } else {
                flash(json.error || 'Error.', false);
            }
        } catch (e) {
            flash('Network error.', false);
        }
    };

    // ── Toggle ────────────────────────────────────────────────────────────────
    window.tnToggle = async function (id, enabled) {
        try {
            await fetch(location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'toggle', id, is_enabled: enabled }),
            });
        } catch (_) {}
    };

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
})();
</script>
