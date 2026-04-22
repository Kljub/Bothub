<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/functions/timed_events.php';

if (!isset($_SESSION) || !is_array($_SESSION)) {
    session_start();
}

$userId       = isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$currentBotId = isset($currentBotId) && is_int($currentBotId) ? $currentBotId : 0;

if (!isset($_SESSION['bh_te_csrf']) || $_SESSION['bh_te_csrf'] === '') {
    $_SESSION['bh_te_csrf'] = bin2hex(random_bytes(32));
}

$flashError   = null;
$flashSuccess = null;

if (isset($_SESSION['bh_te_flash_error'])) {
    $flashError = $_SESSION['bh_te_flash_error'];
    unset($_SESSION['bh_te_flash_error']);
}
if (isset($_SESSION['bh_te_flash_success'])) {
    $flashSuccess = $_SESSION['bh_te_flash_success'];
    unset($_SESSION['bh_te_flash_success']);
}

// ── Load events ───────────────────────────────────────────────────────────────
$events    = [];
$loadError = null;

try {
    if ($userId > 0 && $currentBotId > 0) {
        bh_te_ensure_tables();
        $events = bh_te_list($userId, $currentBotId);
    }
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}

// Group events
$grouped   = [];
$ungrouped = [];
foreach ($events as $evt) {
    $g = trim((string)($evt['group_name'] ?? ''));
    if ($g !== '') {
        $grouped[$g][] = $evt;
    } else {
        $ungrouped[] = $evt;
    }
}
ksort($grouped);

$csrfToken = h((string)$_SESSION['bh_te_csrf']);

