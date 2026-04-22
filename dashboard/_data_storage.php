<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/functions/db_functions/data_storage.php';

$pdo   = bh_get_pdo();
$botId = isset($currentBotId) && $currentBotId > 0 ? $currentBotId : (int)($_GET['bot_id'] ?? 0);

if ($botId <= 0) { ?>
<div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl p-5">
    <div class="text-sm text-rose-600 dark:text-rose-400">Bot nicht gefunden.</div>
</div>
<?php return; }

try { bhds_ensure_tables($pdo); } catch (Throwable) {}

// ── Handle AJAX ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    $raw    = (string)file_get_contents('php://input');
    $data   = json_decode($raw, true);
    $action = (string)($data['action'] ?? '');
    header('Content-Type: application/json; charset=utf-8');

    $validTypes  = ['text', 'number', 'user', 'channel', 'collection', 'object'];
    $validScopes = ['global', 'server'];

    if ($action === 'add') {
        $name         = mb_substr(trim((string)($data['name']          ?? '')), 0, 100);
        $reference    = mb_substr(trim((string)($data['reference']     ?? '')), 0, 100);
        $varType      = in_array((string)($data['var_type'] ?? ''), $validTypes, true)  ? (string)$data['var_type']  : 'text';
        $defaultValue = mb_substr(trim((string)($data['default_value'] ?? '')), 0, 1000);
        $scope        = in_array((string)($data['scope']    ?? ''), $validScopes, true) ? (string)$data['scope']     : 'server';

        if ($name === '' || $reference === '') {
            echo json_encode(['ok' => false, 'error' => 'Name und Reference sind Pflichtfelder.']);
            exit;
        }
        if (!preg_match('/^[a-z0-9_]+$/', $reference)) {
            echo json_encode(['ok' => false, 'error' => 'Reference darf nur Kleinbuchstaben, Zahlen und _ enthalten.']);
            exit;
        }
        try {
            if (bhds_reference_exists($pdo, $botId, $reference)) {
                echo json_encode(['ok' => false, 'error' => 'Eine Variable mit dieser Reference existiert bereits.']);
                exit;
            }
            $id = bhds_add($pdo, $botId, $name, $reference, $varType, $defaultValue, $scope);
            echo json_encode(['ok' => true, 'id' => $id]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'update') {
        $id           = (int)($data['id'] ?? 0);
        $name         = mb_substr(trim((string)($data['name']          ?? '')), 0, 100);
        $varType      = in_array((string)($data['var_type'] ?? ''), $validTypes, true)  ? (string)$data['var_type']  : 'text';
        $defaultValue = mb_substr(trim((string)($data['default_value'] ?? '')), 0, 1000);
        $scope        = in_array((string)($data['scope']    ?? ''), $validScopes, true) ? (string)$data['scope']     : 'server';

        if ($id <= 0 || $name === '') {
            echo json_encode(['ok' => false, 'error' => 'Ungültige Daten.']);
            exit;
        }
        try {
            bhds_update($pdo, $id, $botId, $name, $varType, $defaultValue, $scope);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Ungültige ID.']);
            exit;
        }
        try {
            bhds_delete($pdo, $id, $botId);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unbekannte Aktion.']);
    exit;
}

// ── Load variables ─────────────────────────────────────────────────────────
$variables = bhds_list($pdo, $botId);

$VAR_TYPE_LABELS = [
    'text'       => 'Text',
    'number'     => 'Number',
    'user'       => 'User',
    'channel'    => 'Channel',
    'collection' => 'Collection',
    'object'     => 'Object',
];
$SCOPE_LABELS = [
    'server' => 'Server Specific',
    'global' => 'Global',
];
?>

<div class="space-y-6" id="ds-root" data-bot-id="<?= $botId ?>">

    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <p class="text-xs uppercase tracking-widest text-gray-400 dark:text-gray-500 mb-1">DATA STORAGE</p>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Custom Variables</h1>
        </div>
    </div>

    <!-- Error/success banner -->
    <div id="ds-banner" class="hidden rounded-lg px-4 py-3 text-sm font-medium"></div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

        <!-- ── Left: Create form ── -->
        <div class="xl:col-span-1">
            <div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700/60">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100" id="ds-form-title">New Variable</h2>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Create a new Custom Variable. These can be used in your custom commands and events to store data.</p>
                </div>

                <div class="p-5 space-y-5">

                    <!-- Name -->
                    <div>
                        <label for="ds-input-name" class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Name</label>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mb-2">A descriptive name for this variable.</p>
                        <input type="text" id="ds-input-name" maxlength="100" placeholder="My Variable" class="bh-input">
                    </div>

                    <!-- Reference -->
                    <div>
                        <label for="ds-input-reference" class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Reference</label>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mb-2">The variable tag used to reference this variable in your bot. This is generated from the name. You can use this variable in custom commands.</p>
                        <input type="text" id="ds-input-reference" maxlength="100" readonly placeholder="{bhvar_}" class="bh-input">
                    </div>

                    <hr class="border-gray-100 dark:border-gray-700/60">

                    <!-- Variable Type -->
                    <div>
                        <label for="ds-input-type" class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Variable Type</label>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mb-2">The type of data to store in this variable. If a User variable is selected, variable data will be assigned to each user. If a Channel variable is selected, variable data will be assigned to each channel.</p>
                        <select id="ds-input-type" class="bh-input">
                            <?php foreach ($VAR_TYPE_LABELS as $val => $lbl): ?>
                                <option value="<?= $val ?>"><?= htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <hr class="border-gray-100 dark:border-gray-700/60">

                    <!-- Default Value -->
                    <div>
                        <label for="ds-input-default" class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Default Value</label>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mb-2">The starting value of this variable before being set.</p>
                        <input type="text" id="ds-input-default" maxlength="1000" placeholder="" class="bh-input">
                    </div>

                    <hr class="border-gray-100 dark:border-gray-700/60">

                    <!-- Scope -->
                    <div>
                        <label for="ds-input-scope" class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Scope</label>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mb-2">Whether this variable should have different values based on <span style="color:#a78bfa">the server it is used in.</span></p>
                        <select id="ds-input-scope" class="bh-input">
                            <?php foreach ($SCOPE_LABELS as $val => $lbl): ?>
                                <option value="<?= $val ?>"><?= htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <hr class="border-gray-100 dark:border-gray-700/60">

                    <!-- Actions -->
                    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:4px">
                        <button type="button" id="ds-btn-cancel" class="ds-btn-cancel" style="display:none">
                            Cancel
                        </button>
                        <button type="button" id="ds-btn-submit" class="ds-btn-submit">
                            Add Custom Variable
                        </button>
                    </div>

                </div>
            </div>
        </div>

        <!-- ── Right: Variables list ── -->
        <div class="xl:col-span-2">
            <div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700/60 flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Variables</h2>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">All custom variables for this bot.</p>
                    </div>
                    <span id="ds-var-count" class="text-xs bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 px-2 py-0.5 rounded-full font-medium">
                        <?= count($variables) ?>
                    </span>
                </div>

                <div id="ds-list">
                    <?php if (count($variables) === 0): ?>
                    <div id="ds-empty" class="px-5 py-12 text-center">
                        <div class="text-3xl mb-3">📦</div>
                        <div class="text-sm font-semibold text-gray-500 dark:text-gray-400 mb-1">No variables yet</div>
                        <div class="text-xs text-gray-400 dark:text-gray-500">Create your first custom variable using the form on the left.</div>
                    </div>
                    <?php else: ?>
                    <?php foreach ($variables as $var): ?>
                    <div class="ds-var-row border-b border-gray-100 dark:border-gray-700/60 last:border-b-0 px-5 py-4 flex items-center gap-4 hover:bg-gray-50 dark:hover:bg-gray-700/20 transition"
                         data-id="<?= (int)$var['id'] ?>">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm font-semibold text-gray-800 dark:text-gray-100 ds-row-name">
                                    <?= htmlspecialchars($var['name'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <span class="text-xs font-mono text-violet-500 dark:text-violet-400 bg-violet-50 dark:bg-violet-500/10 px-2 py-0.5 rounded ds-row-ref">
                                    {bhvar_<?= htmlspecialchars($var['reference'], ENT_QUOTES, 'UTF-8') ?>}
                                </span>
                            </div>
                            <div class="flex items-center gap-3 mt-1 flex-wrap">
                                <span class="text-xs text-gray-400 dark:text-gray-500 ds-row-type">Type: <strong class="text-gray-600 dark:text-gray-300"><?= htmlspecialchars($VAR_TYPE_LABELS[$var['var_type']] ?? $var['var_type'], ENT_QUOTES, 'UTF-8') ?></strong></span>
                                <span class="text-xs text-gray-400 dark:text-gray-500">Scope: <strong class="text-gray-600 dark:text-gray-300 ds-row-scope"><?= htmlspecialchars($SCOPE_LABELS[$var['scope']] ?? $var['scope'], ENT_QUOTES, 'UTF-8') ?></strong></span>
                                <?php if ($var['default_value'] !== null && $var['default_value'] !== ''): ?>
                                <span class="text-xs text-gray-400 dark:text-gray-500">Default: <strong class="text-gray-600 dark:text-gray-300 ds-row-default"><?= htmlspecialchars(mb_substr($var['default_value'], 0, 40), ENT_QUOTES, 'UTF-8') ?></strong></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="flex gap-2 shrink-0">
                            <button type="button" class="bh-btn bh-btn--primary bh-btn--sm p-1.5 rounded-lg text-gray-400 hover:text-violet-500 hover:bg-violet-50 dark:hover:bg-violet-500/10 transition" title="Bearbeiten"
                                data-id="<?= (int)$var['id'] ?>"
                                data-name="<?= htmlspecialchars($var['name'], ENT_QUOTES, 'UTF-8') ?>"
                                data-reference="<?= htmlspecialchars($var['reference'], ENT_QUOTES, 'UTF-8') ?>"
                                data-type="<?= htmlspecialchars($var['var_type'], ENT_QUOTES, 'UTF-8') ?>"
                                data-default="<?= htmlspecialchars($var['default_value'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                data-scope="<?= htmlspecialchars($var['scope'], ENT_QUOTES, 'UTF-8') ?>">
                                <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor"><path d="M11.013 1.427a1.75 1.75 0 0 1 2.474 0l1.086 1.086a1.75 1.75 0 0 1 0 2.474l-8.61 8.61c-.21.21-.47.364-.756.445l-3.251.93a.75.75 0 0 1-.927-.928l.929-3.25c.081-.286.235-.547.445-.758l8.61-8.61Zm1.414 1.06a.25.25 0 0 0-.354 0L10.811 3.75l1.439 1.44 1.263-1.263a.25.25 0 0 0 0-.354l-1.086-1.086ZM11.189 6.25 9.75 4.81 3.34 11.22a.25.25 0 0 0-.064.108l-.558 1.953 1.953-.558a.249.249 0 0 0 .108-.064L11.19 6.25Z"/></svg>
                            </button>
                            <button type="button" class="bh-btn bh-btn--danger bh-btn--sm p-1.5 rounded-lg text-gray-400 hover:text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-500/10 transition" title="Löschen"
                                data-id="<?= (int)$var['id'] ?>">
                                <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor"><path d="M11 1.75V3h2.25a.75.75 0 0 1 0 1.5H2.75a.75.75 0 0 1 0-1.5H5V1.75C5 .784 5.784 0 6.75 0h2.5C10.216 0 11 .784 11 1.75ZM4.496 6.675l.66 6.6a.25.25 0 0 0 .249.225h5.19a.25.25 0 0 0 .249-.225l.66-6.6a.75.75 0 0 1 1.492.149l-.66 6.6A1.748 1.748 0 0 1 10.595 15h-5.19a1.75 1.75 0 0 1-1.741-1.575l-.66-6.6a.75.75 0 1 1 1.492-.15ZM6.5 1.75V3h3V1.75a.25.25 0 0 0-.25-.25h-2.5a.25.25 0 0 0-.25.25Z"/></svg>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
(function () {
    'use strict';

    var BOT_ID  = <?= $botId ?>;
    var apiUrl  = window.location.href;

    var TYPE_LABELS  = <?= json_encode($VAR_TYPE_LABELS, JSON_UNESCAPED_UNICODE) ?>;
    var SCOPE_LABELS = <?= json_encode($SCOPE_LABELS, JSON_UNESCAPED_UNICODE) ?>;

    var inputName    = document.getElementById('ds-input-name');
    var inputRef     = document.getElementById('ds-input-reference');
    var inputType    = document.getElementById('ds-input-type');
    var inputDefault = document.getElementById('ds-input-default');
    var inputScope   = document.getElementById('ds-input-scope');
    var btnSubmit    = document.getElementById('ds-btn-submit');
    var btnCancel    = document.getElementById('ds-btn-cancel');
    var formTitle    = document.getElementById('ds-form-title');
    var banner       = document.getElementById('ds-banner');
    var list         = document.getElementById('ds-list');
    var varCount     = document.getElementById('ds-var-count');

    var editId = null; // null = create mode, number = edit mode

    // ── Auto-generate reference from name ────────────────────────────────
    inputName.addEventListener('input', function () {
        if (editId !== null) return; // don't auto-overwrite in edit mode
        var slug = inputName.value
            .toLowerCase()
            .replace(/\s+/g, '_')
            .replace(/[^a-z0-9_]/g, '')
            .substring(0, 80);
        inputRef.value = '{bhvar_' + slug + '}';
    });

    // ── Banner helper ─────────────────────────────────────────────────────
    function showBanner(msg, isError) {
        banner.textContent = msg;
        banner.className = 'rounded-lg px-4 py-3 text-sm font-medium '
            + (isError
                ? 'bg-rose-50 dark:bg-rose-500/10 text-rose-600 dark:text-rose-400'
                : 'bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400');
        banner.classList.remove('hidden');
        clearTimeout(banner._t);
        banner._t = setTimeout(function () { banner.classList.add('hidden'); }, 4000);
    }

    // ── Reset form ────────────────────────────────────────────────────────
    function resetForm() {
        editId = null;
        inputName.value    = '';
        inputRef.value     = '';
        inputType.value    = 'text';
        inputDefault.value = '';
        inputScope.value   = 'server';
        formTitle.textContent = 'New Variable';
        btnSubmit.textContent = 'Add Custom Variable';
        btnCancel.style.display = 'none';
    }

    btnCancel.addEventListener('click', resetForm);

    // ── Submit ────────────────────────────────────────────────────────────
    btnSubmit.addEventListener('click', function () {
        var name     = inputName.value.trim();
        var refRaw   = inputRef.value.trim();
        // Strip wrapper {bhvar_...} → just the slug
        var refMatch = refRaw.match(/^\{bhvar_([a-z0-9_]+)\}$/);
        var reference = refMatch ? refMatch[1] : refRaw.toLowerCase().replace(/[^a-z0-9_]/g, '');
        var varType  = inputType.value;
        var defVal   = inputDefault.value.trim();
        var scope    = inputScope.value;

        if (!name) {
            showBanner('Name ist ein Pflichtfeld.', true);
            return;
        }
        if (!reference) {
            showBanner('Reference ist ein Pflichtfeld.', true);
            return;
        }

        var payload = editId !== null
            ? { action: 'update', id: editId, name: name, var_type: varType, default_value: defVal, scope: scope }
            : { action: 'add', name: name, reference: reference, var_type: varType, default_value: defVal, scope: scope };

        btnSubmit.disabled = true;
        fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.ok) {
                showBanner(d.error || 'Fehler aufgetreten.', true);
                return;
            }
            if (editId !== null) {
                // Update row in-place
                var row = list.querySelector('.ds-var-row[data-id="' + editId + '"]');
                if (row) {
                    row.querySelector('.ds-row-name').textContent = name;
                    row.querySelector('.ds-row-type').innerHTML = 'Type: <strong class="text-gray-600 dark:text-gray-300">' + escHtml(TYPE_LABELS[varType] || varType) + '</strong>';
                    row.querySelector('.ds-row-scope').textContent = SCOPE_LABELS[scope] || scope;
                    var defEl = row.querySelector('.ds-row-default');
                    if (defEl) defEl.textContent = defVal.substring(0, 40);

                    // Update data attrs for next edit
                    var editBtn = row.querySelector('.bh-btn bh-btn--primary bh-btn--sm');
                    editBtn.dataset.name    = name;
                    editBtn.dataset.type    = varType;
                    editBtn.dataset.default = defVal;
                    editBtn.dataset.scope   = scope;
                }
                showBanner('Variable aktualisiert.', false);
            } else {
                // Append new row
                var newId = d.id;
                var empty = document.getElementById('ds-empty');
                if (empty) empty.remove();

                var row = document.createElement('div');
                row.className = 'ds-var-row border-b border-gray-100 dark:border-gray-700/60 last:border-b-0 px-5 py-4 flex items-center gap-4 hover:bg-gray-50 dark:hover:bg-gray-700/20 transition';
                row.dataset.id = newId;
                row.innerHTML = buildRowHtml(newId, name, reference, varType, defVal, scope);
                list.appendChild(row);
                attachRowEvents(row);

                var cnt = list.querySelectorAll('.ds-var-row').length;
                varCount.textContent = cnt;
                showBanner('Variable erstellt.', false);
            }
            resetForm();
        })
        .catch(function () {
            showBanner('Netzwerkfehler.', true);
        })
        .finally(function () {
            btnSubmit.disabled = false;
        });
    });

    // ── Row HTML builder ──────────────────────────────────────────────────
    function buildRowHtml(id, name, reference, varType, defVal, scope) {
        var defPart = defVal
            ? '<span class="text-xs text-gray-400 dark:text-gray-500">Default: <strong class="text-gray-600 dark:text-gray-300 ds-row-default">' + escHtml(defVal.substring(0, 40)) + '</strong></span>'
            : '';
        return ''
            + '<div class="flex-1 min-w-0">'
            +   '<div class="flex items-center gap-2 flex-wrap">'
            +     '<span class="text-sm font-semibold text-gray-800 dark:text-gray-100 ds-row-name">' + escHtml(name) + '</span>'
            +     '<span class="text-xs font-mono text-violet-500 dark:text-violet-400 bg-violet-50 dark:bg-violet-500/10 px-2 py-0.5 rounded ds-row-ref">{bhvar_' + escHtml(reference) + '}</span>'
            +   '</div>'
            +   '<div class="flex items-center gap-3 mt-1 flex-wrap">'
            +     '<span class="text-xs text-gray-400 dark:text-gray-500 ds-row-type">Type: <strong class="text-gray-600 dark:text-gray-300">' + escHtml(TYPE_LABELS[varType] || varType) + '</strong></span>'
            +     '<span class="text-xs text-gray-400 dark:text-gray-500">Scope: <strong class="text-gray-600 dark:text-gray-300 ds-row-scope">' + escHtml(SCOPE_LABELS[scope] || scope) + '</strong></span>'
            +     defPart
            +   '</div>'
            + '</div>'
            + '<div class="flex gap-2 shrink-0">'
            +   '<button type="button" class="bh-btn bh-btn--primary bh-btn--sm p-1.5 rounded-lg text-gray-400 hover:text-violet-500 hover:bg-violet-50 dark:hover:bg-violet-500/10 transition" title="Bearbeiten"'
            +     ' data-id="' + id + '" data-name="' + escAttr(name) + '" data-reference="' + escAttr(reference) + '"'
            +     ' data-type="' + escAttr(varType) + '" data-default="' + escAttr(defVal) + '" data-scope="' + escAttr(scope) + '">'
            +     '<svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor"><path d="M11.013 1.427a1.75 1.75 0 0 1 2.474 0l1.086 1.086a1.75 1.75 0 0 1 0 2.474l-8.61 8.61c-.21.21-.47.364-.756.445l-3.251.93a.75.75 0 0 1-.927-.928l.929-3.25c.081-.286.235-.547.445-.758l8.61-8.61Zm1.414 1.06a.25.25 0 0 0-.354 0L10.811 3.75l1.439 1.44 1.263-1.263a.25.25 0 0 0 0-.354l-1.086-1.086ZM11.189 6.25 9.75 4.81 3.34 11.22a.25.25 0 0 0-.064.108l-.558 1.953 1.953-.558a.249.249 0 0 0 .108-.064L11.19 6.25Z"/></svg>'
            +   '</button>'
            +   '<button type="button" class="bh-btn bh-btn--danger bh-btn--sm p-1.5 rounded-lg text-gray-400 hover:text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-500/10 transition" title="Löschen" data-id="' + id + '">'
            +     '<svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor"><path d="M11 1.75V3h2.25a.75.75 0 0 1 0 1.5H2.75a.75.75 0 0 1 0-1.5H5V1.75C5 .784 5.784 0 6.75 0h2.5C10.216 0 11 .784 11 1.75ZM4.496 6.675l.66 6.6a.25.25 0 0 0 .249.225h5.19a.25.25 0 0 0 .249-.225l.66-6.6a.75.75 0 0 1 1.492.149l-.66 6.6A1.748 1.748 0 0 1 10.595 15h-5.19a1.75 1.75 0 0 1-1.741-1.575l-.66-6.6a.75.75 0 1 1 1.492-.15ZM6.5 1.75V3h3V1.75a.25.25 0 0 0-.25-.25h-2.5a.25.25 0 0 0-.25.25Z"/></svg>'
            +   '</button>'
            + '</div>';
    }

    // ── Edit ──────────────────────────────────────────────────────────────
    function handleEdit(btn) {
        editId = parseInt(btn.dataset.id, 10);
        inputName.value    = btn.dataset.name    || '';
        inputRef.value     = '{bhvar_' + (btn.dataset.reference || '') + '}';
        inputType.value    = btn.dataset.type    || 'text';
        inputDefault.value = btn.dataset.default || '';
        inputScope.value   = btn.dataset.scope   || 'server';
        formTitle.textContent = 'Edit Variable';
        btnSubmit.textContent = 'Save Changes';
        btnCancel.style.display = '';
        document.getElementById('ds-root').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // ── Delete ────────────────────────────────────────────────────────────
    function handleDelete(btn) {
        var id = parseInt(btn.dataset.id, 10);
        if (!confirm('Diese Variable wirklich löschen?')) return;
        fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', id: id }),
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.ok) { showBanner(d.error || 'Fehler aufgetreten.', true); return; }
            var row = list.querySelector('.ds-var-row[data-id="' + id + '"]');
            if (row) row.remove();
            if (list.querySelectorAll('.ds-var-row').length === 0) {
                list.innerHTML = '<div id="ds-empty" class="px-5 py-12 text-center">'
                    + '<div class="text-3xl mb-3">📦</div>'
                    + '<div class="text-sm font-semibold text-gray-500 dark:text-gray-400 mb-1">No variables yet</div>'
                    + '<div class="text-xs text-gray-400 dark:text-gray-500">Create your first custom variable using the form on the left.</div>'
                    + '</div>';
            }
            varCount.textContent = list.querySelectorAll('.ds-var-row').length;
            if (editId === id) resetForm();
            showBanner('Variable gelöscht.', false);
        })
        .catch(function () { showBanner('Netzwerkfehler.', true); });
    }

    // ── Attach events to existing rows ────────────────────────────────────
    function attachRowEvents(row) {
        var editBtn   = row.querySelector('.bh-btn bh-btn--primary bh-btn--sm');
        var deleteBtn = row.querySelector('.bh-btn bh-btn--danger bh-btn--sm');
        if (editBtn)   editBtn.addEventListener('click',   function () { handleEdit(editBtn); });
        if (deleteBtn) deleteBtn.addEventListener('click', function () { handleDelete(deleteBtn); });
    }

    document.querySelectorAll('.ds-var-row').forEach(attachRowEvents);

    // ── Escape helpers ────────────────────────────────────────────────────
    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function escAttr(s) {
        return String(s).replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }

}());
</script>
