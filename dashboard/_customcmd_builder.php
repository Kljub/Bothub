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
require_once dirname(__DIR__) . '/functions/custom_commands.php';
require_once dirname(__DIR__) . '/functions/custom_command_builder.php';

$userId = (int)$_SESSION['user_id'];
$commandId = isset($_GET['command_id']) && is_numeric($_GET['command_id']) ? (int)$_GET['command_id'] : 0;

if (!isset($_SESSION['bh_cc_builder_csrf']) || !is_string($_SESSION['bh_cc_builder_csrf']) || $_SESSION['bh_cc_builder_csrf'] === '') {
    $_SESSION['bh_cc_builder_csrf'] = bin2hex(random_bytes(32));
}

$flashError = null;
$flashSuccess = null;

if (isset($_SESSION['bh_cc_builder_flash_error']) && is_string($_SESSION['bh_cc_builder_flash_error'])) {
    $flashError = $_SESSION['bh_cc_builder_flash_error'];
    unset($_SESSION['bh_cc_builder_flash_error']);
}

if (isset($_SESSION['bh_cc_builder_flash_success']) && is_string($_SESSION['bh_cc_builder_flash_success'])) {
    $flashSuccess = $_SESSION['bh_cc_builder_flash_success'];
    unset($_SESSION['bh_cc_builder_flash_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bh_cc_builder_action'])) {
try {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    $csrfRedirect = $commandId > 0
        ? '/dashboard/custom-commands/builder?command_id=' . $commandId
        : '/dashboard/custom-commands/builder?bot_id=' . $currentBotId;
    if (!hash_equals((string)$_SESSION['bh_cc_builder_csrf'], $csrf)) {
        $_SESSION['bh_cc_builder_flash_error'] = 'Ungültiges CSRF-Token.';
        header('Location: ' . $csrfRedirect, true, 302);
        exit;
    }

    $action = (string)($_POST['bh_cc_builder_action'] ?? '');

    // Detect AJAX save (fetch with X-Requested-With or Accept: application/json)
    $isAjaxSave = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest')
               || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    if ($action === 'save_builder') {
        $displayName = (string)($_POST['command_name'] ?? '');
        $slashName   = (string)($_POST['slash_name']   ?? '');
        $description = (string)($_POST['description']  ?? '');
        $builderJson = (string)($_POST['builder_json'] ?? '');
        $postBotId   = isset($_POST['bot_id']) && is_numeric($_POST['bot_id']) ? (int)$_POST['bot_id'] : $currentBotId;

        // Helper: send JSON response for AJAX saves and exit
        $jsonReply = static function(bool $ok, string $msg, int $newCommandId = 0) use ($isAjaxSave, $commandId): void {
            if (!$isAjaxSave) { return; }
            ob_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'         => $ok,
                'message'    => $msg,
                'command_id' => $newCommandId > 0 ? $newCommandId : $commandId,
            ]);
            exit;
        };

        // ── Input validation with specific messages ───────────────────────────
        $validationError = null;

        if ($postBotId <= 0) {
            $validationError = 'Kein Bot ausgewählt. Bitte wähle zuerst einen Bot aus.';
        } elseif (trim($slashName) === '') {
            $validationError = 'Der Slash-Command braucht einen Namen (z.B. "meincommand").';
        } elseif (!preg_match('/^[-_a-z0-9\p{L}]{1,32}$/u', $slashName)) {
            $validationError = 'Ungültiger Slash-Name „' . htmlspecialchars($slashName) . '". Nur Kleinbuchstaben, Zahlen, Bindestriche und Unterstriche erlaubt (max. 32 Zeichen).';
        } elseif (trim($displayName) === '') {
            $validationError = 'Bitte gib einen Anzeigenamen für den Command ein.';
        } elseif (trim($description) === '') {
            $validationError = 'Die Beschreibung darf nicht leer sein. Discord verlangt eine kurze Beschreibung.';
        } elseif ($builderJson !== '' && json_decode($builderJson) === null) {
            $validationError = 'Die Builder-Daten sind beschädigt. Bitte lade die Seite neu.';
        }

        if ($validationError !== null) {
            if ($isAjaxSave) { $jsonReply(false, $validationError); }
            $_SESSION['bh_cc_builder_flash_error'] = $validationError;
            $fallbackUrl = $commandId > 0
                ? '/dashboard/custom-commands/builder?command_id=' . $commandId
                : '/dashboard/custom-commands/builder?bot_id=' . $postBotId;
            header('Location: ' . $fallbackUrl, true, 302);
            exit;
        }

        if ($commandId <= 0) {
            if ($postBotId <= 0) {
                if ($isAjaxSave) { $jsonReply(false, 'Kein Bot ausgewählt.'); }
                $_SESSION['bh_cc_builder_flash_error'] = 'Kein Bot ausgewählt.';
                header('Location: /dashboard/custom-commands/builder', true, 302);
                exit;
            }
            $createResult = bh_cc_create_custom_command($userId, $postBotId, $displayName, $slashName, $description);
            if (!($createResult['ok'] ?? false)) {
                $err = (string)($createResult['error'] ?? 'Der Command konnte nicht erstellt werden.');
                if ($isAjaxSave) { $jsonReply(false, $err); }
                $_SESSION['bh_cc_builder_flash_error'] = $err;
                header('Location: /dashboard/custom-commands/builder?bot_id=' . $postBotId, true, 302);
                exit;
            }
            $commandId = (int)$createResult['id'];

            $saveResult = bh_cc_builder_save($userId, $commandId, $builderJson);
            if (!($saveResult['ok'] ?? false)) {
                $err = (string)($saveResult['error'] ?? 'Der Builder konnte nicht gespeichert werden.');
                if ($isAjaxSave) { $jsonReply(false, $err, $commandId); }
                $_SESSION['bh_cc_builder_flash_error'] = $err;
                header('Location: /dashboard/custom-commands/builder?command_id=' . $commandId, true, 302);
                exit;
            }

            bh_notify_bot_reload($postBotId);
            if ($isAjaxSave) { $jsonReply(true, 'Command erstellt und Builder gespeichert.', $commandId); }
            $_SESSION['bh_cc_builder_flash_success'] = 'Command erstellt und Builder gespeichert.';
            header('Location: /dashboard/custom-commands/builder?command_id=' . $commandId, true, 302);
            exit;
        }

        $metaResult = bh_cc_update_custom_command_meta($userId, $commandId, $displayName, $slashName, $description);
        if (!($metaResult['ok'] ?? false)) {
            $err = (string)($metaResult['error'] ?? 'Die Command-Metadaten konnten nicht gespeichert werden.');
            if ($isAjaxSave) { $jsonReply(false, $err); }
            $_SESSION['bh_cc_builder_flash_error'] = $err;
            header('Location: /dashboard/custom-commands/builder?command_id=' . $commandId, true, 302);
            exit;
        }

        $saveResult = bh_cc_builder_save($userId, $commandId, $builderJson);
        if (!($saveResult['ok'] ?? false)) {
            $err = (string)($saveResult['error'] ?? 'Der Builder konnte nicht gespeichert werden.');
            if ($isAjaxSave) { $jsonReply(false, $err); }
            $_SESSION['bh_cc_builder_flash_error'] = $err;
            header('Location: /dashboard/custom-commands/builder?command_id=' . $commandId, true, 302);
            exit;
        }

        $savedCommand = bh_cc_get_custom_command($userId, $commandId);
        if (is_array($savedCommand) && isset($savedCommand['bot_id'])) {
            bh_notify_bot_reload((int)$savedCommand['bot_id']);
        }

        if ($isAjaxSave) { $jsonReply(true, 'Builder gespeichert.'); }
        $_SESSION['bh_cc_builder_flash_success'] = 'Builder gespeichert.';
        header('Location: /dashboard/custom-commands/builder?command_id=' . $commandId, true, 302);
        exit;
    }
} catch (Throwable $e) {
    $isAjaxSave = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest')
               || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    if ($isAjaxSave) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Serverfehler: ' . $e->getMessage()]);
        exit;
    }
    throw $e;
}
}

