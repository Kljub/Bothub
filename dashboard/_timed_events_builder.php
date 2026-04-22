<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    header('Location: /?auth=login', true, 302);
    exit;
}

require_once dirname(__DIR__) . '/functions/html.php';
require_once dirname(__DIR__) . '/functions/timed_events.php';
require_once dirname(__DIR__) . '/functions/custom_command_builder.php';

$userId  = (int)$_SESSION['user_id'];
$eventId = isset($_GET['event_id']) && is_numeric($_GET['event_id']) ? (int)$_GET['event_id'] : 0;

if (!isset($_SESSION['bh_te_builder_csrf']) || $_SESSION['bh_te_builder_csrf'] === '') {
    $_SESSION['bh_te_builder_csrf'] = bin2hex(random_bytes(32));
}

$flashError   = null;
$flashSuccess = null;

if (isset($_SESSION['bh_te_builder_flash_error'])) {
    $flashError = $_SESSION['bh_te_builder_flash_error'];
    unset($_SESSION['bh_te_builder_flash_error']);
}
if (isset($_SESSION['bh_te_builder_flash_success'])) {
    $flashSuccess = $_SESSION['bh_te_builder_flash_success'];
    unset($_SESSION['bh_te_builder_flash_success']);
}

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bh_ce_builder_action'])) {
    try {
        $csrf = (string)($_POST['csrf_token'] ?? '');
        $csrfRedirect = $eventId > 0
            ? '/dashboard/timed-events/builder?event_id=' . $eventId
            : '/dashboard/timed-events/builder';

        $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest')
               || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

        if (!hash_equals((string)$_SESSION['bh_te_builder_csrf'], $csrf)) {
            if ($isAjax) { ob_clean(); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'message' => 'Ungültiges CSRF-Token.']); exit; }
            $_SESSION['bh_te_builder_flash_error'] = 'Ungültiges CSRF-Token.';
            header('Location: ' . $csrfRedirect, true, 302); exit;
        }

        $jsonReply = static function (bool $ok, string $msg, int $newId = 0) use ($isAjax, $eventId): void {
            if (!$isAjax) return;
            ob_clean(); header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => $ok, 'message' => $msg, 'event_id' => $newId > 0 ? $newId : $eventId]);
            exit;
        };

        $action = (string)($_POST['bh_ce_builder_action'] ?? '');

        if ($action === 'save_builder') {
            $displayName = trim((string)($_POST['event_name']  ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $builderJson = (string)($_POST['builder_json']     ?? '');
            $postBotId   = isset($_POST['bot_id']) && is_numeric($_POST['bot_id']) ? (int)$_POST['bot_id'] : 0;

            $err = null;
            if ($postBotId <= 0)    $err = 'Kein Bot ausgewählt.';
            elseif ($displayName === '') $err = 'Name darf nicht leer sein.';
            elseif ($builderJson !== '' && json_decode($builderJson) === null) $err = 'Builder-Daten beschädigt.';

            if ($err !== null) {
                $jsonReply(false, $err);
                $_SESSION['bh_te_builder_flash_error'] = $err;
                header('Location: ' . $csrfRedirect, true, 302); exit;
            }

            if ($eventId <= 0) {
                $res = bh_te_create($userId, $postBotId, $displayName, $description);
                if (!($res['ok'] ?? false)) { $e2 = (string)($res['error'] ?? 'Fehler.'); $jsonReply(false, $e2); $_SESSION['bh_te_builder_flash_error'] = $e2; header('Location: /dashboard/timed-events/builder?bot_id=' . $postBotId, true, 302); exit; }
                $eventId = (int)$res['id'];
            } else {
                bh_te_update_meta($userId, $eventId, $displayName, $description);
            }

            $saveRes = bh_te_builder_save($userId, $eventId, $builderJson);
            if (!($saveRes['ok'] ?? false)) { $e2 = (string)($saveRes['error'] ?? 'Speichern fehlgeschlagen.'); $jsonReply(false, $e2, $eventId); $_SESSION['bh_te_builder_flash_error'] = $e2; header('Location: /dashboard/timed-events/builder?event_id=' . $eventId, true, 302); exit; }

            $jsonReply(true, 'Builder gespeichert.', $eventId);
            $_SESSION['bh_te_builder_flash_success'] = 'Builder gespeichert.';
            header('Location: /dashboard/timed-events/builder?event_id=' . $eventId, true, 302); exit;
        }
    } catch (Throwable $e) {
        $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest')
               || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
        if ($isAjax) { ob_clean(); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'message' => 'Serverfehler: ' . $e->getMessage()]); exit; }
        throw $e;
    }
}

