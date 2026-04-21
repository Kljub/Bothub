<?php
declare(strict_types=1);
/** @var int $currentBotId */
/** @var int $userId */

$botId = (int)($currentBotId ?? 0);

require_once dirname(__DIR__) . '/functions/db_functions/commands.php';
require_once dirname(__DIR__) . '/functions/custom_commands.php';

$sbPdo = bh_cc_get_pdo();

/* ── Seed soundboard command keys so slash-sync can find them ── */
$sbCmdKeys = ['soundboard-play', 'soundboard-list', 'soundboard-stop'];
try {
    $sbSeedStmt = $sbPdo->prepare("
        INSERT IGNORE INTO commands (bot_id, command_key, command_type, name, description, is_enabled, created_at, updated_at)
        VALUES (?, ?, 'predefined', ?, NULL, 1, NOW(), NOW())
    ");
    $sbSeeded = 0;
    foreach ($sbCmdKeys as $k) {
        $sbSeedStmt->execute([$botId, $k, $k]);
        $sbSeeded += $sbSeedStmt->rowCount();
    }
    if ($sbSeeded > 0) {
        try { bh_notify_slash_sync($botId); } catch (Throwable) {}
    }
} catch (Throwable) {}

/* ── Load enabled states ── */
$sbCmdEnabled = [];
foreach ($sbCmdKeys as $k) {
    try { $sbCmdEnabled[$k] = bhcmd_is_enabled($sbPdo, $botId, $k); }
    catch (Throwable) { $sbCmdEnabled[$k] = 1; }
}

/* ── AJAX toggle handler ── */
$sbIsAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $sbIsAjax) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    
    // CSRF Check
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf_mismatch']); exit;
    }

    $field = trim((string)($_POST['field'] ?? ''));
    if (in_array($field, $sbCmdKeys, true)) {
        $val = ($_POST['value'] ?? '') === '1' ? 1 : 0;
        bhcmd_set_module_enabled($sbPdo, $botId, $field, $val);
        try { bh_notify_slash_sync($botId); } catch (Throwable) {}
        echo json_encode(['ok' => true, 'field' => $field, 'value' => $val]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'invalid_field']);
    }
    exit;
}
?>
<div class="sb-page">

    <!-- Header -->
    <div class="sb-header">
        <div class="sb-header-left">
            <h1 class="sb-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
                    <path d="M19.07 4.93a10 10 0 0 1 0 14.14"/>
                    <path d="M15.54 8.46a5 5 0 0 1 0 7.07"/>
                </svg>
                Soundboard
            </h1>
            <p class="sb-subtitle">Spiele Sounds ab wenn der Bot in einem Voice Channel ist.</p>
        </div>
        <button type="button" class="sb-btn-primary" id="sb-upload-btn">
            <svg viewBox="0 0 16 16" fill="currentColor" width="14" height="14"><path d="M8 1a.5.5 0 0 1 .5.5v5h5a.5.5 0 0 1 0 1h-5v5a.5.5 0 0 1-1 0v-5h-5a.5.5 0 0 1 0-1h5v-5A.5.5 0 0 1 8 1Z"/></svg>
            Sound hochladen
        </button>
    </div>

    <!-- Commands -->
    <div class="sb-cmd-card">
        <div class="sb-cmd-header">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>
            <div>
                <div class="sb-cmd-kicker">Slash Commands</div>
                <div class="sb-cmd-title">Soundboard-Befehle</div>
            </div>
        </div>
        <div class="sb-cmd-list">
            <?php
            $sbCmdDefs = [
                ['key' => 'soundboard-play', 'name' => '/soundboard-play', 'desc' => 'Sound nach Namen suchen & abspielen (mit Autocomplete)'],
                ['key' => 'soundboard-list', 'name' => '/soundboard-list', 'desc' => 'Alle verfügbaren Sounds auflisten'],
                ['key' => 'soundboard-stop', 'name' => '/soundboard-stop', 'desc' => 'Aktuelle Wiedergabe stoppen'],
            ];
            foreach ($sbCmdDefs as $sbCmd): ?>
            <div class="sb-cmd-row">
                <div class="sb-cmd-row-info">
                    <span class="sb-cmd-row-name"><?= htmlspecialchars($sbCmd['name']) ?></span>
                    <span class="sb-cmd-row-desc"><?= htmlspecialchars($sbCmd['desc']) ?></span>
                </div>
                <label class="sb-toggle">
                    <input type="checkbox" <?= ($sbCmdEnabled[$sbCmd['key']] ?? 1) ? 'checked' : '' ?>
                        data-field="<?= htmlspecialchars($sbCmd['key']) ?>"
                        onchange="sbToggleCmd(this)">
                    <span class="sb-toggle-track"></span>
                </label>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Stats -->
    <div class="sb-stats">
        <div class="sb-stat"><span class="sb-stat-value" id="sb-count-total">—</span><span class="sb-stat-label">Sounds</span></div>
        <div class="sb-stat"><span class="sb-stat-value" id="sb-stat-vc">—</span><span class="sb-stat-label">Voice Channel</span></div>
        <div class="sb-stat"><span class="sb-stat-value" id="sb-stat-playing">—</span><span class="sb-stat-label">Status</span></div>
    </div>

    <!-- VC bar -->
    <div class="sb-vc-bar" id="sb-vc-bar">
        <svg viewBox="0 0 16 16" fill="currentColor" width="16" height="16"><path d="M11.536 14.01A8.473 8.473 0 0 0 14.026 8a8.473 8.473 0 0 0-2.49-6.01l-.708.707A7.476 7.476 0 0 1 13.025 8c0 2.071-.84 3.946-2.197 5.303l.708.707Z"/><path d="M10.121 12.596A6.48 6.48 0 0 0 12.025 8a6.48 6.48 0 0 0-1.904-4.596l-.707.707A5.483 5.483 0 0 1 11.025 8a5.483 5.483 0 0 1-1.61 3.89l.706.706Z"/><path d="M8.707 11.182A4.486 4.486 0 0 0 10.025 8a4.486 4.486 0 0 0-1.318-3.182L8 5.525A3.489 3.489 0 0 1 9.025 8 3.49 3.49 0 0 1 8 10.475l.707.707ZM6.717 3.55A.5.5 0 0 1 7 4v8a.5.5 0 0 1-.812.39L3.825 10.5H1.5A.5.5 0 0 1 1 10V6a.5.5 0 0 1 .5-.5h2.325l2.363-1.89a.5.5 0 0 1 .529-.06Z"/></svg>
        <span id="sb-vc-label">Nicht verbunden</span>
        <div class="sb-vc-actions" id="sb-vc-actions">
            <select class="sb-select" id="sb-guild-select"><option value="">Server wählen…</option></select>
            <select class="sb-select" id="sb-vc-select" disabled><option value="">Voice Channel…</option></select>
            <button type="button" class="sb-btn-sm" id="sb-join-btn">Beitreten</button>
            <button type="button" class="sb-btn-sm sb-btn-danger" id="sb-leave-btn" style="display:none">Verlassen</button>
        </div>
    </div>

    <!-- Sound grid -->
    <div class="sb-grid" id="sb-grid">
        <div class="sb-empty" id="sb-empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="48" height="48"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
            <p>Noch keine Sounds hochgeladen.</p>
            <button type="button" class="sb-btn-primary" id="sb-empty-upload-btn">Ersten Sound hochladen</button>
        </div>
    </div>

    <!-- Upload modal -->
    <div class="sb-overlay" id="sb-modal-overlay">
        <div class="sb-modal">
            <div class="sb-modal-header">
                <span>Sound hochladen</span>
                <button type="button" class="sb-modal-close" id="sb-modal-close">✕</button>
            </div>
            <div class="sb-modal-body">
                <div class="sb-drop-zone" id="sb-drop-zone">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="32" height="32"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <p>Datei hierher ziehen oder</p>
                    <label class="sb-btn-sm" style="cursor:pointer">
                        Datei wählen
                        <input type="file" id="sb-file-input" accept="audio/*,.mp3,.wav,.ogg,.flac,.aac" style="display:none">
                    </label>
                    <span class="sb-drop-hint">MP3, WAV, OGG, FLAC, AAC · max. 8 MB</span>
                    <div class="sb-selected-file" id="sb-selected-file" style="display:none"></div>
                </div>
                <div class="sb-form-row">
                    <label>Anzeigename</label>
                    <input type="text" id="sb-sound-name" placeholder="z.B. Airhorn" maxlength="64">
                </div>
                <div class="sb-form-row">
                    <label>Emoji (optional)</label>
                    <input type="text" id="sb-sound-emoji" placeholder="🎺" maxlength="8">
                </div>
                <div class="sb-form-row">
                    <label>Lautstärke</label>
                    <div class="sb-volume-row">
                        <input type="range" id="sb-sound-volume" min="1" max="200" value="100">
                        <span id="sb-volume-display">100%</span>
                    </div>
                </div>
                <div id="sb-upload-error" class="sb-error" style="display:none"></div>
            </div>
            <div class="sb-modal-footer">
                <button type="button" class="sb-btn-secondary" id="sb-modal-cancel">Abbrechen</button>
                <button type="button" class="sb-btn-primary" id="sb-modal-save">
                    <span id="sb-modal-save-text">Hochladen</span>
                </button>
            </div>
        </div>
    </div>