$builderState = null;
$loadError = null;

if ($commandId > 0) {
    try {
        $builderState = bh_cc_builder_get($userId, $commandId);
    } catch (Throwable $e) {
        $loadError = $e->getMessage();
    }
}

$command = is_array($builderState) && isset($builderState['command']) && is_array($builderState['command']) ? $builderState['command'] : null;
$builder = is_array($builderState) && isset($builderState['builder']) && is_array($builderState['builder'])
    ? $builderState['builder']
    : [
        'version' => 1,
        'viewport' => [
            'x' => 0,
            'y' => 0,
            'zoom' => 1,
        ],
        'nodes' => [
            [
                'id' => 'node_trigger_1',
                'type' => 'trigger.slash',
                'label' => 'Slash Command',
                'x' => 560,
                'y' => 260,
                'config' => [
                    'display_name' => '',
                    'name' => 'command',
                    'description' => '',
                ],
            ],
            [
                'id' => 'node_error_handler_1',
                'type' => 'utility.error_handler',
                'label' => 'Error Handler',
                'x' => 980,
                'y' => 260,
                'config' => [
                    'title' => 'Error Handler',
                ],
            ],
        ],
        'edges' => [
            [
                'id' => 'edge_node_trigger_1_error_node_error_handler_1_in',
                'from_node_id' => 'node_trigger_1',
                'from_port' => 'error',
                'to_node_id' => 'node_error_handler_1',
                'to_port' => 'in',
            ],
        ],
    ];

if ($commandId > 0 && $command === null && $loadError === null) {
    $loadError = 'Der Custom Command wurde nicht gefunden.';
}

$currentBotId = $command !== null ? (int)($command['bot_id'] ?? 0) : (isset($_GET['bot_id']) && is_numeric($_GET['bot_id']) ? (int)$_GET['bot_id'] : 0);