// ── Load builder state ─────────────────────────────────────────────────────────
$builderState = null;
$loadError    = null;

if ($eventId > 0) {
    try {
        bh_te_ensure_tables();
        $builderState = bh_te_builder_get($userId, $eventId);
    } catch (Throwable $e) {
        $loadError = $e->getMessage();
    }
}

$event   = is_array($builderState) && isset($builderState['event'])   ? $builderState['event']   : null;
$builder = is_array($builderState) && isset($builderState['builder']) && is_array($builderState['builder'])
    ? $builderState['builder']
    : [
        'version'  => 1,
        'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
        'nodes'    => [
            [
                'id'     => 'node_trigger_1',
                'type'   => 'trigger.timed',
                'label'  => 'Timed Event Trigger',
                'x'      => 160,
                'y'      => 260,
                'config' => ['event_type' => 'interval'],
            ],
            [
                'id'     => 'node_error_1',
                'type'   => 'utility.error_handler',
                'label'  => 'Error Handler',
                'x'      => 560,
                'y'      => 260,
                'config' => ['display_name' => 'Error Handler', 'enabled' => true],
            ],
        ],
        'edges' => [],
    ];

if ($eventId > 0 && $event === null && $loadError === null) {
    $loadError = 'Das Timed Event wurde nicht gefunden.';
}

$currentBotId = $event !== null
    ? (int)($event['bot_id'] ?? 0)
    : (isset($_GET['bot_id']) && is_numeric($_GET['bot_id']) ? (int)$_GET['bot_id'] : 0);

$displayName = $event !== null ? trim((string)($event['name']        ?? '')) : '';
$description = $event !== null ? trim((string)($event['description'] ?? '')) : '';

