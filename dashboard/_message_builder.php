<?php
declare(strict_types=1);
/** @var int $currentBotId */

$botId = (int)($currentBotId ?? 0);

require_once dirname(__DIR__) . '/functions/db_functions/db.php';
require_once dirname(__DIR__) . '/functions/db_functions/message_builder.php';
require_once dirname(__DIR__) . '/functions/db_functions/commands.php';
require_once dirname(__DIR__) . '/functions/custom_commands.php';

$mbPdo = bh_get_pdo();
$esc   = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

try { bhmb_ensure_table($mbPdo); } catch (Throwable) {}

/* ── Seed slash command key ─────────────────────────────────────────────── */
try {
    $mbPdo->prepare("
        INSERT IGNORE INTO commands (bot_id, command_key, command_type, name, description, is_enabled, created_at, updated_at)
        VALUES (?, 'message-send', 'predefined', 'message-send', NULL, 1, NOW(), NOW())
    ")->execute([$botId]);
} catch (Throwable) {}

/* ── AJAX ──────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $raw    = (string)file_get_contents('php://input');
    $data   = json_decode($raw, true) ?? [];
    $action = (string)($data['action'] ?? '');

    if ($action === 'save') {
        $editId  = isset($data['id']) ? (int)$data['id'] : null;
        $name    = mb_substr(trim((string)($data['name']    ?? '')), 0, 100);
        $tag     = mb_substr(trim((string)($data['tag']     ?? '')), 0, 50);
        $isEmbed = (isset($data['is_embed']) && $data['is_embed']) ? 1 : 0;
        $plain   = trim((string)($data['plain_text']     ?? ''));
        $author  = mb_substr(trim((string)($data['embed_author']    ?? '')), 0, 256);
        $thumb   = mb_substr(trim((string)($data['embed_thumbnail'] ?? '')), 0, 512);
        $title   = mb_substr(trim((string)($data['embed_title']     ?? '')), 0, 256);
        $body    = trim((string)($data['embed_body']  ?? ''));
        $image   = mb_substr(trim((string)($data['embed_image'] ?? '')), 0, 512);
        $color   = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($data['embed_color'] ?? ''))
                    ? (string)$data['embed_color'] : '#5865f2';
        $embedUrl = mb_substr(trim((string)($data['embed_url'] ?? '')), 0, 512);

        if ($name === '') { echo json_encode(['ok' => false, 'error' => 'Name ist erforderlich.']); exit; }

        try {
            $id = bhmb_save($mbPdo, $botId, [
                'name'      => $name, 'tag'    => $tag,
                'is_embed'  => $isEmbed, 'plain_text' => $plain,
                'author'    => $author, 'thumb'  => $thumb, 'title' => $title,
                'body'      => $body,   'image'  => $image, 'color' => $color,
                'embed_url' => $embedUrl,
            ], $editId);
            $tpl = bhmb_get($mbPdo, $botId, $id);
            echo json_encode(['ok' => true, 'id' => $id, 'template' => $tpl]);
        } catch (Throwable $e) {
            $msg = str_contains($e->getMessage(), 'Duplicate') ? 'Ein Template mit diesem Namen existiert bereits.' : $e->getMessage();
            echo json_encode(['ok' => false, 'error' => $msg]);
        }
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($data['id'] ?? 0);
        try {
            bhmb_delete($mbPdo, $botId, $id);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'invalid_action']);
    exit;
}

/* ── Load templates ────────────────────────────────────────────────────── */
$templates = [];
try { $templates = bhmb_list($mbPdo, $botId); } catch (Throwable) {}
?>

<div class="mb-page">

    <!-- Flash -->
    <div id="bh-alert" class="bh-alert bh-alert--inline" style="display:none"></div>

    <!-- Header -->
    <div class="mb-header">
        <div>
            <div class="bh-section-label">Message Builder</div>
            <div class="mb-header-title">Templates</div>
            <div class="mb-header-desc">Wiederverwendbare Nachrichten-Templates für deinen Bot.</div>
        </div>
    </div>

    <!-- ── Create form ──────────────────────────────────────────────────── -->
    <div class="bh-card">
        <div class="bh-card-title">Neues Template</div>
        <div class="bh-card-desc">Verwende Variablen wie <code style="color:#a5b4fc;font-size:12px">{user.mention}</code> für dynamische Inhalte.</div>

        <!-- Name + Tag -->
        <div class="bh-field mb-field-row">
            <div>
                <div class="bh-label">Name <span style="color:#f87171">*</span></div>
                <input type="text" id="mb-name" class="bh-input" placeholder="z.B. Willkommens-Nachricht" maxlength="100">
            </div>
            <div>
                <div class="bh-label">Kategorie / Tag</div>
                <input type="text" id="mb-tag" class="bh-input" placeholder="z.B. welcome, info" maxlength="50">
            </div>
        </div>

        <!-- Embed toggle -->
        <div class="bh-toggle-row">
            <div class="bh-toggle-row__info">
                <div class="bh-toggle-row__title">Embed-Nachricht</div>
                <div class="bh-toggle-row__desc">Formatierte Nachricht mit Farbe, Titel und Feldern.</div>
            </div>
            <label class="bh-toggle">
                <input class="bh-toggle-input" type="checkbox" id="mb-is-embed" checked>
                <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
            </label>
        </div>

        <!-- Embed builder -->
        <div id="mb-embed-panel" style="margin-top:14px">
            <div class="mb-embed-panel">
                <div class="mb-embed-inner">
                    <div id="mb-embed-stripe" class="mb-embed-stripe" title="Farbe ändern" onclick="document.getElementById('mb-color-picker').click()"></div>
                    <div class="mb-embed-body">
                        <div class="mb-embed-row">
                            <div>
                                <div class="bh-embed-label">Author</div>
                                <input type="text" id="mb-embed-author" class="bh-embed-input" placeholder="Autor-Name">
                            </div>
                            <div>
                                <div class="bh-embed-label">Thumbnail URL</div>
                                <input type="text" id="mb-embed-thumb" class="bh-embed-input" placeholder="https://...">
                            </div>
                        </div>
                        <div>
                            <div class="bh-embed-label">Titel</div>
                            <input type="text" id="mb-embed-title" class="bh-embed-input" placeholder="Template-Titel">
                        </div>
                        <div>
                            <div class="bh-embed-label">Beschreibung</div>
                            <textarea id="mb-embed-body" class="bh-embed-textarea" placeholder="Nachrichtentext… {user.mention} sind hier erlaubt."></textarea>
                        </div>
                        <div>
                            <div class="bh-embed-label">Bild URL</div>
                            <input type="text" id="mb-embed-image" class="bh-embed-input" placeholder="https://...">
                        </div>
                        <div class="mb-embed-footer-row">
                            <div>
                                <div class="bh-embed-label">Farbe</div>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <span id="mb-color-swatch" class="mb-color-swatch" style="background:#5865f2" onclick="document.getElementById('mb-color-picker').click()"></span>
                                    <input type="color" id="mb-color-picker" value="#5865f2" style="display:none">
                                    <input type="text" id="mb-color-hex" class="bh-embed-input" value="#5865f2" placeholder="#5865f2" style="width:88px;font-family:monospace">
                                </div>
                            </div>
                            <div>
                                <div class="bh-embed-label">Embed URL</div>
                                <input type="text" id="mb-embed-url" class="bh-embed-input" placeholder="https://...">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Plain text panel -->
        <div id="mb-plain-panel" style="display:none;margin-top:14px">
            <div class="bh-label" style="margin-bottom:6px">Text</div>
            <textarea id="mb-plain-text" class="mb-textarea" placeholder="Nachrichtentext..."></textarea>
        </div>

        <!-- Variables -->
        <div class="mb-vars" style="margin-top:16px">
            <div class="mb-vars-title">Verfügbare Variablen — klicken zum Kopieren</div>
            <div class="mb-vars-list">
                <?php foreach ([
                    '{user}', '{user.mention}', '{user.id}', '{user.name}', '{user.tag}',
                    '{guild.name}', '{guild.id}', '{guild.memberCount}',
                    '{channel}', '{channel.name}',
                    '{date}', '{time}',
                ] as $v): ?>
                <span class="mb-var-chip" onclick="mbCopyVar(this, '<?= $esc($v) ?>')"><?= $esc($v) ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Save -->
        <div class="mb-btn-row">
            <button class="bh-btn bh-btn--primary" id="mb-save-btn" onclick="mbSave()">Template speichern</button>
        </div>
    </div>

    <!-- ── Template list ────────────────────────────────────────────────── -->
    <div class="bh-card" style="padding:0">
        <div class="bh-card-hdr">
            <div>
                <div class="bh-card-title" style="margin-bottom:0">Vorhandene Templates</div>
            </div>
            <span id="mb-count-badge" class="bh-tag"><?= count($templates) ?></span>
        </div>
        <div style="padding:16px 22px">
            <div id="mb-list" class="mb-list">
                <?php foreach ($templates as $tpl): ?>
                <?php
                    $tid      = (int)$tpl['id'];
                    $tname    = (string)$tpl['name'];
                    $ttag     = (string)$tpl['tag'];
                    $tisEmbed = (int)$tpl['is_embed'];
                    $tcolor   = (string)$tpl['embed_color'];
                ?>
                <div class="bh-list-card" id="mb-row-<?= $tid ?>">
                    <div style="display:flex;align-items:center;gap:10px;flex:1;min-width:0">
                        <?php if ($tisEmbed): ?>
                        <div style="width:3px;height:32px;background:<?= $esc($tcolor) ?>;border-radius:2px;flex-shrink:0"></div>
                        <?php endif; ?>
                        <div class="mb-list-info">
                            <div class="bh-list-name"><?= $esc($tname) ?></div>
                            <div class="bh-list-meta">
                                <?= $tisEmbed ? 'Embed' : 'Plain text' ?>
                                <?php if ($ttag !== ''): ?>
                                <span class="mb-tag-badge"><?= $esc($ttag) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="mb-list-actions">
                        <button class="mb-btn-edit" onclick="mbEdit(<?= $tid ?>)">Bearbeiten</button>
                        <button class="bh-btn bh-btn--danger bh-btn--sm" onclick="mbDelete(<?= $tid ?>)">Löschen</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div id="mb-empty" class="mb-empty-hint"<?= !empty($templates) ? ' style="display:none"' : '' ?>>
                Noch keine Templates vorhanden. Erstelle dein erstes Template oben.
            </div>
        </div>
    </div>

</div>

<!-- ── Edit Modal ────────────────────────────────────────────────────────── -->
<div id="mb-modal-backdrop" class="mb-modal-backdrop" style="display:none" onclick="if(event.target===this)mbCloseModal()">
    <div class="mb-modal">
        <div class="mb-modal-title">Template bearbeiten</div>

        <div class="bh-field mb-field-row">
            <div>
                <div class="bh-label">Name <span style="color:#f87171">*</span></div>
                <input type="text" id="mb-edit-name" class="bh-input" maxlength="100">
            </div>
            <div>
                <div class="bh-label">Kategorie / Tag</div>
                <input type="text" id="mb-edit-tag" class="bh-input" maxlength="50">
            </div>
        </div>

        <div class="bh-toggle-row">
            <div>
                <div class="bh-toggle-row__title">Embed-Nachricht</div>
                <div class="bh-toggle-row__desc">Aktiviere für eine formatierte Embed-Nachricht.</div>
            </div>
            <label class="bh-toggle" style="margin-left:16px">
                <input class="bh-toggle-input" type="checkbox" id="mb-edit-is-embed">
                <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
            </label>
        </div>

        <div id="mb-edit-embed-panel">
            <div class="mb-embed-panel">
                <div class="mb-embed-inner">
                    <div id="mb-edit-embed-stripe" class="mb-embed-stripe" onclick="document.getElementById('mb-edit-color-picker').click()"></div>
                    <div class="mb-embed-body">
                        <div class="mb-embed-row">
                            <div>
                                <div class="bh-embed-label">Author</div>
                                <input type="text" id="mb-edit-author" class="bh-embed-input" placeholder="Autor-Name">
                            </div>
                            <div>
                                <div class="bh-embed-label">Thumbnail URL</div>
                                <input type="text" id="mb-edit-thumb" class="bh-embed-input" placeholder="https://...">
                            </div>
                        </div>
                        <div>
                            <div class="bh-embed-label">Titel</div>
                            <input type="text" id="mb-edit-title" class="bh-embed-input">
                        </div>
                        <div>
                            <div class="bh-embed-label">Beschreibung</div>
                            <textarea id="mb-edit-body" class="bh-embed-textarea"></textarea>
                        </div>
                        <div>
                            <div class="bh-embed-label">Bild URL</div>
                            <input type="text" id="mb-edit-image" class="bh-embed-input" placeholder="https://...">
                        </div>
                        <div class="mb-embed-footer-row">
                            <div>
                                <div class="bh-embed-label">Farbe</div>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <span id="mb-edit-color-swatch" class="mb-color-swatch" onclick="document.getElementById('mb-edit-color-picker').click()"></span>
                                    <input type="color" id="mb-edit-color-picker" style="display:none">
                                    <input type="text" id="mb-edit-color-hex" class="bh-embed-input" style="width:88px;font-family:monospace">
                                </div>
                            </div>
                            <div>
                                <div class="bh-embed-label">Embed URL</div>
                                <input type="text" id="mb-edit-url" class="bh-embed-input" placeholder="https://...">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="mb-edit-plain-panel" style="display:none">
            <div class="bh-label" style="margin-bottom:6px">Text</div>
            <textarea id="mb-edit-plain" class="mb-textarea"></textarea>
        </div>

        <div class="mb-btn-row">
            <button class="bh-btn bh-btn--secondary" onclick="mbCloseModal()">Abbrechen</button>
            <button class="bh-btn bh-btn--primary" id="mb-edit-save-btn" onclick="mbEditSave()">Speichern</button>
        </div>
    </div>
</div>

<script>
(function () {
    const BOT_ID  = <?= (int)$botId ?>;
    let editingId = null;

    // ── Templates data cache (for edit modal) ──────────────────────────
    const templates = <?= json_encode(array_values($templates)) ?>;
    const tplMap = Object.fromEntries(templates.map(t => [t.id, t]));

    // ── Flash ────────────────────────────────────────────────────────────
    function flash(msg, ok) {
        const el = document.getElementById('bh-alert');
        el.className = 'bh-alert bh-alert--inline ' + (ok ? 'bh-alert--ok' : 'bh-alert--err');
        el.textContent = msg;
        el.style.display = 'block';
        clearTimeout(el._t);
        el._t = setTimeout(() => { el.style.display = 'none'; }, 4000);
    }

    // ── Color sync helpers ────────────────────────────────────────────────
    function initColorSync(pickerId, hexId, swatchId, stripeId) {
        const picker = document.getElementById(pickerId);
        const hex    = document.getElementById(hexId);
        const swatch = document.getElementById(swatchId);
        const stripe = stripeId ? document.getElementById(stripeId) : null;

        function apply(color) {
            picker.value = color;
            hex.value    = color;
            swatch.style.background = color;
            if (stripe) stripe.style.background = color;
        }

        picker.addEventListener('input', () => apply(picker.value));
        hex.addEventListener('change', () => {
            const v = hex.value.trim();
            if (/^#[0-9a-fA-F]{6}$/.test(v)) apply(v);
        });

        return apply;
    }

    const applyColor     = initColorSync('mb-color-picker',      'mb-color-hex',      'mb-color-swatch',      'mb-embed-stripe');
    const applyEditColor = initColorSync('mb-edit-color-picker',  'mb-edit-color-hex', 'mb-edit-color-swatch', 'mb-edit-embed-stripe');

    // ── Embed/plain toggle ────────────────────────────────────────────────
    function bindEmbedToggle(checkboxId, embedPanelId, plainPanelId) {
        const cb    = document.getElementById(checkboxId);
        const embed = document.getElementById(embedPanelId);
        const plain = document.getElementById(plainPanelId);
        cb.addEventListener('change', () => {
            embed.style.display = cb.checked ? '' : 'none';
            plain.style.display = cb.checked ? 'none' : '';
        });
    }

    bindEmbedToggle('mb-is-embed',      'mb-embed-panel',      'mb-plain-panel');
    bindEmbedToggle('mb-edit-is-embed', 'mb-edit-embed-panel', 'mb-edit-plain-panel');

    // ── Variable copy ─────────────────────────────────────────────────────
    window.mbCopyVar = function (el, v) {
        const orig = el.textContent;
        const done = () => {
            el.textContent = '✓ Kopiert';
            setTimeout(() => { el.textContent = orig; }, 1200);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(v).then(done).catch(() => mbCopyFallback(v, done));
        } else {
            mbCopyFallback(v, done);
        }
    };

    function mbCopyFallback(text, cb) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try { document.execCommand('copy'); } catch (_) {}
        document.body.removeChild(ta);
        if (cb) cb();
    }

    // ── Build payload from create form ─────────────────────────────────────
    function buildPayload(prefix = '') {
        const p = prefix;
        const isEmbed = document.getElementById(p + 'mb-is-embed' || 'mb-is-embed').checked;
        return {
            name:           document.getElementById(p ? 'mb-edit-name'  : 'mb-name').value.trim(),
            tag:            document.getElementById(p ? 'mb-edit-tag'   : 'mb-tag').value.trim(),
            is_embed:       isEmbed,
            plain_text:     document.getElementById(p ? 'mb-edit-plain' : 'mb-plain-text').value,
            embed_author:   document.getElementById(p ? 'mb-edit-author': 'mb-embed-author').value.trim(),
            embed_thumbnail:document.getElementById(p ? 'mb-edit-thumb' : 'mb-embed-thumb').value.trim(),
            embed_title:    document.getElementById(p ? 'mb-edit-title' : 'mb-embed-title').value.trim(),
            embed_body:     document.getElementById(p ? 'mb-edit-body'  : 'mb-embed-body').value,
            embed_image:    document.getElementById(p ? 'mb-edit-image' : 'mb-embed-image').value.trim(),
            embed_color:    document.getElementById(p ? 'mb-edit-color-hex': 'mb-color-hex').value.trim() || '#5865f2',
            embed_url:      document.getElementById(p ? 'mb-edit-url'   : 'mb-embed-url').value.trim(),
        };
    }

    // ── Save new ──────────────────────────────────────────────────────────
    window.mbSave = async function () {
        const btn = document.getElementById('mb-save-btn');
        btn.disabled = true;
        btn.textContent = 'Speichern…';
        try {
            const p = {
                action:          'save',
                name:            document.getElementById('mb-name').value.trim(),
                tag:             document.getElementById('mb-tag').value.trim(),
                is_embed:        document.getElementById('mb-is-embed').checked,
                plain_text:      document.getElementById('mb-plain-text').value,
                embed_author:    document.getElementById('mb-embed-author').value.trim(),
                embed_thumbnail: document.getElementById('mb-embed-thumb').value.trim(),
                embed_title:     document.getElementById('mb-embed-title').value.trim(),
                embed_body:      document.getElementById('mb-embed-body').value,
                embed_image:     document.getElementById('mb-embed-image').value.trim(),
                embed_color:     document.getElementById('mb-color-hex').value.trim() || '#5865f2',
                embed_url:       document.getElementById('mb-embed-url').value.trim(),
            };
            const res  = await fetch('', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(p) });
            const json = await res.json();
            if (!json.ok) { flash('❌ ' + (json.error || 'Fehler'), false); return; }

            flash('✅ Template gespeichert.', true);
            mbResetCreateForm();
            mbAddRow(json.template);
            tplMap[json.template.id] = json.template;
        } catch (e) {
            flash('❌ ' + e.message, false);
        } finally {
            btn.disabled = false;
            btn.textContent = 'Template speichern';
        }
    };

    function mbResetCreateForm() {
        ['mb-name','mb-tag','mb-embed-author','mb-embed-thumb','mb-embed-title','mb-embed-body','mb-embed-image','mb-embed-url','mb-plain-text']
            .forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
        applyColor('#5865f2');
        document.getElementById('mb-is-embed').checked = true;
        document.getElementById('mb-embed-panel').style.display = '';
        document.getElementById('mb-plain-panel').style.display = 'none';
    }

    // ── Update count badge ────────────────────────────────────────────────
    function mbUpdateCount() {
        const n = document.querySelectorAll('#mb-list .bh-list-card').length;
        const badge = document.getElementById('mb-count-badge');
        if (badge) badge.textContent = n;
    }

    // ── Build list row HTML ───────────────────────────────────────────────
    function mbAddRow(tpl) {
        const empty = document.getElementById('mb-empty');
        if (empty) empty.style.display = 'none';

        // Remove existing row if updating
        const existing = document.getElementById('mb-row-' + tpl.id);
        if (existing) existing.remove();

        const color   = tpl.embed_color || '#5865f2';
        const isEmbed = parseInt(tpl.is_embed);
        const tag     = tpl.tag ? `<span class="mb-tag-badge">${esc(tpl.tag)}</span>` : '';
        const stripe  = isEmbed ? `<div style="width:4px;height:36px;background:${esc(color)};border-radius:2px;flex-shrink:0"></div>` : '';

        const div = document.createElement('div');
        div.className = 'bh-list-card';
        div.id        = 'mb-row-' + tpl.id;
        div.innerHTML = `
            <div style="display:flex;align-items:center;gap:12px;flex:1;min-width:0">
                ${stripe}
                <div class="mb-list-info">
                    <div class="bh-list-name">${esc(tpl.name)}</div>
                    <div class="bh-list-meta">${isEmbed ? 'Embed' : 'Plain text'} ${tag}</div>
                </div>
            </div>
            <div class="mb-list-actions">
                <button class="mb-btn-edit" onclick="mbEdit(${tpl.id})">Bearbeiten</button>
                <button class="bh-btn bh-btn--danger bh-btn--sm"  onclick="mbDelete(${tpl.id})">Löschen</button>
            </div>`;

        document.getElementById('mb-list').prepend(div);
        mbUpdateCount();
    }

    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Delete ────────────────────────────────────────────────────────────
    window.mbDelete = async function (id) {
        if (!confirm('Template wirklich löschen?')) return;
        try {
            const res  = await fetch('', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete', id }) });
            const json = await res.json();
            if (!json.ok) { flash('❌ ' + (json.error || 'Fehler'), false); return; }
            document.getElementById('mb-row-' + id)?.remove();
            delete tplMap[id];
            mbUpdateCount();
            if (document.querySelectorAll('#mb-list .bh-list-card').length === 0) {
                document.getElementById('mb-empty').style.display = '';
            }
            flash('✅ Template gelöscht.', true);
        } catch (e) {
            flash('❌ ' + e.message, false);
        }
    };

    // ── Edit modal ────────────────────────────────────────────────────────
    window.mbEdit = function (id) {
        const tpl = tplMap[id];
        if (!tpl) return;
        editingId = id;

        document.getElementById('mb-edit-name').value  = tpl.name   || '';
        document.getElementById('mb-edit-tag').value   = tpl.tag    || '';
        document.getElementById('mb-edit-author').value = tpl.embed_author    || '';
        document.getElementById('mb-edit-thumb').value  = tpl.embed_thumbnail || '';
        document.getElementById('mb-edit-title').value  = tpl.embed_title     || '';
        document.getElementById('mb-edit-body').value   = tpl.embed_body      || '';
        document.getElementById('mb-edit-image').value  = tpl.embed_image     || '';
        document.getElementById('mb-edit-url').value    = tpl.embed_url       || '';
        document.getElementById('mb-edit-plain').value  = tpl.plain_text      || '';

        const isEmbed = parseInt(tpl.is_embed) === 1;
        document.getElementById('mb-edit-is-embed').checked = isEmbed;
        document.getElementById('mb-edit-embed-panel').style.display = isEmbed ? '' : 'none';
        document.getElementById('mb-edit-plain-panel').style.display = isEmbed ? 'none' : '';

        applyEditColor(tpl.embed_color || '#5865f2');

        document.getElementById('mb-modal-backdrop').style.display = 'flex';
    };

    window.mbCloseModal = function () {
        document.getElementById('mb-modal-backdrop').style.display = 'none';
        editingId = null;
    };

    window.mbEditSave = async function () {
        if (editingId === null) return;
        const btn = document.getElementById('mb-edit-save-btn');
        btn.disabled = true;
        btn.textContent = 'Speichern…';
        try {
            const p = {
                action:          'save',
                id:              editingId,
                name:            document.getElementById('mb-edit-name').value.trim(),
                tag:             document.getElementById('mb-edit-tag').value.trim(),
                is_embed:        document.getElementById('mb-edit-is-embed').checked,
                plain_text:      document.getElementById('mb-edit-plain').value,
                embed_author:    document.getElementById('mb-edit-author').value.trim(),
                embed_thumbnail: document.getElementById('mb-edit-thumb').value.trim(),
                embed_title:     document.getElementById('mb-edit-title').value.trim(),
                embed_body:      document.getElementById('mb-edit-body').value,
                embed_image:     document.getElementById('mb-edit-image').value.trim(),
                embed_color:     document.getElementById('mb-edit-color-hex').value.trim() || '#5865f2',
                embed_url:       document.getElementById('mb-edit-url').value.trim(),
            };
            const res  = await fetch('', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(p) });
            const json = await res.json();
            if (!json.ok) { flash('❌ ' + (json.error || 'Fehler'), false); return; }

            tplMap[json.template.id] = json.template;
            mbAddRow(json.template);
            mbCloseModal();
            flash('✅ Template aktualisiert.', true);
        } catch (e) {
            flash('❌ ' + e.message, false);
        } finally {
            btn.disabled = false;
            btn.textContent = 'Speichern';
        }
    };

    // Close modal on Escape
    document.addEventListener('keydown', e => { if (e.key === 'Escape') mbCloseModal(); });
})();
</script>