</div>


<script>
(function () {
    const BOT_ID   = <?= $botId ?>;
    const API_BASE = '/api/v1/soundboard.php?bot_id=' + BOT_ID;

    // ── DOM refs ────────────────────────────────────────────────────
    const grid       = document.getElementById('sb-grid');
    const empty      = document.getElementById('sb-empty');
    const overlay    = document.getElementById('sb-modal-overlay');
    const guildSel   = document.getElementById('sb-guild-select');
    const vcSel      = document.getElementById('sb-vc-select');
    const vcLabel    = document.getElementById('sb-vc-label');
    const joinBtn    = document.getElementById('sb-join-btn');
    const leaveBtn   = document.getElementById('sb-leave-btn');
    const saveBtn    = document.getElementById('sb-modal-save');
    const saveTxt    = document.getElementById('sb-modal-save-text');
    const uploadErr  = document.getElementById('sb-upload-error');

    let sounds       = [];
    let guilds       = [];
    let vcStates     = [];          // active connections
    let playingId    = null;        // sound id currently playing

    // ── Toast ───────────────────────────────────────────────────────
    function toast(msg, type = 'info') {
        let c = document.getElementById('sb-toasts');
        if (!c) { c = document.createElement('div'); c.id = 'sb-toasts'; c.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);z-index:99999;display:flex;flex-direction:column;align-items:center;gap:8px;pointer-events:none'; document.body.appendChild(c); }
        const t = document.createElement('div');
        const bg = type === 'success' ? '#16a34a' : type === 'error' ? '#dc2626' : '#2563eb';
        t.style.cssText = `padding:10px 20px;border-radius:8px;font-size:13px;font-weight:600;color:#fff;background:${bg};box-shadow:0 4px 16px rgba(0,0,0,.4);opacity:0;transform:translateY(10px);transition:opacity .22s,transform .22s`;
        t.textContent = msg; c.appendChild(t);
        requestAnimationFrame(() => requestAnimationFrame(() => { t.style.opacity = '1'; t.style.transform = 'translateY(0)'; }));
        setTimeout(() => { t.style.opacity = '0'; t.addEventListener('transitionend', () => t.remove(), { once: true }); }, 3500);
    }

    // ── API helpers ─────────────────────────────────────────────────
    async function apiGet(action) {
        const r = await fetch(API_BASE + '&action=' + action);
        return r.json();
    }
    async function apiPost(action, body) {
        const r = await fetch(API_BASE + '&action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        return r.json();
    }

    // ── Load sounds + guilds + VC status ────────────────────────────
    async function loadAll() {
        const [listData, guildsData] = await Promise.all([
            apiGet('list'),
            apiPost('guilds', {}),
        ]);

        sounds   = listData.sounds || [];
        vcStates = listData.vc?.states || [];

        guilds = guildsData.guilds || [];
        populateGuildSelect();
        updateVcBar();
        renderGrid();
    }

    function populateGuildSelect() {
        guildSel.innerHTML = '<option value="">Server wählen…</option>';
        guilds.forEach(g => {
            const o = document.createElement('option');
            o.value = g.id; o.textContent = g.name;
            guildSel.appendChild(o);
        });
    }

    guildSel.addEventListener('change', () => {
        const gId = guildSel.value;
        vcSel.innerHTML = '<option value="">Voice Channel…</option>';
        vcSel.disabled = !gId;
        if (!gId) return;
        const g = guilds.find(x => x.id === gId);
        (g?.voice_channels || []).forEach(ch => {
            const o = document.createElement('option');
            o.value = ch.id; o.textContent = '🔊 ' + ch.name;
            vcSel.appendChild(o);
        });
        // Pre-select if already connected in this guild
        const active = vcStates.find(s => s.guild_id === gId);
        if (active) vcSel.value = active.channel_id;
    });

    function updateVcBar() {
        if (vcStates.length === 0) {
            vcLabel.textContent = 'Nicht verbunden';
            joinBtn.style.display = '';
            leaveBtn.style.display = 'none';
            document.getElementById('sb-stat-vc').textContent = '—';
            document.getElementById('sb-stat-playing').textContent = '—';
        } else {
            const s = vcStates[0];
            vcLabel.textContent = s.guild_name + ' › ' + s.channel_name;
            joinBtn.style.display = 'none';
            leaveBtn.style.display = '';
            document.getElementById('sb-stat-vc').textContent = s.channel_name;
            document.getElementById('sb-stat-playing').textContent = s.playing ? '▶ Spielt' : '⏸ Bereit';
        }
    }

    // ── VC join / leave ─────────────────────────────────────────────
    joinBtn.addEventListener('click', async () => {
        const guildId   = guildSel.value;
        const channelId = vcSel.value;
        if (!guildId || !channelId) { toast('Bitte Server und Voice Channel wählen.', 'error'); return; }
        joinBtn.disabled = true;
        const r = await apiPost('join', { guild_id: guildId, channel_id: channelId });
        joinBtn.disabled = false;
        if (r.ok) { toast('Voice Channel beigetreten!', 'success'); await loadAll(); }
        else       toast('Fehler: ' + (r.error || r.message), 'error');
    });

    leaveBtn.addEventListener('click', async () => {
        const guildId = vcStates[0]?.guild_id || guildSel.value;
        if (!guildId) return;
        const r = await apiPost('leave', { guild_id: guildId });
        if (r.ok) { toast('Voice Channel verlassen.', 'info'); await loadAll(); }
        else       toast('Fehler: ' + (r.error || r.message), 'error');
    });

    // ── Browser audio preview ───────────────────────────────────────
    let previewAudio   = null;
    let previewingId   = null;

    function previewSound(s) {
        const previewUrl = API_BASE + '&action=preview&sound_id=' + s.id;

        if (previewingId == s.id) {
            // Toggle: stop if already playing this one
            if (previewAudio) { previewAudio.pause(); previewAudio.currentTime = 0; }
            previewingId = null;
            updatePreviewBtns();
            return;
        }

        if (previewAudio) { previewAudio.pause(); }
        previewAudio          = new Audio(previewUrl);
        previewAudio.volume   = Math.min(s.volume / 100, 1.0);
        previewingId          = s.id;
        updatePreviewBtns();

        previewAudio.addEventListener('ended', () => {
            previewingId = null;
            updatePreviewBtns();
        });
        previewAudio.play().catch(() => {
            previewingId = null;
            updatePreviewBtns();
        });
    }

    function updatePreviewBtns() {
        grid.querySelectorAll('.sb-card-preview').forEach(btn => {
            const id = btn.dataset.id;
            const active = previewingId == id;
            btn.title = active ? 'Vorschau stoppen' : 'Vorschau im Browser';
            btn.innerHTML = active
                ? '<svg viewBox="0 0 16 16" fill="currentColor" width="11" height="11"><path d="M3 2h3v12H3V2Zm7 0h3v12h-3V2Z"/></svg>'
                : '<svg viewBox="0 0 16 16" fill="currentColor" width="11" height="11"><path d="M11 7.5a1 1 0 0 1 0 1.732l-7 4A1 1 0 0 1 2 12.5v-9a1 1 0 0 1 1.5-.866l7 4Z"/></svg>';
            btn.classList.toggle('is-previewing', active);
        });
    }

    // ── Sound grid ──────────────────────────────────────────────────
    function renderGrid() {
        grid.querySelectorAll('.sb-card').forEach(c => c.remove());
        document.getElementById('sb-count-total').textContent = sounds.length;
        empty.style.display = sounds.length === 0 ? '' : 'none';

        sounds.forEach(s => {
            const card = document.createElement('div');
            card.className = 'sb-card';
            card.dataset.id = s.id;
            card.innerHTML = `
                <div class="sb-card-emoji">${s.emoji || '🔊'}</div>
                <div class="sb-card-name" title="${esc(s.name)}">${esc(s.name)}</div>
                <div class="sb-card-meta">Vol: ${s.volume}%</div>
                <div class="sb-card-actions">
                    <button class="sb-card-preview" data-id="${s.id}" title="Vorschau im Browser">
                        <svg viewBox="0 0 16 16" fill="currentColor" width="11" height="11"><path d="M11 7.5a1 1 0 0 1 0 1.732l-7 4A1 1 0 0 1 2 12.5v-9a1 1 0 0 1 1.5-.866l7 4Z"/></svg>
                    </button>
                    <button class="sb-card-play ${playingId == s.id ? 'is-playing' : ''}" data-id="${s.id}">
                        ${playingId == s.id
                            ? '<svg viewBox="0 0 16 16" fill="currentColor" width="12" height="12"><path d="M3 2h3v12H3V2Zm7 0h3v12h-3V2Z"/></svg> Stopp'
                            : '<svg viewBox="0 0 16 16" fill="currentColor" width="12" height="12"><path d="M3 2l10 6-10 6V2z"/></svg> Abspielen'}
                    </button>
                </div>
                <button class="sb-card-delete" data-id="${s.id}" title="Löschen">✕</button>`;

            card.querySelector('.sb-card-preview').addEventListener('click', () => previewSound(s));
            card.querySelector('.sb-card-play').addEventListener('click', () => playSound(s));
            card.querySelector('.sb-card-delete').addEventListener('click', () => deleteSound(s.id));
            grid.appendChild(card);
        });

        updatePreviewBtns();
    }

    async function playSound(s) {
        if (vcStates.length === 0) {
            toast('Bot ist in keinem Voice Channel. Bitte zuerst beitreten.', 'error'); return;
        }
        const vc = vcStates[0];

        if (playingId == s.id) {
            // Stop
            const r = await apiPost('stop', { guild_id: vc.guild_id });
            if (r.ok) { playingId = null; renderGrid(); document.getElementById('sb-stat-playing').textContent = '⏸ Bereit'; }
            else toast('Fehler: ' + (r.error || r.message), 'error');
            return;
        }

        const btn = grid.querySelector(`.sb-card-play[data-id="${s.id}"]`);
        if (btn) btn.disabled = true;

        const r = await apiPost('play', {
            sound_id:   parseInt(s.id),
            guild_id:   vc.guild_id,
            channel_id: vc.channel_id,
        });

        if (btn) btn.disabled = false;

        if (r.ok) {
            playingId = s.id;
            renderGrid();
            document.getElementById('sb-stat-playing').textContent = '▶ ' + s.name.slice(0, 12);
        } else {
            toast('Fehler: ' + (r.error || r.message), 'error');
        }
    }

    async function deleteSound(id) {
        if (!confirm('Sound wirklich löschen?')) return;
        const r = await apiPost('delete', { sound_id: parseInt(id) });
        if (r.ok) { toast('Sound gelöscht.', 'info'); await loadAll(); }
        else       toast('Fehler: ' + (r.error || r.message), 'error');
    }

    // ── Upload modal ────────────────────────────────────────────────
    let pickedFile = null;

    function openModal()  { overlay.classList.add('is-open'); }
    function closeModal() { overlay.classList.remove('is-open'); resetForm(); }

    document.getElementById('sb-upload-btn').addEventListener('click', openModal);
    document.getElementById('sb-empty-upload-btn').addEventListener('click', openModal);
    document.getElementById('sb-modal-close').addEventListener('click', closeModal);
    document.getElementById('sb-modal-cancel').addEventListener('click', closeModal);
    overlay.addEventListener('click', e => { if (e.target === overlay) closeModal(); });

    const volSlider = document.getElementById('sb-sound-volume');
    const volDisp   = document.getElementById('sb-volume-display');
    volSlider.addEventListener('input', () => { volDisp.textContent = volSlider.value + '%'; });

    const dropZone  = document.getElementById('sb-drop-zone');
    const fileInput = document.getElementById('sb-file-input');
    const selFile   = document.getElementById('sb-selected-file');
    const nameInput = document.getElementById('sb-sound-name');

    function setFile(file) {
        pickedFile = file;
        selFile.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
        selFile.style.display = '';
        if (!nameInput.value) nameInput.value = file.name.replace(/\.[^.]+$/, '').replace(/[-_]/g, ' ');
    }
    fileInput.addEventListener('change', () => { if (fileInput.files[0]) setFile(fileInput.files[0]); });
    dropZone.addEventListener('dragover',  e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
    dropZone.addEventListener('drop', e => {
        e.preventDefault(); dropZone.classList.remove('drag-over');
        if (e.dataTransfer.files[0]) setFile(e.dataTransfer.files[0]);
    });

    function resetForm() {
        pickedFile = null; fileInput.value = ''; nameInput.value = '';
        document.getElementById('sb-sound-emoji').value = '';
        volSlider.value = 100; volDisp.textContent = '100%';
        selFile.style.display = 'none'; selFile.textContent = '';
        uploadErr.style.display = 'none'; saveBtn.disabled = false; saveTxt.textContent = 'Hochladen';
    }

    saveBtn.addEventListener('click', async () => {
        if (!pickedFile) { showUploadErr('Bitte wähle eine Audiodatei aus.'); return; }
        if (pickedFile.size > 8 * 1024 * 1024) { showUploadErr('Datei zu groß (max. 8 MB).'); return; }

        const fd = new FormData();
        fd.append('file',   pickedFile);
        fd.append('name',   nameInput.value.trim() || pickedFile.name);
        fd.append('emoji',  document.getElementById('sb-sound-emoji').value.trim());
        fd.append('volume', volSlider.value);

        saveBtn.disabled = true; saveTxt.textContent = 'Wird hochgeladen…';
        uploadErr.style.display = 'none';

        try {
            const r = await fetch(API_BASE + '&action=upload', { method: 'POST', body: fd });
            const j = await r.json();
            if (j.ok) {
                toast('Sound hochgeladen!', 'success');
                closeModal();
                await loadAll();
            } else {
                showUploadErr(j.error || 'Unbekannter Fehler.');
                saveBtn.disabled = false; saveTxt.textContent = 'Hochladen';
            }
        } catch (e) {
            showUploadErr('Netzwerkfehler: ' + e.message);
            saveBtn.disabled = false; saveTxt.textContent = 'Hochladen';
        }
    });

    function showUploadErr(msg) { uploadErr.textContent = msg; uploadErr.style.display = ''; }

    function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    // ── Init ────────────────────────────────────────────────────────
    loadAll();
})();

// ── Command toggles ─────────────────────────────────────────────────────────
function sbToggleCmd(el) {
    const field = el.dataset.field;
    const val   = el.checked ? '1' : '0';
    const fd    = new FormData();
    fd.append('field', field);
    fd.append('value', val);
    fd.append('csrf_token', '<?= $_SESSION['csrf_token'] ?? '' ?>');
    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd,
    })
    .then(function (r) { return r.json(); })
    .then(function (j) {
        if (!j.ok) { el.checked = !el.checked; alert('Fehler: ' + (j.error || 'unbekannt')); }
    })
    .catch(function () { el.checked = !el.checked; });
}
</script>
