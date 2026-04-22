<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/functions/custom_commands.php';

if (!isset($_SESSION) || !is_array($_SESSION)) {
    session_start();
}

$userId       = isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$currentBotId = isset($currentBotId) && is_int($currentBotId) ? $currentBotId : 0;

if (!function_exists('bh_cc_partial_redirect')) {
    function bh_cc_partial_redirect(string $url): void
    {
        header('Location: ' . $url, true, 302);
        exit;
    }
}

if (!function_exists('bh_cc_partial_url')) {
    function bh_cc_partial_url(int $botId): string
    {
        return '/dashboard?view=custom-commands&bot_id=' . $botId;
    }
}

if (!isset($_SESSION['bh_cc_csrf']) || !is_string($_SESSION['bh_cc_csrf']) || $_SESSION['bh_cc_csrf'] === '') {
    $_SESSION['bh_cc_csrf'] = bin2hex(random_bytes(32));
}

$flashError   = null;
$flashSuccess = null;

if (isset($_SESSION['bh_cc_flash_error']) && is_string($_SESSION['bh_cc_flash_error'])) {
    $flashError = $_SESSION['bh_cc_flash_error'];
    unset($_SESSION['bh_cc_flash_error']);
}

if (isset($_SESSION['bh_cc_flash_success']) && is_string($_SESSION['bh_cc_flash_success'])) {
    $flashSuccess = $_SESSION['bh_cc_flash_success'];
    unset($_SESSION['bh_cc_flash_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bh_cc_action'])) {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)$_SESSION['bh_cc_csrf'], $csrf)) {
        $_SESSION['bh_cc_flash_error'] = 'Ungültiges CSRF-Token.';
        bh_cc_partial_redirect(bh_cc_partial_url($currentBotId));
    }

    $action = (string)($_POST['bh_cc_action'] ?? '');

    if ($action === 'delete_command') {
        $commandId = isset($_POST['command_id']) && is_numeric($_POST['command_id']) ? (int)$_POST['command_id'] : 0;
        $result    = bh_cc_delete_custom_command($userId, $commandId);

        if (!($result['ok'] ?? false)) {
            $_SESSION['bh_cc_flash_error'] = (string)($result['error'] ?? 'Der Command konnte nicht gelöscht werden.');
        } else {
            $_SESSION['bh_cc_flash_success'] = 'Custom Command wurde gelöscht.';
        }

        bh_cc_partial_redirect(bh_cc_partial_url($currentBotId));
    }
}

$commands     = [];
$commandsError = null;

try {
    if ($userId > 0 && $currentBotId > 0) {
        $commands = bh_cc_list_custom_commands($userId, $currentBotId);
    }
} catch (Throwable $e) {
    $commandsError = $e->getMessage();
}

$commandsTableReady = false;
$builderTableReady  = false;

try {
    $commandsTableReady = bh_cc_commands_table_ready();
    $builderTableReady  = bh_cc_builder_table_ready();
} catch (Throwable $e) {
    $commandsError = $e->getMessage();
}