// Load bot meta for the Message Builder preview (name + Discord user ID for avatar)
$botPreviewName   = 'Bot';
$botDiscordUserId = '';
if ($currentBotId > 0) {
    try {
        $pdo = bh_cc_get_pdo();
        $stmt = $pdo->prepare(
            'SELECT display_name, discord_bot_user_id FROM bot_instances WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $currentBotId]);
        $botRow = $stmt->fetch();
        if (is_array($botRow)) {
            $botPreviewName   = trim((string)($botRow['display_name'] ?? '')) ?: 'Bot';
            $botDiscordUserId = trim((string)($botRow['discord_bot_user_id'] ?? ''));
        }
    } catch (Throwable) {
        // non-critical
    }
}

$displayName = $command !== null ? trim((string)($command['name'] ?? '')) : '';
$slashName = $command !== null ? trim((string)($command['slash_name'] ?? '')) : '';
$description = $command !== null ? trim((string)($command['description'] ?? '')) : '';

$initialBuilderJson = json_encode($builder, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if (!is_string($initialBuilderJson) || $initialBuilderJson === '') {
    $initialBuilderJson = '{}';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title><?= h('BotHub – Custom Command Builder') ?></title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link href="/assets/css/custom-command-builder.css" rel="stylesheet">
</head>
<body>
<form id="cc-builder-form" method="post" action="/dashboard/custom-commands/builder?command_id=<?= (int)$commandId ?>">
    <input type="hidden" name="bh_cc_builder_action" value="save_builder">
    <input type="hidden" name="csrf_token" value="<?= h((string)$_SESSION['bh_cc_builder_csrf']) ?>">
    <input type="hidden" name="bot_id" value="<?= $currentBotId ?>">
    <input type="hidden" id="command_name" name="command_name" value="<?= h($displayName) ?>">
    <input type="hidden" id="slash_name" name="slash_name" value="<?= h($slashName) ?>">
    <input type="hidden" id="description" name="description" value="<?= h($description) ?>">
    <textarea id="builder-json-field" name="builder_json" class="cc-hidden-field" aria-hidden="true"></textarea>
    <textarea id="cc-builder-initial-json" class="cc-hidden-field" aria-hidden="true"><?= h($initialBuilderJson) ?></textarea>

    <div class="cc-app" id="cc-builder-root">
        <header class="cc-topbar">
            <div class="cc-topbar-left">
                <a class="cc-icon-btn" href="/dashboard?view=custom-commands<?= $currentBotId > 0 ? '&bot_id=' . $currentBotId : '' ?>" title="Zurück" aria-label="Zurück">‹</a>
                <button type="button" class="cc-top-btn" id="cc-import-command-btn">Import Command</button>
                <button type="button" class="cc-top-btn">Docs</button>
                <button type="button" class="cc-top-btn">Settings</button>
                <button type="button" class="cc-top-btn cc-top-btn--accent">Tutorial</button>
            </div>

            <div class="cc-topbar-right">
                <div class="cc-save-status" id="builder-save-status">Unsaved changes</div>
                <button type="submit" class="cc-save-btn" id="cc-builder-save-btn" <?= ($currentBotId <= 0) ? 'disabled' : '' ?>>Save Command</button>
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
            <aside class="cc-left">
                <div class="cc-rail">
                    <button type="button" class="cc-rail-btn is-active" data-rail-panel="blocks" title="Actions / Options / Conditions">
                        <svg viewBox="0 0 24 24"><path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    </button>

                    <button type="button" class="cc-rail-btn" data-rail-panel="variables" title="Variables">
                        <svg viewBox="0 0 24 24"><path d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    </button>

                    <button type="button" class="cc-rail-btn" data-rail-panel="errors" title="Error Logs">
                        <svg viewBox="0 0 24 24"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    </button>

                    <button type="button" class="cc-rail-btn" data-rail-panel="commands" title="Commands wechseln">
                        <svg viewBox="0 0 24 24"><path d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    </button>

                    <button type="button" class="cc-rail-btn" data-rail-panel="timed" title="Timed Events">
                        <svg viewBox="0 0 24 24"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    </button>

                    <button type="button" class="cc-rail-btn" data-rail-panel="templates" title="Block Templates">
                        <svg viewBox="0 0 24 24"><path d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    </button>
                </div>

                <div class="cc-panel">
                    <section class="cc-panel-section is-active" data-rail-content="blocks">
                        <div class="cc-panel-head">
                            <div>
                                <h2>Blocks</h2>
                                <p>Drag and drop <span class="cc-help-option">Options</span>, <span class="cc-help-action">Actions</span> and <span class="cc-help-condition">Conditions</span> to add them to your command. Connect the corresponding colors to create your command flow.</p>
                            </div>
                            <button type="button" class="cc-menu-btn">☰</button>
                        </div>

                        <div class="cc-tabs">
                            <button type="button" class="cc-tab is-active" data-block-tab="options">Options</button>
                            <button type="button" class="cc-tab" data-block-tab="actions">Actions</button>
                            <button type="button" class="cc-tab" data-block-tab="conditions">Conditions</button>
                        </div>

                        <div class="cc-search-wrap">
                            <input type="text" id="cc-block-search" class="cc-search" placeholder="Search">
                        </div>

                        <div class="cc-tab-panel is-active" data-block-tab-content="options">
                            <div class="cc-group-title">Options</div>
                            <div class="cc-block-list">
                                <button type="button" class="cc-block-item cc-block-item--option" draggable="true" data-block-type="option.text" data-block-label="Text">
                                    <span class="cc-drag-handle">⋮⋮</span>
                                    <span class="cc-badge">T</span>
                                    <span class="cc-block-copy">
                                        <strong>Text</strong>
                                        <small>A text option.</small>
                                    </span>
                                </button>

                                <button type="button" class="cc-block-item cc-block-item--option" draggable="true" data-block-type="option.number" data-block-label="Number">
                                    <span class="cc-drag-handle">⋮⋮</span>
                                    <span class="cc-badge">#</span>
                                    <span class="cc-block-copy">
                                        <strong>Number</strong>
                                        <small>A number option.</small>
                                    </span>
                                </button>

                                <button type="button" class="cc-block-item cc-block-item--option" draggable="true" data-block-type="option.user" data-block-label="User">
                                    <span class="cc-drag-handle">⋮⋮</span>
                                    <span class="cc-badge">U</span>
                                    <span class="cc-block-copy">
                                        <strong>User</strong>
                                        <small>Select a member from the server.</small>
                                    </span>
                                </button>

                                <button type="button" class="cc-block-item cc-block-item--option" draggable="true" data-block-type="option.channel" data-block-label="Channel">
                                    <span class="cc-drag-handle">⋮⋮</span>
                                    <span class="cc-badge">#</span>
                                    <span class="cc-block-copy">
                                        <strong>Channel</strong>
                                        <small>Select a channel from the server.</small>
                                    </span>
                                </button>

                                <button type="button" class="cc-block-item cc-block-item--option" draggable="true" data-block-type="option.role" data-block-label="Role">
                                    <span class="cc-drag-handle">⋮⋮</span>
                                    <span class="cc-badge">R</span>
                                    <span class="cc-block-copy">
                                        <strong>Role</strong>
                                        <small>Select a role from the server.</small>
                                    </span>
                                </button>

                                <button type="button" class="cc-block-item cc-block-item--option" draggable="true" data-block-type="option.choice" data-block-label="Choice">
                                    <span class="cc-drag-handle">⋮⋮</span>
                                    <span class="cc-badge">?</span>
                                    <span class="cc-block-copy">
                                        <strong>Choice</strong>
                                        <small>A True or False option.</small>
                                    </span>
                                </button>

                                <button type="button" class="cc-block-item cc-block-item--option" draggable="true" data-block-type="option.attachment" data-block-label="Attachment">
                                    <span class="cc-drag-handle">⋮⋮</span>
                                    <span class="cc-badge">F</span>
                                    <span class="cc-block-copy">
                                        <strong>Attachment</strong>
                                        <small>An attachment option.</small>
                                    </span>
                                </button>
                            </div>
                        </div>

                        <div class="cc-tab-panel" data-block-tab-content="actions">

                            <div class="cc-group-title">Message</div>
                            <div class="cc-block-list">
                                <?php foreach ([
                                    ['action.message.send_or_edit',    'Send or Edit a Message',        'Send or edit a message with embeds.'],
                                    ['action.message.edit_component',  'Edit a Button or Select Menu',  'Edit a component of a previous message.'],
                                    ['action.message.send_form',       'Send Form',                     'Send a modal form as interaction response.'],
                                    ['action.delete_message',          'Delete a Message',              'Delete a message by variable or message ID.'],
                                    ['action.message.publish',         'Publish a Message',             'Publish a message to an announcement channel.'],
                                    ['action.message.react',           'React to a Message',            'Add a reaction to a message.'],
                                    ['action.message.pin',             'Pin a Message',                 'Pin a message in the current channel.'],
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
                                    ['action.flow.loop.run',      'Run Loop',                       'Execute a loop block.'],
                                    ['action.flow.loop.stop',     'Stop Loop',                      'Stop an active loop.'],
                                    ['action.flow.loop.set_mode', 'Set Loop Mode',                  'Change the mode of a running loop.'],
                                    ['action.flow.wait',          'Wait before running another Action', 'Wait before executing another action.'],
                                    ['action.text.manipulate',    'Manipulate some text',           'Manipulate and run functions on provided text.'],
                                    ['action.utility.error_log',  'Send an Error Log Message',      'Send an error message to the configured log channel.'],
                                    ['variable.local.set',        'Set a local unique Variable',    'Set a variable scoped to this command execution.'],
                                    ['action.bot.set_status',     'Change the Bot Status',          'Change the bot\'s activity and online status.'],
                                    ['action.utility.note',       'Note',                           'Write a note in the flow tree.'],
                                ] as [$type, $label, $desc]): ?>
                                <button type="button" class="cc-block-item cc-block-item--action" draggable="true" data-block-type="<?= htmlspecialchars($type) ?>" data-block-label="<?= htmlspecialchars($label) ?>">
                                    <span class="cc-drag-handle">⋮⋮</span><span class="cc-badge">A</span>
                                    <span class="cc-block-copy"><strong><?= htmlspecialchars($label) ?></strong><small><?= htmlspecialchars($desc) ?></small></span>
                                </button>
                                <?php endforeach; ?>
                            </div>

                            <div class="cc-group-title">Music <span class="cc-group-badge">Optional</span></div>
                            <div class="cc-block-list">
                                <?php foreach ([
                                    ['action.music.create_player', 'Create Music Player',   'Create a music player (YouTube, Spotify, Plex).'],
                                    ['action.music.create_plex',   'Create Plex Player',    'Create a Plex-specific music player.'],
                                    ['action.music.add_queue',     'Add to Queue',          'Add a track to the queue.'],
                                    ['action.music.play_queue',    'Play Queue',            'Start playing the queue.'],
                                    ['action.music.remove_queue',  'Remove Queue',          'Remove a track from the queue.'],
                                    ['action.music.shuffle_queue', 'Shuffle Queue',         'Shuffle the queue randomly.'],
                                    ['action.music.pause',         'Pause Music',           'Pause the current playback.'],
                                    ['action.music.resume',        'Resume Music',          'Resume paused playback.'],
                                    ['action.music.stop',          'Stop Music',            'Stop playback and clear the queue.'],
                                    ['action.music.disconnect',    'Disconnect from VC',    'Disconnect the bot from the voice channel.'],
                                    ['action.music.skip',          'Skip Track',            'Skip the current track.'],
                                    ['action.music.previous',      'Play Previous Track',   'Play the previous track.'],
                                    ['action.music.seek',          'Set Track Position',    'Jump to a specific position in the track.'],
                                    ['action.music.volume',        'Set Volume',            'Set the playback volume (0–200).'],
                                    ['action.music.autoleave',     'Set Autoleave',         'Enable or disable auto-disconnect on inactivity.'],
                                    ['action.music.replay',        'Replay Track',          'Replay the current track.'],
                                    ['action.music.filter',        'Apply Audio Filter',    'Apply an audio filter to the playback.'],
                                    ['action.music.clear_filters', 'Clear Filters',         'Remove all active audio filters.'],
                                    ['action.music.search',        'Search Tracks',         'Search for tracks and show results.'],
                                ] as [$type, $label, $desc]): ?>
                                <button type="button" class="cc-block-item cc-block-item--action" draggable="true" data-block-type="<?= htmlspecialchars($type) ?>" data-block-label="<?= htmlspecialchars($label) ?>">
                                    <span class="cc-drag-handle">⋮⋮</span><span class="cc-badge">A</span>
                                    <span class="cc-block-copy"><strong><?= htmlspecialchars($label) ?></strong><small><?= htmlspecialchars($desc) ?></small></span>
                                </button>
                                <?php endforeach; ?>
                            </div>

                            <div class="cc-group-title">Voice Channel</div>
                            <div class="cc-block-list">
                                <?php foreach ([
                                    ['action.vc.join',          'Join a Voice Channel',         'Make the bot join a voice channel.'],
                                    ['action.vc.leave',         'Leave VC',                     'Leave the current voice channel.'],
                                    ['action.vc.move_member',   'Move a VC Member',             'Move a member to another voice channel.'],
                                    ['action.vc.kick_member',   'Kick a VC Member',             'Kick a member from their voice channel.'],
                                    ['action.vc.mute_member',   'Mute / Unmute a VC Member',    'Server-mute or unmute a member.'],
                                    ['action.vc.deafen_member', 'Deafen / Undeafen a VC Member','Server-deafen or undeafen a member.'],
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
                                    ['action.role.add_to_everyone',     'Add Roles to Everyone',       'Add a role to all server members.'],
                                    ['action.role.remove_from_everyone','Remove Roles from Everyone',  'Remove a role from all server members.'],
                                    ['action.role.create',              'Create a Role',               'Create a new role.'],
                                    ['action.role.delete',              'Delete a Role',               'Delete an existing role.'],
                                    ['action.role.edit',                'Edit a Role',                 'Edit an existing role.'],
                                ] as [$type, $label, $desc]): ?>
                                <button type="button" class="cc-block-item cc-block-item--action" draggable="true" data-block-type="<?= htmlspecialchars($type) ?>" data-block-label="<?= htmlspecialchars($label) ?>">
                                    <span class="cc-drag-handle">⋮⋮</span><span class="cc-badge">A</span>
                                    <span class="cc-block-copy"><strong><?= htmlspecialchars($label) ?></strong><small><?= htmlspecialchars($desc) ?></small></span>
                                </button>
                                <?php endforeach; ?>
                            </div>

                            <div class="cc-group-title">Channels</div>
                            <div class="cc-block-list">
                                <?php foreach ([
                                    ['action.channel.create', 'Create a Channel', 'Create a new text or voice channel.'],
                                    ['action.channel.edit',   'Edit a Channel',   'Edit an existing channel.'],
                                    ['action.channel.delete', 'Delete a Channel', 'Delete a channel.'],
                                    ['action.thread.create',  'Create a Thread',  'Create a new thread.'],
                                    ['action.thread.edit',    'Edit a Thread',    'Edit an existing thread.'],
                                    ['action.thread.delete',  'Delete a Thread',  'Delete a thread.'],
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
                                    ['action.mod.kick',     'Kick Member',                'Kick a member from the server.'],
                                    ['action.mod.ban',      'Ban Member',                 'Ban a member from the server.'],
                                    ['action.mod.timeout',  'Timeout a Member',           'Put a member in timeout.'],
                                    ['action.mod.nickname', 'Change Member\'s Nickname',  'Change a member\'s nickname.'],
                                    ['action.mod.purge',    'Purge Messages',             'Bulk delete messages.'],
                                ] as [$type, $label, $desc]): ?>
                                <button type="button" class="cc-block-item cc-block-item--action" draggable="true" data-block-type="<?= htmlspecialchars($type) ?>" data-block-label="<?= htmlspecialchars($label) ?>">
                                    <span class="cc-drag-handle">⋮⋮</span><span class="cc-badge">A</span>
                                    <span class="cc-block-copy"><strong><?= htmlspecialchars($label) ?></strong><small><?= htmlspecialchars($desc) ?></small></span>
                                </button>
                                <?php endforeach; ?>
                            </div>

                            <div class="cc-group-title">Server</div>
                            <div class="cc-block-list">
                                <?php foreach ([
                                    ['action.server.create_invite', 'Create Server Invite', 'Generate a server invite link.'],
                                    ['action.server.leave',         'Leave Server',          'Make the bot leave the server.'],
                                ] as [$type, $label, $desc]): ?>
                                <button type="button" class="cc-block-item cc-block-item--action" draggable="true" data-block-type="<?= htmlspecialchars($type) ?>" data-block-label="<?= htmlspecialchars($label) ?>">
                                    <span class="cc-drag-handle">⋮⋮</span><span class="cc-badge">A</span>
                                    <span class="cc-block-copy"><strong><?= htmlspecialchars($label) ?></strong><small><?= htmlspecialchars($desc) ?></small></span>
                                </button>
                                <?php endforeach; ?>
                            </div>

                        </div>

                        <div class="cc-tab-panel" data-block-tab-content="conditions">
                            <div class="cc-group-title">Conditions</div>
                            <div class="cc-block-list">
                                <?php foreach ([
                                    ['condition.comparison', 'Comparison Condition',   'Run actions based on the difference between two values.'],
                                    ['condition.if_else',    'If – Else Condition',    'Run different branches on true or false.'],
                                    ['condition.chance',     'Chance Condition',       'Branch by random probability.'],
                                    ['condition.permission', 'Permission Condition',   'Check if the user has a specific permission.'],
                                    ['condition.role',       'Role Condition',         'Check if the user has a specific role.'],
                                    ['condition.channel',    'Channel Condition',      'Check if the command was used in a specific channel.'],
                                    ['condition.user',       'User Condition',         'Check if the user matches a specific user ID.'],
                                    ['condition.status',     'Status Condition',       'Check the user\'s online status.'],
                                ] as [$type, $label, $desc]): ?>
                                <button type="button" class="cc-block-item cc-block-item--condition" draggable="true" data-block-type="<?= htmlspecialchars($type) ?>" data-block-label="<?= htmlspecialchars($label) ?>">
                                    <span class="cc-drag-handle">⋮⋮</span><span class="cc-badge">C</span>
                                    <span class="cc-block-copy"><strong><?= htmlspecialchars($label) ?></strong><small><?= htmlspecialchars($desc) ?></small></span>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </section>

                    <section class="cc-panel-section" data-rail-content="variables">
                        <div class="cc-panel-head">
                            <div class="cc-panel-head-text">
                                <strong>Variables</strong>
                                <p>Drag and drop variables to read or write values across your command flow.</p>
                            </div>
                        </div>

                        <div class="cc-group-title cc-group-title--var-local">Local Variables</div>
                        <p class="cc-var-scope-hint">Only available within this command's execution.</p>
                        <div class="cc-block-list">
                            <button type="button" class="cc-block-item cc-block-item--variable" draggable="true" data-block-type="variable.local.set" data-block-label="Set Local Variable">
                                <span class="cc-drag-handle">⋮⋮</span>
                                <span class="cc-badge cc-badge--var">$</span>
                                <span class="cc-block-copy">
                                    <strong>Set Local Variable</strong>
                                    <small>Set a variable for use within this command.</small>
                                </span>
                            </button>
                        </div>

                        <div class="cc-group-title cc-group-title--var-global">Global Variables</div>
                        <p class="cc-var-scope-hint">Persisted in the database — accessible from all commands of this bot.</p>
                        <div class="cc-block-list">
                            <button type="button" class="cc-block-item cc-block-item--variable" draggable="true" data-block-type="variable.global.set" data-block-label="Set Global Variable">
                                <span class="cc-drag-handle">⋮⋮</span>
                                <span class="cc-badge cc-badge--var">$</span>
                                <span class="cc-block-copy">
                                    <strong>Set Global Variable</strong>
                                    <small>Write a value that persists across all commands.</small>
                                </span>
                            </button>
                            <button type="button" class="cc-block-item cc-block-item--variable" draggable="true" data-block-type="variable.global.delete" data-block-label="Delete Global Variable">
                                <span class="cc-drag-handle">⋮⋮</span>
                                <span class="cc-badge cc-badge--var">$</span>
                                <span class="cc-block-copy">
                                    <strong>Delete Global Variable</strong>
                                    <small>Remove a global variable from the database.</small>
                                </span>
                            </button>
                        </div>

                        <div class="cc-group-title cc-group-title--var-global" style="margin-top:16px">Coming Soon</div>
                        <div class="cc-block-list">
                            <?php foreach ([
                                ['variable.set',      'Set Variables',              'Set one or more variables at once.'],
                                ['variable.equation', 'Run Equation on Variables',  'Run a math equation and store the result.'],
                                ['variable.delete',   'Delete Variables',           'Delete one or more variables.'],
                            ] as [$type, $label, $desc]): ?>
                            <button type="button" class="cc-block-item cc-block-item--variable cc-block-item--coming-soon" disabled title="Coming soon">
                                <span class="cc-drag-handle">⋮⋮</span><span class="cc-badge cc-badge--var">$</span>
                                <span class="cc-block-copy"><strong><?= htmlspecialchars($label) ?></strong><small><?= htmlspecialchars($desc) ?></small></span>
                            </button>
                            <?php endforeach; ?>
                        </div>

                        <div class="cc-var-usage-hint">
                            <strong>Usage in text fields:</strong><br>
                            <code>{local.varname}</code> — read a local variable<br>
                            <code>{global.varname}</code> — read a global variable
                        </div>
                    </section>

                    <section class="cc-panel-section" data-rail-content="errors">
                        <div class="cc-simple-panel">
                            <h2>Error Logs</h2>
                            <p>Kommt später. Hier kannst du später Fehlerpfade und Error Log Targets verwalten.</p>
                        </div>
                    </section>

                    <section class="cc-panel-section" data-rail-content="commands">
                        <div class="cc-simple-panel">
                            <h2>Command wechseln</h2>
                            <p>Wechsel zurück zur Command-Liste und öffne einen anderen Command.</p>
                            <a class="cc-panel-link-btn" href="/dashboard?view=custom-commands<?= $currentBotId > 0 ? '&bot_id=' . $currentBotId : '' ?>">Zur Command-Liste</a>
                        </div>
                    </section>

                    <section class="cc-panel-section" data-rail-content="timed">
                        <div class="cc-simple-panel">
                            <h2>Timed Events</h2>
                            <p>Kommt später. Hier kommen zeitgesteuerte Trigger und Event-Builder rein.</p>
                        </div>
                    </section>

                    <section class="cc-panel-section" data-rail-content="templates">
                        <div class="cc-simple-panel">
                            <h2>Export / Import</h2>
                            <p>Exportiere oder importiere Builder-JSON als Block-Template.</p>
                            <div class="cc-template-actions">
                                <button type="button" id="cc-export-builder-btn" class="cc-panel-link-btn">Export JSON</button>
                                <label class="cc-panel-link-btn" for="cc-import-builder-file">Import JSON</label>
                                <input type="file" id="cc-import-builder-file" accept="application/json,.json">
                            </div>
                        </div>
                    </section>
                </div>
            </aside>

            <section class="cc-stage">
                <div class="cc-stage-toolbar">
                    <button type="button" class="cc-stage-btn cc-stage-btn--square" id="cc-builder-center-btn" title="Center view">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v4M12 18v4M2 12h4M18 12h4"/></svg>
                    </button>
                    <button type="button" class="cc-stage-btn cc-stage-btn--square" id="cc-builder-clear-selection-btn" title="Clear selection">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>
                    <div class="cc-zoom-group">
                        <button type="button" class="cc-stage-btn cc-stage-btn--square" id="cc-builder-zoom-out-btn" title="Zoom out">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        </button>
                        <button type="button" class="cc-stage-btn cc-stage-btn--zoom" id="cc-builder-zoom-reset-btn" title="Reset zoom">100%</button>
                        <button type="button" class="cc-stage-btn cc-stage-btn--square" id="cc-builder-zoom-in-btn" title="Zoom in">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        </button>
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
    </div>
</form>

<!-- ═══════════════════════════════════════════════════════════ Message Builder Modal -->
<div id="cc-msg-builder-overlay" class="cc-mb-overlay" aria-hidden="true">
    <div class="cc-mb-modal" role="dialog" aria-label="Message Builder">

        <!-- Header -->
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

        <!-- Body -->
        <div class="cc-mb-body">

            <!-- LEFT: Editor -->
            <div class="cc-mb-left">

                <!-- Response Type -->
                <div class="cc-mb-section">
                    <div class="cc-mb-section-head">
                        <span class="cc-mb-section-label">
                            Response type
                            <span class="cc-mb-section-hint">Where your bot should send this message.</span>
                        </span>
                    </div>
                    <select class="cc-mb-select" id="cc-mb-response-type">
                        <optgroup label="Reply">
                            <option value="reply">Reply to the command</option>
                            <option value="reply_message">Reply to a specific message</option>
                            <option value="channel">Send the message to the channel the command was used in</option>
                        </optgroup>
                        <optgroup label="Specific">
                            <option value="specific_channel">Send the message to a specific channel</option>
                            <option value="channel_option">Send the message to a channel option</option>
                        </optgroup>
                        <optgroup label="Direct Message">
                            <option value="dm_user">Direct message the user who used the command</option>
                            <option value="dm_user_option">Direct message a user option</option>
                            <option value="dm_specific_user">Direct message a specific user</option>
                        </optgroup>
                        <optgroup label="Edit">
                            <option value="edit_action">Edit a message sent by another action</option>
                        </optgroup>
                    </select>

                    <!-- Conditional: channel ID -->
                    <div class="cc-mb-cond-field" id="cc-mb-cond-specific-channel" style="display:none">
                        <label class="cc-mb-field-label">Channel ID</label>
                        <input type="text" class="cc-mb-input" id="cc-mb-target-channel-id" placeholder="Channel ID…" maxlength="32">
                    </div>

                    <!-- Conditional: channel option name -->
                    <div class="cc-mb-cond-field" id="cc-mb-cond-channel-option" style="display:none">
                        <label class="cc-mb-field-label">Option Name</label>
                        <input type="text" class="cc-mb-input" id="cc-mb-target-option-name" placeholder="Name der Channel-Option…" maxlength="32">
                    </div>

                    <!-- Conditional: DM user option name -->
                    <div class="cc-mb-cond-field" id="cc-mb-cond-dm-user-option" style="display:none">
                        <label class="cc-mb-field-label">Option Name</label>
                        <input type="text" class="cc-mb-input" id="cc-mb-target-dm-option-name" placeholder="Name der User-Option…" maxlength="32">
                    </div>

                    <!-- Conditional: DM specific user ID -->
                    <div class="cc-mb-cond-field" id="cc-mb-cond-dm-specific-user" style="display:none">
                        <label class="cc-mb-field-label">User ID</label>
                        <input type="text" class="cc-mb-input" id="cc-mb-target-user-id" placeholder="User ID…" maxlength="32">
                    </div>

                    <!-- Conditional: edit action – target variable -->
                    <div class="cc-mb-cond-field" id="cc-mb-cond-edit-action" style="display:none">
                        <label class="cc-mb-field-label">Edit Target Variable</label>
                        <input type="text" class="cc-mb-input" id="cc-mb-edit-target-var" placeholder="z.B. my_message" maxlength="64" pattern="[a-z0-9_]+">
                        <span class="cc-mb-section-hint">Variable name of the message you want to edit.</span>
                    </div>
                </div>

                <div class="cc-mb-divider"></div>

                <!-- Message Content -->
                <div class="cc-mb-section">
                    <div class="cc-mb-section-head">
                        <span class="cc-mb-section-label">Message Content <span class="cc-mb-counter" id="cc-mb-content-count">0</span>/2000</span>
                        <div class="cc-mb-section-tools">
                            <button type="button" class="cc-mb-tool-btn" id="cc-mb-variables-btn" title="Variable einfügen">{x}</button>
                        </div>
                    </div>
                    <textarea class="cc-mb-textarea" id="cc-mb-content" maxlength="2000" placeholder="Nachrichteninhalt…" rows="5"></textarea>
                </div>

                <div class="cc-mb-divider"></div>

                <!-- Embeds -->
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

            <!-- RIGHT: Discord Preview -->
            <div class="cc-mb-right">
                <div class="cc-mb-preview-wrap">
                    <div class="cc-mb-preview" id="cc-mb-preview">
                        <div class="cc-mb-discord-msg" id="cc-mb-discord-msg">
                            <div class="cc-mb-discord-avatar" id="cc-mb-bot-avatar">
                                <img src="" alt="Bot" id="cc-mb-bot-avatar-img">
                                <div class="cc-mb-discord-avatar-fallback" id="cc-mb-bot-avatar-fallback">B</div>
                            </div>
                            <div class="cc-mb-discord-content">
                                <div class="cc-mb-discord-name-row">
                                    <span class="cc-mb-discord-name" id="cc-mb-bot-name">Bot</span>
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

<!-- Embed item template (hidden) -->
<template id="cc-mb-embed-tpl">
    <div class="cc-mb-embed-item" data-embed-idx="">
        <div class="cc-mb-embed-head">
            <div class="cc-mb-embed-color-wrap">
                <input type="color" class="cc-mb-color-picker" value="#5865F2" title="Embed Farbe">
            </div>
            <span class="cc-mb-embed-title-label">Embed <span class="cc-mb-embed-num">1</span></span>
            <button type="button" class="cc-mb-embed-del-btn" title="Embed löschen">✕</button>
        </div>
        <div class="cc-mb-embed-fields">
            <div class="cc-mb-field-row">
                <label>Author Name</label>
                <input type="text" class="cc-mb-input" data-field="author_name" placeholder="Autor…" maxlength="256">
            </div>
            <div class="cc-mb-field-row">
                <label>Title</label>
                <input type="text" class="cc-mb-input" data-field="title" placeholder="Titel…" maxlength="256">
            </div>
            <div class="cc-mb-field-row">
                <label>URL</label>
                <input type="text" class="cc-mb-input" data-field="url" placeholder="https://…">
            </div>
            <div class="cc-mb-field-row">
                <label>Description</label>
                <textarea class="cc-mb-input cc-mb-input--ta" data-field="description" placeholder="Beschreibung…" maxlength="4096" rows="3"></textarea>
            </div>
            <div class="cc-mb-field-row">
                <label>Thumbnail URL</label>
                <input type="text" class="cc-mb-input" data-field="thumbnail_url" placeholder="https://…">
            </div>
            <div class="cc-mb-field-row">
                <label>Image URL</label>
                <input type="text" class="cc-mb-input" data-field="image_url" placeholder="https://…">
            </div>
            <div class="cc-mb-field-row">
                <label>Footer Text</label>
                <input type="text" class="cc-mb-input" data-field="footer_text" placeholder="Footer…" maxlength="2048">
            </div>
            <div class="cc-mb-field-row cc-mb-field-row--inline">
                <label class="cc-mb-switch-label">
                    <span class="cc-mb-switch">
                        <input type="checkbox" data-field="timestamp">
                        <span class="cc-mb-switch-slider"></span>
                    </span>
                    Timestamp anzeigen
                </label>
            </div>

            <!-- Embed Fields -->
            <div class="cc-mb-subfields-head">
                <span>Fields <span class="cc-mb-fields-count">0</span>/25</span>
                <button type="button" class="cc-mb-add-field-btn">+ Field</button>
            </div>
            <div class="cc-mb-subfields-list"></div>
        </div>
    </div>
</template>

<!-- Embed field row template (hidden) -->
<template id="cc-mb-field-tpl">
    <div class="cc-mb-subfield-item">
        <div class="cc-mb-subfield-head">
            <span>Field</span>
            <div class="cc-mb-subfield-right">
                <label class="cc-mb-switch-label cc-mb-switch-label--sm">
                    <span class="cc-mb-switch cc-mb-switch--sm">
                        <input type="checkbox" data-subfield="inline">
                        <span class="cc-mb-switch-slider"></span>
                    </span>
                    Inline
                </label>
                <button type="button" class="cc-mb-field-del-btn">✕</button>
            </div>
        </div>
        <div class="cc-mb-field-row">
            <label>Name</label>
            <input type="text" class="cc-mb-input" data-subfield="name" placeholder="Name…" maxlength="256">
        </div>
        <div class="cc-mb-field-row">
            <label>Value</label>
            <textarea class="cc-mb-input cc-mb-input--ta" data-subfield="value" placeholder="Wert…" maxlength="1024" rows="2"></textarea>
        </div>
    </div>
</template>

<script>
    window.BuilderDefinitions = <?= json_encode(custom_command_builder_definitions()); ?>;

    window.BuilderPalette = <?= json_encode(custom_command_builder_palette()); ?>;
    window.CcBotMeta = {
        name:    <?= json_encode($botPreviewName) ?>,
        userId:  <?= json_encode($botDiscordUserId) ?>,
        botId:   <?= json_encode($currentBotId) ?>,
    };
</script>
<script src="/assets/js/custom-command-builder.js"></script>
</body>
</html>