// Helper: format schedule summary
if (!function_exists('bh_te_schedule_summary')) :
function bh_te_schedule_summary(array $evt): string
{
    $type = (string)($evt['event_type'] ?? 'interval');
    if ($type === 'schedule') {
        $time = h((string)($evt['schedule_time'] ?? '00:00'));
        $days = h((string)($evt['schedule_days'] ?? 'täglich'));
        return 'Schedule · ' . $time . ' · ' . $days;
    }
    $parts = [];
    if ((int)($evt['interval_days'] ?? 0) > 0)    $parts[] = $evt['interval_days'] . 'd';
    if ((int)($evt['interval_hours'] ?? 0) > 0)   $parts[] = $evt['interval_hours'] . 'h';
    if ((int)($evt['interval_minutes'] ?? 0) > 0) $parts[] = $evt['interval_minutes'] . 'min';
    if ((int)($evt['interval_seconds'] ?? 0) > 0) $parts[] = $evt['interval_seconds'] . 's';
    return 'Interval · ' . (empty($parts) ? 'nicht konfiguriert' : implode(' ', $parts));
}
endif;
?>
<div class="grid grid-cols-12 gap-6">
    <div class="col-span-full">

        <!-- Header card -->
        <div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl mb-6">
            <div class="px-5 py-5 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <div class="text-xs uppercase font-semibold tracking-wide text-violet-500 mb-1">Timed Events</div>
                    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Timed Event Builder</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Automatisch ausgeführte Flows — gesteuert über Zeitplan oder Intervall.
                    </p>
                </div>
                <?php if ($currentBotId > 0): ?>
                    <div class="flex items-center gap-2">
                        <a href="/dashboard/timed-events/builder?bot_id=<?= $currentBotId ?>"
                           class="btn bg-violet-500 hover:bg-violet-600 text-white whitespace-nowrap">
                            + Neues Timed Event
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
        <?php if ($loadError !== null): ?>
            <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                <?= h($loadError) ?>
            </div>
        <?php endif; ?>

        <?php if ($currentBotId <= 0): ?>
            <div class="rounded-xl border border-gray-200 dark:border-gray-700/60 px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800">
                Bitte zuerst einen Bot auswählen.
            </div>

        <?php elseif ($events === []): ?>
            <div class="rounded-xl border border-gray-200 dark:border-gray-700/60 px-4 py-12 text-center bg-white dark:bg-gray-800">
                <div class="text-4xl mb-3">⏰</div>
                <div class="text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Noch keine Timed Events</div>
                <div class="text-sm text-gray-400 dark:text-gray-500 mb-5">Klicke auf "+ Neues Timed Event" um loszulegen.</div>
                <a href="/dashboard/timed-events/builder?bot_id=<?= $currentBotId ?>"
                   class="btn bg-violet-500 hover:bg-violet-600 text-white text-sm">
                    + Neues Timed Event
                </a>
            </div>

        <?php else: ?>

            <!-- Stats bar -->
            <div class="flex items-center gap-4 mb-4 text-sm text-gray-500 dark:text-gray-400">
                <span><strong class="text-gray-700 dark:text-gray-200"><?= count($events) ?></strong> Events</span>
                <span><strong class="text-gray-700 dark:text-gray-200"><?= count(array_filter($events, fn($e) => (int)($e['is_enabled'] ?? 0) === 1)) ?></strong> Aktiv</span>
                <span><strong class="text-gray-700 dark:text-gray-200"><?= count($grouped) ?></strong> Gruppen</span>
            </div>

            <?php
            if (!function_exists('renderTimedEventSection')) :
            function renderTimedEventSection(array $evts, string $sectionLabel, bool $isGroup, int $currentBotId, string $csrfToken): void
            {
            ?>
            <div class="te-section mb-4 bg-white dark:bg-gray-800 rounded-xl shadow-xs border border-gray-200 dark:border-gray-700/60 overflow-hidden"
                 data-section="<?= h($sectionLabel) ?>">

                <!-- Section header -->
                <div class="te-section-head flex items-center gap-3 px-5 py-3 border-b border-gray-100 dark:border-gray-700/60 cursor-pointer select-none"
                     onclick="this.closest('.te-section').classList.toggle('is-collapsed')">
                    <svg class="te-collapse-icon w-4 h-4 text-gray-400 transition-transform" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>

                    <?php if ($isGroup): ?>
                        <svg class="w-4 h-4 text-violet-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                        <span class="font-semibold text-sm text-gray-700 dark:text-gray-200"><?= h($sectionLabel) ?></span>
                    <?php else: ?>
                        <span class="font-medium text-sm text-gray-500 dark:text-gray-400 italic">Ohne Gruppe</span>
                    <?php endif; ?>

                    <span class="ml-auto text-xs text-gray-400"><?= count($evts) ?> Events</span>
                </div>

                <!-- Events in this section -->
                <div class="te-section-body divide-y divide-gray-100 dark:divide-gray-700/60">
                    <?php foreach ($evts as $evt):
                        $evtId      = (int)($evt['id'] ?? 0);
                        $evtName    = trim((string)($evt['name'] ?? ''));
                        $evtDesc    = trim((string)($evt['description'] ?? ''));
                        $isEnabled  = ((int)($evt['is_enabled'] ?? 0) === 1);
                        $groupName  = trim((string)($evt['group_name'] ?? ''));
                        $summary    = bh_te_schedule_summary($evt);
                        $builderUrl = '/dashboard/timed-events/builder?event_id=' . $evtId . '&bot_id=' . $currentBotId;
                    ?>
                    <div class="te-row flex items-center gap-4 px-5 py-4" data-event-id="<?= $evtId ?>">

                        <!-- Enable toggle -->
                        <label class="bh-toggle flex-shrink-0" title="<?= $isEnabled ? 'Deaktivieren' : 'Aktivieren' ?>">
                            <input type="checkbox"
                                   class="bh-toggle-input"
                                   <?= $isEnabled ? 'checked' : '' ?>
                                   data-event-id="<?= $evtId ?>">
                            <span class="bh-toggle-track">
                                <span class="bh-toggle-thumb"></span>
                            </span>
                        </label>

                        <!-- Icon -->
                        <div class="flex-shrink-0 w-9 h-9 rounded-lg <?= $isEnabled ? 'bg-violet-100 dark:bg-violet-500/20 text-violet-600 dark:text-violet-300' : 'bg-gray-100 dark:bg-gray-700/50 text-gray-400' ?> flex items-center justify-center te-row-icon">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>

                        <!-- Info -->
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-semibold text-sm text-gray-800 dark:text-gray-100 truncate">
                                    <?= h($evtName !== '' ? $evtName : 'Event #' . $evtId) ?>
                                </span>
                                <span class="text-xs rounded-full px-2 py-0.5 font-medium <?= $isEnabled ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' : 'bg-gray-100 text-gray-500 dark:bg-gray-700/60 dark:text-gray-400' ?> te-status-badge">
                                    <?= $isEnabled ? 'Aktiv' : 'Inaktiv' ?>
                                </span>
                            </div>
                            <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5 truncate">
                                <?= h($summary) ?><?= $evtDesc !== '' ? ' · ' . h($evtDesc) : '' ?>
                            </div>
                        </div>

                        <!-- Group inline editor -->
                        <div class="flex-shrink-0 hidden sm:flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                            <input type="text"
                                   class="te-group-input text-xs border border-gray-200 dark:border-gray-600 rounded-lg px-2 py-1 bg-transparent text-gray-600 dark:text-gray-300 placeholder-gray-400 focus:outline-none focus:border-violet-400 w-28"
                                   placeholder="Gruppe…"
                                   maxlength="80"
                                   value="<?= h($groupName) ?>"
                                   data-event-id="<?= $evtId ?>"
                                   data-original="<?= h($groupName) ?>">
                        </div>

                        <!-- Actions -->
                        <div class="flex-shrink-0 flex items-center gap-2">
                            <a href="<?= $builderUrl ?>"
                               class="btn-sm bg-violet-500 hover:bg-violet-600 text-white text-xs">
                                Builder
                            </a>
                            <button type="button"
                                    class="btn-sm border border-gray-200 hover:border-rose-300 hover:text-rose-600 dark:border-gray-700 dark:hover:border-rose-400 dark:hover:text-rose-300 text-xs te-delete-btn"
                                    data-event-id="<?= $evtId ?>">
                                Löschen
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php } // end renderTimedEventSection
            endif; ?>

            <?php foreach ($grouped as $groupName => $groupEvts): ?>
                <?php renderTimedEventSection($groupEvts, $groupName, true, $currentBotId, $csrfToken); ?>
            <?php endforeach; ?>

            <?php if ($ungrouped !== []): ?>
                <?php renderTimedEventSection($ungrouped, '', false, $currentBotId, $csrfToken); ?>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</div>