// Group commands
$grouped    = []; // ['Group A' => [...], '' => [...]]
$ungrouped  = [];
foreach ($commands as $cmd) {
    $g = trim((string)($cmd['group_name'] ?? ''));
    if ($g !== '') {
        $grouped[$g][] = $cmd;
    } else {
        $ungrouped[] = $cmd;
    }
}
ksort($grouped);
$csrfToken = h((string)$_SESSION['bh_cc_csrf']);
?>
<div class="grid grid-cols-12 gap-6">
    <div class="col-span-full">

        <!-- Header card -->
        <div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl mb-6">
            <div class="px-5 py-5 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <div class="text-xs uppercase font-semibold tracking-wide text-violet-500 mb-1">Custom Commands</div>
                    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Command Builder</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Erstelle und verwalte eigene Slash-Commands mit dem visuellen Builder.
                    </p>
                </div>
                <?php if ($currentBotId > 0): ?>
                    <div class="flex items-center gap-2">
                        <button type="button" id="cc-import-share-btn"
                                class="btn bg-sky-500 hover:bg-sky-600 text-white whitespace-nowrap"
                                data-bot-id="<?= $currentBotId ?>">
                            Import via Code
                        </button>
                        <a href="/dashboard/custom-commands/builder?bot_id=<?= $currentBotId ?>"
                           class="btn bg-violet-500 hover:bg-violet-600 text-white whitespace-nowrap">
                            + Neuer Command
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($flashSuccess !== null): ?>
            <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
                <?= h($flashSuccess) ?>
            </div>
        <?php endif; ?>
        <?php if ($flashError !== null): ?>
            <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                <?= h($flashError) ?>
            </div>
        <?php endif; ?>
        <?php if ($commandsError !== null): ?>
            <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                <?= h($commandsError) ?>
            </div>
        <?php endif; ?>
        <?php if (!$commandsTableReady || !$builderTableReady): ?>
            <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-4 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                <strong>DB-Hinweis:</strong> Fehlende Tabellen —
                <?= !$commandsTableReady ? 'bot_custom_commands' : '' ?>
                <?= (!$commandsTableReady && !$builderTableReady) ? ' und ' : '' ?>
                <?= !$builderTableReady ? 'bot_custom_command_builders' : '' ?>.
                Außerdem bitte ausführen: <code>ALTER TABLE bot_custom_commands ADD COLUMN group_name VARCHAR(80) NULL DEFAULT NULL AFTER is_enabled;</code>
            </div>
        <?php endif; ?>

        <?php if ($currentBotId <= 0): ?>
            <div class="rounded-xl border border-gray-200 dark:border-gray-700/60 px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800">
                Bitte zuerst einen Bot auswählen.
            </div>

        <?php elseif ($commands === []): ?>
            <div class="rounded-xl border border-gray-200 dark:border-gray-700/60 px-4 py-12 text-center bg-white dark:bg-gray-800">
                <div class="text-4xl mb-3">🤖</div>
                <div class="text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Noch keine Custom Commands</div>
                <div class="text-sm text-gray-400 dark:text-gray-500 mb-5">Klicke auf "+ Neuer Command" um loszulegen.</div>
                <a href="/dashboard/custom-commands/builder?bot_id=<?= $currentBotId ?>"
                   class="btn bg-violet-500 hover:bg-violet-600 text-white text-sm">
                    + Neuer Command
                </a>
            </div>

        <?php else: ?>

            <!-- Stats bar -->
            <div class="flex items-center gap-4 mb-4 text-sm text-gray-500 dark:text-gray-400">
                <span><strong class="text-gray-700 dark:text-gray-200"><?= count($commands) ?></strong> Commands</span>
                <span><strong class="text-gray-700 dark:text-gray-200"><?= count(array_filter($commands, fn($c) => (int)($c['is_enabled'] ?? 0) === 1)) ?></strong> Aktiv</span>
                <span><strong class="text-gray-700 dark:text-gray-200"><?= count($grouped) ?></strong> Gruppen</span>
            </div>

            <?php
            // Render a section (group or ungrouped)
            function renderCommandSection(array $cmds, string $sectionLabel, bool $isGroup, int $currentBotId, string $csrfToken): void
            {
                $sectionId = 'cc-section-' . md5($sectionLabel ?: '__none__');
            ?>
            <div class="cc-cmd-section mb-4 bg-white dark:bg-gray-800 rounded-xl shadow-xs border border-gray-200 dark:border-gray-700/60 overflow-hidden"
                 data-section="<?= h($sectionLabel) ?>">

                <!-- Section header -->
                <div class="cc-cmd-section-head flex items-center gap-3 px-5 py-3 border-b border-gray-100 dark:border-gray-700/60 cursor-pointer select-none"
                     onclick="this.closest('.cc-cmd-section').classList.toggle('is-collapsed')">
                    <svg class="cc-collapse-icon w-4 h-4 text-gray-400 transition-transform" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>

                    <?php if ($isGroup): ?>
                        <svg class="w-4 h-4 text-violet-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                        <span class="font-semibold text-sm text-gray-700 dark:text-gray-200"><?= h($sectionLabel) ?></span>
                    <?php else: ?>
                        <span class="font-medium text-sm text-gray-500 dark:text-gray-400 italic">Ohne Gruppe</span>
                    <?php endif; ?>

                    <span class="ml-auto text-xs text-gray-400"><?= count($cmds) ?> Commands</span>
                </div>

                <!-- Commands in this section -->
                <div class="cc-cmd-section-body divide-y divide-gray-100 dark:divide-gray-700/60">
                    <?php foreach ($cmds as $cmd):
                        $commandId   = (int)($cmd['id'] ?? 0);
                        $commandName = trim((string)($cmd['name'] ?? ''));
                        $slashName   = trim((string)($cmd['slash_name'] ?? ''));
                        $description = trim((string)($cmd['description'] ?? ''));
                        $isEnabled   = ((int)($cmd['is_enabled'] ?? 0) === 1);
                        $groupName   = trim((string)($cmd['group_name'] ?? ''));
                    ?>
                    <div class="cc-cmd-row flex items-center gap-4 px-5 py-4" data-command-id="<?= $commandId ?>">

                        <!-- Enable toggle -->
                        <label class="bh-toggle flex-shrink-0" title="<?= $isEnabled ? 'Deaktivieren' : 'Aktivieren' ?>">
                            <input type="checkbox"
                                   class="bh-toggle-input"
                                   <?= $isEnabled ? 'checked' : '' ?>
                                   data-command-id="<?= $commandId ?>">
                            <span class="bh-toggle-track">
                                <span class="bh-toggle-thumb"></span>
                            </span>
                        </label>

                        <!-- Icon -->
                        <div class="flex-shrink-0 w-9 h-9 rounded-lg <?= $isEnabled ? 'bg-violet-100 dark:bg-violet-500/20 text-violet-600 dark:text-violet-300' : 'bg-gray-100 dark:bg-gray-700/50 text-gray-400' ?> flex items-center justify-center font-bold text-sm">
                            /
                        </div>

                        <!-- Info -->
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-semibold text-sm text-gray-800 dark:text-gray-100 truncate">
                                    <?= h($commandName !== '' ? $commandName : 'Command #' . $commandId) ?>
                                </span>
                                <code class="text-xs text-gray-500 dark:text-gray-400 bg-gray-100 dark:bg-gray-700/60 rounded px-1.5 py-0.5">/<?= h($slashName) ?></code>
                                <span class="text-xs rounded-full px-2 py-0.5 font-medium <?= $isEnabled ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' : 'bg-gray-100 text-gray-500 dark:bg-gray-700/60 dark:text-gray-400' ?> cc-cmd-status-badge">
                                    <?= $isEnabled ? 'Aktiv' : 'Inaktiv' ?>
                                </span>
                            </div>
                            <?php if ($description !== ''): ?>
                                <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5 truncate"><?= h($description) ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Group inline editor -->
                        <div class="flex-shrink-0 hidden sm:flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                            <input type="text"
                                   class="cc-cmd-group-input text-xs border border-gray-200 dark:border-gray-600 rounded-lg px-2 py-1 bg-transparent text-gray-600 dark:text-gray-300 placeholder-gray-400 focus:outline-none focus:border-violet-400 w-28"
                                   placeholder="Gruppe…"
                                   maxlength="80"
                                   value="<?= h($groupName) ?>"
                                   data-command-id="<?= $commandId ?>"
                                   data-original="<?= h($groupName) ?>">
                        </div>

                        <!-- Actions -->
                        <div class="flex-shrink-0 flex items-center gap-2">
                            <a href="/dashboard/custom-commands/builder?command_id=<?= $commandId ?>&amp;bot_id=<?= $currentBotId ?>"
                               class="btn-sm bg-violet-500 hover:bg-violet-600 text-white text-xs">
                                Builder
                            </a>
                            <button type="button"
                                    class="btn-sm border border-sky-300 text-sky-600 hover:bg-sky-50 dark:border-sky-600 dark:text-sky-400 dark:hover:bg-sky-500/10 text-xs cc-cmd-share-btn"
                                    data-command-id="<?= $commandId ?>"
                                    data-command-name="<?= h($commandName) ?>">
                                Share
                            </button>
                            <button type="button"
                                    class="btn-sm border border-gray-200 hover:border-rose-300 hover:text-rose-600 dark:border-gray-700 dark:hover:border-rose-400 dark:hover:text-rose-300 text-xs cc-cmd-delete-btn"
                                    data-command-id="<?= $commandId ?>">
                                Löschen
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php } // end renderCommandSection ?>

            <?php foreach ($grouped as $groupName => $groupCmds): ?>
                <?php renderCommandSection($groupCmds, $groupName, true, $currentBotId, $csrfToken); ?>
            <?php endforeach; ?>

            <?php if ($ungrouped !== []): ?>
                <?php renderCommandSection($ungrouped, '', false, $currentBotId, $csrfToken); ?>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</div>