$botPreviewName   = 'Bot';
$botDiscordUserId = '';
if ($currentBotId > 0) {
    try {
        $pdo  = bh_te_get_pdo();
        $stmt = $pdo->prepare('SELECT display_name, discord_bot_user_id FROM bot_instances WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $currentBotId]);
        $botRow = $stmt->fetch();
        if (is_array($botRow)) {
            $botPreviewName   = trim((string)($botRow['display_name']        ?? '')) ?: 'Bot';
            $botDiscordUserId = trim((string)($botRow['discord_bot_user_id'] ?? ''));
        }
    } catch (Throwable) {}
}

$initialBuilderJson = json_encode($builder, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title><?= h('BotHub – Timed Event Builder') ?></title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link href="/assets/css/custom-command-builder.css" rel="stylesheet">
</head>
<body>
<form id="cc-builder-form" method="post" action="/dashboard/timed-events/builder?event_id=<?= (int)$eventId ?>">
    <input type="hidden" name="bh_ce_builder_action" value="save_builder">
    <input type="hidden" name="csrf_token" value="<?= h((string)$_SESSION['bh_te_builder_csrf']) ?>">
    <input type="hidden" name="bot_id"     value="<?= $currentBotId ?>">
    <input type="hidden" id="command_name" name="event_name"  value="<?= h($displayName) ?>">
    <input type="hidden" id="slash_name"   name="event_type"  value="timed">
    <input type="hidden" id="description"  name="description" value="<?= h($description) ?>">
    <textarea id="builder-json-field"      name="builder_json" class="cc-hidden-field" aria-hidden="true"></textarea>
    <textarea id="cc-builder-initial-json" class="cc-hidden-field" aria-hidden="true"><?= h($initialBuilderJson) ?></textarea>

    <div class="cc-app" id="cc-builder-root" data-mode="timed">

        <header class="cc-topbar">
            <div class="cc-topbar-left">
                <a class="cc-icon-btn" href="/dashboard?view=timed-events<?= $currentBotId > 0 ? '&bot_id=' . $currentBotId : '' ?>" title="Zurück" aria-label="Zurück">‹</a>
                <button type="button" class="cc-top-btn">Docs</button>
                <button type="button" class="cc-top-btn">Settings</button>
                <button type="button" class="cc-top-btn cc-top-btn--accent">Tutorial</button>
            </div>
            <div class="cc-topbar-right">
                <div class="cc-save-status" id="builder-save-status">Unsaved changes</div>
                <button type="submit" class="cc-save-btn" id="cc-builder-save-btn" <?= ($currentBotId <= 0) ? 'disabled' : '' ?>>Save Event</button>
            </div>
        </header>

        <?php if ($flashSuccess !== null): ?>
            <div class="bh-alert bh-alert--ok"><?= h($flashSuccess) ?></div>
        <?php endif; ?>
        <?php if ($flashError !== null): ?>
            <div class="bh-alert bh-alert--err"><?= h($flashError) ?></div>
        <?php endif; ?>
        <?php if ($loadError !== null): ?>
            <div class="bh-alert bh-alert--err"><?= h($loadError) ?></div>
        <?php endif; ?>

        <main class="cc-main">

            <!-- Left rail -->
            <aside class="cc-left">
                <div class="cc-rail">
                    <button type="button" class="cc-rail-btn is-active" data-rail-panel="blocks" title="Actions / Conditions">
                        <svg viewBox="0 0 24 24"><path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    </button>
                    <button type="button" class="cc-rail-btn" data-rail-panel="variables" title="Variables">
                        <svg viewBox="0 0 24 24"><path d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    </button>
                    <button type="button" class="cc-rail-btn" data-rail-panel="errors" title="Error Logs">
                        <svg viewBox="0 0 24 24"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    </button>
                    <button type="button" class="cc-rail-btn" data-rail-panel="events" title="Event Info">
                        <svg viewBox="0 0 24 24"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    </button>
                    <button type="button" class="cc-rail-btn" data-rail-panel="templates" title="Export / Import">
                        <svg viewBox="0 0 24 24"><path d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    </button>
                </div>

                <div class="cc-panel">

                    <!-- Blocks panel -->
                    <section class="cc-panel-section is-active" data-rail-content="blocks">
                        <div class="cc-panel-head">
                            <div>
                                <h2>Blocks</h2>
                                <p>Drag and drop <span class="cc-help-action">Actions</span> and <span class="cc-help-condition">Conditions</span> to build your timed flow.</p>
                            </div>
                            <button type="button" class="cc-menu-btn">☰</button>
                        </div>
                        <div class="cc-tabs">
                            <button type="button" class="cc-tab is-active" data-block-tab="actions">Actions</button>
                            <button type="button" class="cc-tab" data-block-tab="conditions">Conditions</button>
                        </div>
                        <div class="cc-search-wrap">
                            <input type="text" id="cc-block-search" class="cc-search" placeholder="Search">
                        </div>

                        <div class="cc-tab-panel is-active" data-block-tab-content="actions">

                            <div class="cc-group-title">Message</div>
                            <div class="cc-block-list">
                                <?php foreach ([
                                    ['action.message.send_or_edit',   'Send or Edit a Message',       'Send or edit a message with embeds.'],
                                    ['action.message.edit_component', 'Edit a Button or Select Menu', 'Edit a component of a previous message.'],
                                    ['action.delete_message',         'Delete a Message',             'Delete a message by variable or message ID.'],
                                    ['action.message.publish',        'Publish a Message',            'Publish a message to an announcement channel.'],
                                    ['action.message.react',          'React to a Message',           'Add a reaction to a message.'],
                                    ['action.message.pin',            'Pin a Message',                'Pin a message in the current channel.'],
                                ] as [$type, $label, $desc]): ?>
                                <button type="button" class="cc-block-item cc-block-item--action" draggable="true" data-block-type="<?= htmlspecialchars($type) ?>" data-block-label="<?= htmlspecialchars($label) ?>">
                                    <span class="cc-drag-handle">⋮⋮</span><span class="cc-badge">A</span>
                                    <span class="cc-block-copy"><strong><?= htmlspecialchars($label) ?></strong><small><?= htmlspecialchars($desc) ?></small></span>
                                </button>
                                <?php endforeach; ?>
                            </div>

                            <div class="cc-group-title">HTTP</div>
                            <div class="cc-block-list">
                                <button type="button" class="cc-block-item cc-block-item--action" draggable="true" data-block-type="action.http.request" data-block-label="Send API Request">
                                    <span class="cc-drag-handle">⋮⋮</span><span class="cc-badge">A</span>
                                    <span class="cc-block-copy"><strong>Send API Request</strong><small>Make an HTTP request to an external API.</small></span>
                                </button>
                            </div>

                            <div class="cc-group-title">Flow Control</div>
                            <div class="cc-block-list">
                                <?php foreach ([
                                    ['action.flow.loop.run',      'Run Loop',                           'Execute a loop block.'],
                                    ['action.flow.loop.stop',     'Stop Loop',                          'Stop an active loop.'],
                                    ['action.flow.wait',          'Wait before running another Action', 'Wait before executing another action.'],
                                    ['action.text.manipulate',    'Manipulate some text',               'Manipulate and run functions on provided text.'],
                                    ['action.utility.error_log',  'Send an Error Log Message',          'Send an error message to the log channel.'],
                                    ['variable.local.set',        'Set a local unique Variable',        'Set a variable scoped to this execution.'],
                                    ['action.bot.set_status',     'Change the Bot Status',              'Change the bot\'s activity and online status.'],
                                    ['action.utility.note',       'Note',                               'Write a note in the flow tree.'],
                                ] as [$type, $label, $desc]): ?>
                                <button type="button" class="cc-block-item cc-block-item--action" draggable="true" data-block-type="<?= htmlspecialchars($type) ?>" data-block-label="<?= htmlspecialchars($label) ?>">
                                    <span class="cc-drag-handle">⋮⋮</span><span class="cc-badge">A</span>
                                    <span class="cc-block-copy"><strong><?= htmlspecialchars($label) ?></strong><small><?= htmlspecialchars($desc) ?></small></span>
                                </button>
                                <?php endforeach; ?>
                            </div>

                            <div class="cc-group-title">Roles</div>
                            <div class="cc-block-list">
                                <?php foreach ([
                                    ['action.role.add_to_member',       'Add Roles to a Member',       'Add one or more roles to a member.'],
                                    ['action.role.remove_from_member',  'Remove Roles from a Member',  'Remove roles from a member.'],
                                ] as [$type, $label, $desc]): ?>
                                <button type="button" class="cc-block-item cc-block-item--action" draggable="true" data-block-type="<?= htmlspecialchars($type) ?>" data-block-label="<?= htmlspecialchars($label) ?>">
                                    <span class="cc-drag-handle">⋮⋮</span><span class="cc-badge">A</span>
                                    <span class="cc-block-copy"><strong><?= htmlspecialchars($label) ?></strong><small><?= htmlspecialchars($desc) ?></small></span>
                                </button>
                                <?php endforeach; ?>
                            </div>

                            <div class="cc-group-title">Moderation</div>
                            <div class="cc-block-list">
                                <?php foreach ([
                                    ['action.mod.kick',    'Kick Member',          'Kick a member from the server.'],
                                    ['action.mod.ban',     'Ban Member',           'Ban a member from the server.'],
                                    ['action.mod.timeout', 'Timeout a Member',     'Put a member in timeout.'],
                                    ['action.mod.purge',   'Purge Messages',       'Bulk delete messages.'],
                                ] as [$type, $label, $desc]): ?>
                                <button type="button" class="cc-block-item cc-block-item--action" draggable="true" data-block-type="<?= htmlspecialchars($type) ?>" data-block-label="<?= htmlspecialchars($label) ?>">
                                    <span class="cc-drag-handle">⋮⋮</span><span class="cc-badge">A</span>
                                    <span class="cc-block-copy"><strong><?= htmlspecialchars($label) ?></strong><small><?= htmlspecialchars($desc) ?></small></span>
                                </button>
                                <?php endforeach; ?>
                            </div>

                        </div><!-- actions tab -->

                        <div class="cc-tab-panel" data-block-tab-content="conditions">
                            <div class="cc-group-title">Conditions</div>
                            <div class="cc-block-list">
                                <?php foreach ([
                                    ['condition.comparison', 'Comparison Condition',  'Compare two values.'],
                                    ['condition.if_else',    'If – Else Condition',   'Branch on true or false.'],
                                    ['condition.chance',     'Chance Condition',      'Branch by random probability.'],
                                ] as [$type, $label, $desc]): ?>
                                <button type="button" class="cc-block-item cc-block-item--condition" draggable="true" data-block-type="<?= htmlspecialchars($type) ?>" data-block-label="<?= htmlspecialchars($label) ?>">
                                    <span class="cc-drag-handle">⋮⋮</span><span class="cc-badge">C</span>
                                    <span class="cc-block-copy"><strong><?= htmlspecialchars($label) ?></strong><small><?= htmlspecialchars($desc) ?></small></span>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div><!-- conditions tab -->

                    </section><!-- blocks panel -->

                    <!-- Variables panel -->
                    <section class="cc-panel-section" data-rail-content="variables">
                        <div class="cc-panel-head">
                            <div class="cc-panel-head-text">
                                <strong>Variables</strong>
                                <p>Drag and drop variables to use across the flow.</p>
                            </div>
                        </div>
                        <div class="cc-group-title cc-group-title--var-local">Local Variables</div>
                        <p class="cc-var-scope-hint">Only available within this execution.</p>
                        <div class="cc-block-list">
                            <button type="button" class="cc-block-item cc-block-item--variable" draggable="true" data-block-type="variable.local.set" data-block-label="Set Local Variable">
                                <span class="cc-drag-handle">⋮⋮</span>
                                <span class="cc-badge cc-badge--var">$</span>
                                <span class="cc-block-copy"><strong>Set Local Variable</strong><small>Set a variable for this execution.</small></span>
                            </button>
                        </div>
                        <div class="cc-group-title cc-group-title--var-global">Global Variables</div>
                        <p class="cc-var-scope-hint">Persisted in the database.</p>
                        <div class="cc-block-list">
                            <button type="button" class="cc-block-item cc-block-item--variable" draggable="true" data-block-type="variable.global.set" data-block-label="Set Global Variable">
                                <span class="cc-drag-handle">⋮⋮</span><span class="cc-badge cc-badge--var">$</span>
                                <span class="cc-block-copy"><strong>Set Global Variable</strong><small>Write a persistent value.</small></span>
                            </button>
                            <button type="button" class="cc-block-item cc-block-item--variable" draggable="true" data-block-type="variable.global.delete" data-block-label="Delete Global Variable">
                                <span class="cc-drag-handle">⋮⋮</span><span class="cc-badge cc-badge--var">$</span>
                                <span class="cc-block-copy"><strong>Delete Global Variable</strong><small>Remove a global variable.</small></span>
                            </button>
                        </div>
                        <div class="cc-var-usage-hint">
                            <strong>Usage:</strong><br>
                            <code>{local.varname}</code> — local variable<br>
                            <code>{global.varname}</code> — global variable
                        </div>
                    </section>

                    <!-- Errors panel -->
                    <section class="cc-panel-section" data-rail-content="errors">
                        <div class="cc-simple-panel">
                            <h2>Error Logs</h2>
                            <p>Fehlerbehandlung über den Error Handler Node.</p>
                        </div>
                    </section>

                    <!-- Event info panel -->
                    <section class="cc-panel-section" data-rail-content="events">
                        <div class="cc-simple-panel">
                            <h2>Timed Event wechseln</h2>
                            <a class="cc-panel-link-btn" href="/dashboard?view=timed-events<?= $currentBotId > 0 ? '&bot_id=' . $currentBotId : '' ?>">Zur Timed Events Liste</a>
                            <h2 style="margin-top:20px">Event Info</h2>
                            <div style="display:flex;flex-direction:column;gap:10px;margin-top:8px">
                                <label style="font-size:12px;color:var(--cc-text-soft)">
                                    Event Name
                                    <input type="text" id="cc-event-meta-name" class="cc-input" style="margin-top:4px;display:block;width:100%"
                                           placeholder="My Timed Event" value="<?= h($displayName) ?>">
                                </label>
                                <label style="font-size:12px;color:var(--cc-text-soft)">
                                    Description (optional)
                                    <input type="text" id="cc-event-meta-desc" class="cc-input" style="margin-top:4px;display:block;width:100%"
                                           placeholder="What does this event do?" value="<?= h($description) ?>">
                                </label>
                            </div>
                        </div>
                    </section>

                    <!-- Templates panel -->
                    <section class="cc-panel-section" data-rail-content="templates">
                        <div class="cc-simple-panel">
                            <h2>Export / Import</h2>
                            <div class="cc-template-actions">
                                <button type="button" id="cc-export-builder-btn" class="cc-panel-link-btn">Export JSON</button>
                                <label class="cc-panel-link-btn" for="cc-import-builder-file">Import JSON</label>
                                <input type="file" id="cc-import-builder-file" accept="application/json,.json">
                            </div>
                        </div>
                    </section>

                </div><!-- .cc-panel -->
            </aside><!-- .cc-left -->

            <!-- Canvas -->
            <section class="cc-stage">
                <div class="cc-stage-toolbar">
                    <button type="button" class="cc-stage-btn" id="cc-builder-center-btn">Center</button>
                    <button type="button" class="cc-stage-btn" id="cc-builder-clear-selection-btn">Clear</button>
                    <div class="cc-zoom-group">
                        <button type="button" class="cc-stage-btn cc-stage-btn--square" id="cc-builder-zoom-out-btn">−</button>
                        <button type="button" class="cc-stage-btn cc-stage-btn--zoom"   id="cc-builder-zoom-reset-btn">100%</button>
                        <button type="button" class="cc-stage-btn cc-stage-btn--square" id="cc-builder-zoom-in-btn">+</button>
                    </div>
                </div>
                <div class="cc-canvas-wrap">
                    <div class="cc-canvas" id="cc-builder-canvas">
                        <div class="cc-world" id="cc-builder-world">
                            <svg class="cc-edges" id="cc-builder-edges" aria-hidden="true"></svg>
                            <div class="cc-canvas-inner" id="cc-builder-canvas-inner"></div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Properties drawer -->
            <aside class="cc-drawer" id="cc-properties-drawer">
                <div class="cc-drawer-empty" id="cc-builder-properties-empty">
                    <div class="cc-drawer-head">
                        <div class="cc-drawer-label">Properties</div>
                        <h2>Ausgewählter Block</h2>
                    </div>
                    Wähle einen Block aus, um dessen Eigenschaften zu bearbeiten.
                </div>
                <div class="cc-drawer-panel is-hidden" id="cc-builder-properties-panel">
                    <div class="cc-drawer-head">
                        <div class="cc-drawer-label">Properties</div>
                        <h2>Ausgewählter Block</h2>
                    </div>
                    <div id="cc-builder-dynamic-fields"></div>
                    <div class="cc-prop-actions">
                        <button type="button" class="cc-danger-btn" id="cc-builder-delete-node-btn">Block löschen</button>
                    </div>
                </div>
            </aside>

        </main>
    </div><!-- #cc-builder-root -->
</form>

<!-- Message Builder Modal (same as event builder) -->
<div id="cc-msg-builder-overlay" class="cc-mb-overlay" aria-hidden="true">
    <div class="cc-mb-modal" role="dialog" aria-label="Message Builder">
        <div class="cc-mb-header">
            <div class="cc-mb-header-left">
                <h2 class="cc-mb-title">Message Builder</h2>
                <p class="cc-mb-subtitle">Craft powerful Discord messages with our intuitive Message Builder.</p>
            </div>
            <div class="cc-mb-header-right">
                <button type="button" class="cc-mb-save-btn" id="cc-mb-save-btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Übernehmen
                </button>
                <button type="button" class="cc-mb-close-btn" id="cc-mb-close-btn" aria-label="Schließen">✕</button>
            </div>
        </div>
        <div class="cc-mb-body">
            <div class="cc-mb-left">
                <div class="cc-mb-section">
                    <div class="cc-mb-section-head">
                        <span class="cc-mb-section-label">Response type <span class="cc-mb-section-hint">Where the bot should send this message.</span></span>
                    </div>
                    <select class="cc-mb-select" id="cc-mb-response-type">
                        <optgroup label="Channel">
                            <option value="channel">Event channel</option>
                            <option value="specific_channel">Specific channel</option>
                        </optgroup>
                        <optgroup label="Edit">
                            <option value="edit_action">Edit a message by variable</option>
                        </optgroup>
                    </select>
                    <div class="cc-mb-cond-field" id="cc-mb-cond-specific-channel" style="display:none">
                        <label class="cc-mb-field-label">Channel ID</label>
                        <input type="text" class="cc-mb-input" id="cc-mb-target-channel-id" placeholder="Channel ID…" maxlength="32">
                    </div>
                    <div class="cc-mb-cond-field" id="cc-mb-cond-channel-option" style="display:none"></div>
                    <div class="cc-mb-cond-field" id="cc-mb-cond-dm-user-option" style="display:none"></div>
                    <div class="cc-mb-cond-field" id="cc-mb-cond-dm-specific-user" style="display:none"></div>
                    <div class="cc-mb-cond-field" id="cc-mb-cond-edit-action" style="display:none">
                        <label class="cc-mb-field-label">Edit Target Variable</label>
                        <input type="text" class="cc-mb-input" id="cc-mb-edit-target-var" placeholder="z.B. my_message" maxlength="64">
                    </div>
                </div>
                <div class="cc-mb-divider"></div>
                <div class="cc-mb-section">
                    <div class="cc-mb-section-head">
                        <span class="cc-mb-section-label">Message Content <span class="cc-mb-counter" id="cc-mb-content-count">0</span>/2000</span>
                        <div class="cc-mb-section-tools"><button type="button" class="cc-mb-tool-btn" id="cc-mb-variables-btn" title="Variable einfügen">{x}</button></div>
                    </div>
                    <textarea class="cc-mb-textarea" id="cc-mb-content" maxlength="2000" placeholder="Nachrichteninhalt…" rows="5"></textarea>
                </div>
                <div class="cc-mb-divider"></div>
                <div class="cc-mb-section">
                    <div class="cc-mb-section-head">
                        <span class="cc-mb-section-label">Embeds <span class="cc-mb-counter" id="cc-mb-embeds-count">0</span>/10</span>
                    </div>
                    <div class="cc-mb-embed-actions">
                        <button type="button" class="cc-mb-primary-btn" id="cc-mb-add-embed-btn">Add Embed</button>
                        <button type="button" class="cc-mb-ghost-btn" id="cc-mb-clear-embeds-btn">Clear Embeds</button>
                    </div>
                    <div id="cc-mb-embeds-list"></div>
                </div>
            </div>
            <div class="cc-mb-right">
                <div class="cc-mb-preview-wrap">
                    <div class="cc-mb-preview" id="cc-mb-preview">
                        <div class="cc-mb-discord-msg" id="cc-mb-discord-msg">
                            <div class="cc-mb-discord-avatar" id="cc-mb-bot-avatar">
                                <img src="" alt="Bot" id="cc-mb-bot-avatar-img">
                                <div class="cc-mb-discord-avatar-fallback" id="cc-mb-bot-avatar-fallback"><?= h(mb_substr($botPreviewName, 0, 1, 'UTF-8')) ?></div>
                            </div>
                            <div class="cc-mb-discord-content">
                                <div class="cc-mb-discord-name-row">
                                    <span class="cc-mb-discord-name" id="cc-mb-bot-name"><?= h($botPreviewName) ?></span>
                                    <span class="cc-mb-discord-badge">BOT</span>
                                    <span class="cc-mb-discord-time" id="cc-mb-preview-time">Today at 00:00</span>
                                </div>
                                <div class="cc-mb-discord-text" id="cc-mb-preview-text"></div>
                                <div id="cc-mb-preview-embeds"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<template id="cc-mb-embed-tpl">
    <div class="cc-mb-embed-item" data-embed-idx="">
        <div class="cc-mb-embed-head">
            <div class="cc-mb-embed-color-wrap"><input type="color" class="cc-mb-color-picker" value="#5865F2" title="Embed Farbe"></div>
            <span class="cc-mb-embed-title-label">Embed <span class="cc-mb-embed-num">1</span></span>
            <button type="button" class="cc-mb-embed-del-btn" title="Embed löschen">✕</button>
        </div>
        <div class="cc-mb-embed-fields">
            <div class="cc-mb-field-row"><label>Author Name</label><input type="text" class="cc-mb-input" data-field="author_name" placeholder="Autor…" maxlength="256"></div>
            <div class="cc-mb-field-row"><label>Title</label><input type="text" class="cc-mb-input" data-field="title" placeholder="Titel…" maxlength="256"></div>
            <div class="cc-mb-field-row"><label>URL</label><input type="text" class="cc-mb-input" data-field="url" placeholder="https://…"></div>
            <div class="cc-mb-field-row"><label>Description</label><textarea class="cc-mb-input cc-mb-input--ta" data-field="description" placeholder="Beschreibung…" maxlength="4096" rows="3"></textarea></div>
            <div class="cc-mb-field-row"><label>Thumbnail URL</label><input type="text" class="cc-mb-input" data-field="thumbnail_url" placeholder="https://…"></div>
            <div class="cc-mb-field-row"><label>Image URL</label><input type="text" class="cc-mb-input" data-field="image_url" placeholder="https://…"></div>
            <div class="cc-mb-field-row"><label>Footer Text</label><input type="text" class="cc-mb-input" data-field="footer_text" placeholder="Footer…" maxlength="2048"></div>
            <div class="cc-mb-field-row cc-mb-field-row--inline">
                <label class="cc-mb-switch-label"><span class="cc-mb-switch"><input type="checkbox" data-field="timestamp"><span class="cc-mb-switch-slider"></span></span>Timestamp anzeigen</label>
            </div>
            <div class="cc-mb-subfields-head"><span>Fields <span class="cc-mb-fields-count">0</span>/25</span><button type="button" class="cc-mb-add-field-btn">+ Field</button></div>
            <div class="cc-mb-subfields-list"></div>
        </div>
    </div>
</template>

<template id="cc-mb-field-tpl">
    <div class="cc-mb-subfield-item">
        <div class="cc-mb-subfield-head">
            <span>Field</span>
            <div class="cc-mb-subfield-right">
                <label class="cc-mb-switch-label cc-mb-switch-label--sm"><span class="cc-mb-switch cc-mb-switch--sm"><input type="checkbox" data-subfield="inline"><span class="cc-mb-switch-slider"></span></span>Inline</label>
                <button type="button" class="cc-mb-field-del-btn">✕</button>
            </div>
        </div>
        <div class="cc-mb-field-row"><label>Name</label><input type="text" class="cc-mb-input" data-subfield="name" placeholder="Name…" maxlength="256"></div>
        <div class="cc-mb-field-row"><label>Value</label><textarea class="cc-mb-input cc-mb-input--ta" data-subfield="value" placeholder="Wert…" maxlength="1024" rows="2"></textarea></div>
    </div>
</template>

<!-- Timed Event Builder Modal -->
<div id="cc-te-overlay" class="cc-mb-overlay" aria-hidden="true">
    <div class="cc-mb-modal cc-te-modal" role="dialog" aria-label="Timed Event Builder">
        <div class="cc-mb-header">
            <div class="cc-mb-header-left">
                <h2 class="cc-mb-title">Timed Event Trigger <span class="cc-te-help-icon" title="Hilfe">?</span></h2>
                <p class="cc-mb-subtitle">Create a new timed event to be triggered on a schedule or interval.</p>
            </div>
            <div class="cc-mb-header-right">
                <button type="button" class="cc-mb-save-btn" id="cc-te-save-btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Übernehmen
                </button>
                <button type="button" class="cc-mb-close-btn" id="cc-te-close-btn" aria-label="Schließen">✕</button>
            </div>
        </div>
        <div class="cc-te-body">
            <div class="cc-te-field-group">
                <label class="cc-te-field-label">Event Name <span class="cc-te-field-desc">A descriptive name for the event</span></label>
                <input type="text" class="cc-te-input" id="cc-te-name" placeholder="Name" maxlength="100">
            </div>
            <div class="cc-te-field-group">
                <label class="cc-te-field-label">Event Type <span class="cc-te-field-desc">Schedule or interval-based trigger.</span></label>
                <select class="cc-te-select" id="cc-te-type">
                    <option value="interval">Interval</option>
                    <option value="schedule">Schedule</option>
                </select>
            </div>
            <div class="cc-te-divider"></div>
            <div id="cc-te-interval-section">
                <div class="cc-te-section-title">Interval Timed Event</div>
                <p class="cc-te-section-desc">Run events every X amount of seconds, minutes, hours or days. The minimum interval is 10 seconds.</p>
                <div class="cc-te-interval-grid">
                    <div class="cc-te-interval-cell"><label class="cc-te-interval-label">Seconds</label><input type="number" class="cc-te-number" id="cc-te-seconds" min="0" max="59" value="0"></div>
                    <div class="cc-te-interval-cell"><label class="cc-te-interval-label">Minutes</label><input type="number" class="cc-te-number" id="cc-te-minutes" min="0" max="59" value="0"></div>
                    <div class="cc-te-interval-cell"><label class="cc-te-interval-label">Hours</label><input type="number" class="cc-te-number" id="cc-te-hours" min="0" max="23" value="0"></div>
                    <div class="cc-te-interval-cell"><label class="cc-te-interval-label">Days</label><input type="number" class="cc-te-number" id="cc-te-days" min="0" max="365" value="0"></div>
                </div>
                <div class="cc-te-field-group" style="margin-top:16px">
                    <label class="cc-te-interval-label">Days of the Week</label>
                    <div class="cc-te-weekdays" id="cc-te-interval-weekdays">
                        <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $day): ?>
                        <button type="button" class="cc-te-day-btn is-active" data-day="<?= $day ?>"><?= $day ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div id="cc-te-schedule-section" style="display:none">
                <div class="cc-te-section-title">Scheduled Timed Event</div>
                <p class="cc-te-section-desc">Run events at a specific time each day. Uses the server timezone.</p>
                <div class="cc-te-field-group">
                    <label class="cc-te-interval-label">Time (HH:MM)</label>
                    <input type="time" class="cc-te-input" id="cc-te-schedule-time" value="00:00">
                </div>
                <div class="cc-te-field-group" style="margin-top:16px">
                    <label class="cc-te-interval-label">Days of the Week</label>
                    <div class="cc-te-weekdays" id="cc-te-schedule-weekdays">
                        <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $day): ?>
                        <button type="button" class="cc-te-day-btn is-active" data-day="<?= $day ?>"><?= $day ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    window.BuilderDefinitions = <?= json_encode(custom_command_builder_definitions()); ?>;
    window.BuilderPalette     = <?= json_encode(custom_command_builder_palette()); ?>;
    window.CcBotMeta = {
        name:   <?= json_encode($botPreviewName) ?>,
        userId: <?= json_encode($botDiscordUserId) ?>,
        botId:  <?= json_encode($currentBotId) ?>,
    };
    window.CebEventTypes  = {};
    window.CebEventLabels = {};
</script>
<script src="/assets/js/custom-command-builder.js"></script>
</body>
</html>