<script>
(function () {
    const CSRF   = <?= json_encode((string)$_SESSION['bh_te_csrf']) ?>;
    const BOT_ID = <?= (int)$currentBotId ?>;

    async function teApi(action, payload) {
        const res = await fetch('/api/v1/timed_events.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-TE-CSRF': CSRF,
            },
            body: JSON.stringify({ action, ...payload }),
        });
        return res.json();
    }

    // ── Toggle enable/disable ─────────────────────────────────────────────────
    document.querySelectorAll('.bh-toggle-input').forEach((input) => {
        input.addEventListener('change', async function () {
            const eventId = parseInt(this.dataset.eventId, 10);
            const enabled = this.checked;
            const row     = this.closest('.te-row');

            applyEnabledState(row, enabled);

            try {
                const data = await teApi('toggle_enabled', { event_id: eventId, enabled });
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
        const badge = row.querySelector('.te-status-badge');
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
        const icon = row.querySelector('.te-row-icon');
        if (icon) {
            icon.className = icon.className
                .replace(/\b(bg-violet-100|dark:bg-violet-500\/20|text-violet-600|dark:text-violet-300|bg-gray-100|dark:bg-gray-700\/50|text-gray-400)\b/g, '')
                .trim();
            if (enabled) {
                icon.classList.add('bg-violet-100','dark:bg-violet-500/20','text-violet-600','dark:text-violet-300');
            } else {
                icon.classList.add('bg-gray-100','dark:bg-gray-700/50','text-gray-400');
            }
        }
    }

    // ── Delete event ──────────────────────────────────────────────────────────
    document.querySelectorAll('.te-delete-btn').forEach((btn) => {
        btn.addEventListener('click', async function () {
            if (!confirm('Dieses Timed Event wirklich löschen?')) return;

            const eventId = parseInt(this.dataset.eventId, 10);
            const row = this.closest('.te-row');

            btn.disabled = true;
            btn.textContent = '…';

            try {
                const data = await teApi('delete_event', { event_id: eventId });
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

    // ── Group inline editor ───────────────────────────────────────────────────
    document.querySelectorAll('.te-group-input').forEach((input) => {
        async function saveGroup() {
            const eventId  = parseInt(input.dataset.eventId, 10);
            const newGroup = input.value.trim();
            if (newGroup === input.dataset.original) return;

            input.disabled = true;
            try {
                const data = await teApi('set_group', { event_id: eventId, group_name: newGroup });
                if (data.ok) {
                    input.dataset.original = newGroup;
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
            if (e.key === 'Enter')  { e.preventDefault(); input.blur(); }
            if (e.key === 'Escape') { input.value = input.dataset.original; input.blur(); }
        });
    });
}());
</script>