<script>
(function () {
    const CSRF = <?= json_encode((string)$_SESSION['bh_cc_csrf']) ?>;

    async function ccApi(action, payload) {
        const res = await fetch('/api/v1/custom_commands.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CC-CSRF': CSRF,
            },
            body: JSON.stringify({ action, ...payload }),
        });
        return res.json();
    }

    // ── Toggle enable/disable ─────────────────────────────────────────────────
    document.querySelectorAll('.bh-toggle-input').forEach((input) => {
        input.addEventListener('change', async function () {
            const commandId = parseInt(this.dataset.commandId, 10);
            const enabled   = this.checked;
            const row       = this.closest('.cc-cmd-row');

            // CSS handles toggle visuals via :checked — only update badge/icon
            applyEnabledState(row, enabled);

            try {
                const data = await ccApi('toggle_enabled', { command_id: commandId, enabled });
                if (!data.ok) {
                    this.checked = !enabled;
                    applyEnabledState(row, !enabled);
                    alert(data.error || 'Fehler beim Speichern.');
                }
            } catch {
                this.checked = !enabled;
                applyEnabledState(row, !enabled);
            }
        });
    });

    function applyEnabledState(row, enabled) {
        if (!row) return;

        const badge = row.querySelector('.cc-cmd-status-badge');
        if (badge) {
            badge.textContent = enabled ? 'Aktiv' : 'Inaktiv';
            badge.className = badge.className
                .replace(/\b(bg-emerald-100|text-emerald-700|dark:bg-emerald-500\/20|dark:text-emerald-300|bg-gray-100|text-gray-500|dark:bg-gray-700\/60|dark:text-gray-400)\b/g, '')
                .trim();
            badge.classList.add(...(enabled
                ? ['bg-emerald-100','text-emerald-700','dark:bg-emerald-500/20','dark:text-emerald-300']
                : ['bg-gray-100','text-gray-500','dark:bg-gray-700/60','dark:text-gray-400']
            ));
        }
    }

    // ── Delete command ────────────────────────────────────────────────────────
    document.querySelectorAll('.cc-cmd-delete-btn').forEach((btn) => {
        btn.addEventListener('click', async function () {
            if (!confirm('Diesen Command wirklich löschen?')) return;

            const commandId = parseInt(this.dataset.commandId, 10);
            const row = this.closest('.cc-cmd-row');

            btn.disabled = true;
            btn.textContent = '…';

            try {
                const data = await ccApi('delete_command', { command_id: commandId });
                if (data.ok) {
                    if (row) {
                        row.style.transition = 'opacity 0.2s';
                        row.style.opacity = '0';
                        setTimeout(() => row.remove(), 200);
                    }
                } else {
                    alert(data.error || 'Fehler beim Löschen.');
                    btn.disabled = false;
                    btn.textContent = 'Löschen';
                }
            } catch {
                alert('Netzwerkfehler beim Löschen.');
                btn.disabled = false;
                btn.textContent = 'Löschen';
            }
        });
    });

    // ── Share Code ────────────────────────────────────────────────────────────
    async function ccShareApi(action, payload) {
        const res = await fetch('/api/v1/cc_share.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CC-CSRF': CSRF },
            body: JSON.stringify({ action, ...payload }),
        });
        return res.json();
    }

    function showShareModal(commandId, commandName) {
        let modal = document.getElementById('cc-share-modal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'cc-share-modal';
            modal.className = 'cc-modal-overlay';
            modal.innerHTML =
                '<div class="cc-modal">' +
                    '<div class="cc-modal-header"><span id="cc-share-modal-title">Share Command</span>' +
                        '<button type="button" class="cc-modal-close" onclick="document.getElementById(\'cc-share-modal\').classList.remove(\'is-open\')">✕</button>' +
                    '</div>' +
                    '<div class="cc-modal-body">' +
                        '<div id="cc-share-result" class="mb-4"></div>' +
                        '<button type="button" id="cc-share-generate-btn" class="btn bg-violet-500 hover:bg-violet-600 text-white w-full">Share Code generieren</button>' +
                    '</div>' +
                '</div>';
            document.body.appendChild(modal);
        }

        document.getElementById('cc-share-modal-title').textContent = 'Share: ' + commandName;
        document.getElementById('cc-share-result').innerHTML = '';
        const generateBtn = document.getElementById('cc-share-generate-btn');
        generateBtn.disabled = false;
        generateBtn.textContent = 'Share Code generieren';

        generateBtn.onclick = async function () {
            generateBtn.disabled = true;
            generateBtn.textContent = 'Generiere…';
            try {
                const data = await ccShareApi('generate_share_code', { command_id: commandId });
                if (data.ok) {
                    document.getElementById('cc-share-result').innerHTML =
                        '<div class="mb-2 text-sm text-gray-500 dark:text-gray-400">Share Code (gültig 30 Tage):</div>' +
                        '<div class="flex items-center gap-2">' +
                            '<code class="flex-1 text-center text-lg font-mono bg-gray-100 dark:bg-gray-700/60 text-violet-600 dark:text-violet-300 rounded-lg px-4 py-3 select-all" id="cc-share-code-text">' + escHtml(data.code) + '</code>' +
                            '<button type="button" class="btn bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 text-xs" onclick="navigator.clipboard.writeText(document.getElementById(\'cc-share-code-text\').textContent).then(()=>{this.textContent=\'✓ Kopiert\'})">Kopieren</button>' +
                        '</div>';
                    generateBtn.style.display = 'none';
                } else {
                    document.getElementById('cc-share-result').innerHTML = '<div class="text-sm text-rose-600">' + escHtml(data.error || 'Fehler') + '</div>';
                    generateBtn.disabled = false;
                    generateBtn.textContent = 'Share Code generieren';
                }
            } catch {
                document.getElementById('cc-share-result').innerHTML = '<div class="text-sm text-rose-600">Netzwerkfehler.</div>';
                generateBtn.disabled = false;
                generateBtn.textContent = 'Share Code generieren';
            }
        };

        modal.classList.add('is-open');
        modal.onclick = (e) => { if (e.target === modal) modal.classList.remove('is-open'); };
    }

    document.querySelectorAll('.cc-cmd-share-btn').forEach((btn) => {
        btn.addEventListener('click', function () {
            const commandId   = parseInt(this.dataset.commandId, 10);
            const commandName = this.dataset.commandName || 'Command';
            showShareModal(commandId, commandName);
        });
    });

    // ── Import via Share Code ─────────────────────────────────────────────────
    const importBtn = document.getElementById('cc-import-share-btn');
    if (importBtn) {
        importBtn.addEventListener('click', function () {
            const botId = parseInt(this.dataset.botId, 10);
            showImportModal(botId);
        });
    }

    function showImportModal(botId) {
        let modal = document.getElementById('cc-import-modal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'cc-import-modal';
            modal.className = 'cc-modal-overlay';
            modal.innerHTML =
                '<div class="cc-modal">' +
                    '<div class="cc-modal-header"><span>Command Importieren</span>' +
                        '<button type="button" class="cc-modal-close" onclick="document.getElementById(\'cc-import-modal\').classList.remove(\'is-open\')">✕</button>' +
                    '</div>' +
                    '<div class="cc-modal-body">' +
                        '<label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Share Code eingeben</label>' +
                        '<input type="text" id="cc-import-code-input" placeholder="xxxx-xxxxxx-xxxxxx-xxxx"' +
                            ' class="w-full bg-white dark:bg-gray-900 border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 text-sm font-mono text-gray-900 dark:text-gray-100 mb-3" maxlength="25">' +
                        '<div id="cc-import-preview" class="mb-3"></div>' +
                        '<div class="flex gap-2">' +
                            '<button type="button" id="cc-import-preview-btn" class="btn bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 flex-1 text-sm">Vorschau</button>' +
                            '<button type="button" id="cc-import-confirm-btn" class="btn bg-violet-500 hover:bg-violet-600 text-white flex-1 text-sm" disabled>Importieren</button>' +
                        '</div>' +
                    '</div>' +
                '</div>';
            document.body.appendChild(modal);

            const previewBtn  = document.getElementById('cc-import-preview-btn');
            const confirmBtn  = document.getElementById('cc-import-confirm-btn');
            const codeInput   = document.getElementById('cc-import-code-input');
            const previewDiv  = document.getElementById('cc-import-preview');

            previewBtn.onclick = async function () {
                const code = codeInput.value.trim().toLowerCase();
                if (!code) return;
                previewBtn.disabled = true;
                previewDiv.innerHTML = '<span class="text-sm text-gray-500">Lade…</span>';
                confirmBtn.disabled = true;
                try {
                    const data = await ccShareApi('preview_share_code', { code });
                    if (data.ok) {
                        previewDiv.innerHTML =
                            '<div class="rounded-lg border border-gray-200 dark:border-gray-700/60 bg-gray-50 dark:bg-gray-900/30 p-3 text-sm">' +
                                '<div class="font-semibold text-gray-800 dark:text-gray-100">' + escHtml(data.name) + '</div>' +
                                '<div class="text-gray-500 dark:text-gray-400 mt-0.5">' + escHtml(data.description || '—') + '</div>' +
                                '<div class="text-xs text-gray-400 mt-1">Erstellt: ' + escHtml(data.created_at) + ' · Läuft ab: ' + escHtml(data.expires_at || 'nie') + '</div>' +
                            '</div>';
                        confirmBtn.disabled = false;
                    } else {
                        previewDiv.innerHTML = '<div class="text-sm text-rose-600">' + escHtml(data.error || 'Code nicht gefunden.') + '</div>';
                    }
                } catch {
                    previewDiv.innerHTML = '<div class="text-sm text-rose-600">Netzwerkfehler.</div>';
                } finally {
                    previewBtn.disabled = false;
                }
            };

            confirmBtn.onclick = async function () {
                const code = codeInput.value.trim().toLowerCase();
                if (!code) return;
                confirmBtn.disabled = true;
                confirmBtn.textContent = 'Importiere…';
                try {
                    const data = await ccShareApi('import_share_code', { code, bot_id: botId });
                    if (data.ok) {
                        previewDiv.innerHTML = '<div class="text-sm text-emerald-600">✓ Importiert als /' + escHtml(data.slug) + '. Seite wird neu geladen…</div>';
                        setTimeout(() => window.location.reload(), 1200);
                    } else {
                        previewDiv.innerHTML = '<div class="text-sm text-rose-600">' + escHtml(data.error || 'Import fehlgeschlagen.') + '</div>';
                        confirmBtn.disabled = false;
                        confirmBtn.textContent = 'Importieren';
                    }
                } catch {
                    previewDiv.innerHTML = '<div class="text-sm text-rose-600">Netzwerkfehler.</div>';
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = 'Importieren';
                }
            };
        }

        document.getElementById('cc-import-code-input').value = '';
        document.getElementById('cc-import-preview').innerHTML = '';
        document.getElementById('cc-import-confirm-btn').disabled = true;
        modal.classList.add('is-open');
        modal.onclick = (e) => { if (e.target === modal) modal.classList.remove('is-open'); };
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Group inline editor ───────────────────────────────────────────────────
    document.querySelectorAll('.cc-cmd-group-input').forEach((input) => {
        async function saveGroup() {
            const commandId = parseInt(input.dataset.commandId, 10);
            const newGroup  = input.value.trim();
            if (newGroup === input.dataset.original) return;

            input.disabled = true;
            try {
                const data = await ccApi('set_group', { command_id: commandId, group_name: newGroup });
                if (data.ok) {
                    input.dataset.original = newGroup;
                    // Reload page so grouping reflects the change
                    window.location.reload();
                } else {
                    alert(data.error || 'Fehler beim Speichern der Gruppe.');
                    input.value = input.dataset.original;
                }
            } catch {
                input.value = input.dataset.original;
            } finally {
                input.disabled = false;
            }
        }

        input.addEventListener('blur', saveGroup);
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
            if (e.key === 'Escape') { input.value = input.dataset.original; input.blur(); }
        });
    });
}());
</script>
