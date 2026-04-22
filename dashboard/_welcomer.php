<?php
declare(strict_types=1);
/** @var int|null $currentBotId */
/** @var int $userId */

if (!isset($currentBotId) || !is_int($currentBotId) || $currentBotId <= 0) {
    echo '<p style="color:#fca5a5;padding:24px">Kein Bot ausgewählt.</p>';
    return;
}

$botId = $currentBotId;

require_once dirname(__DIR__) . '/functions/module_toggle.php';

/* ── Ensure tables exist ── */
try {
    $pdo = bh_get_pdo();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_welcomer_settings (
            bot_id              INT UNSIGNED  NOT NULL,
            welcome_card_tpl    VARCHAR(64)   NOT NULL DEFAULT '',
            wc_channel          VARCHAR(20)   NOT NULL DEFAULT '',
            wc_bg               VARCHAR(64)   NOT NULL DEFAULT 'default',
            wc_bg_color         VARCHAR(64)   NOT NULL DEFAULT 'brilliant_red',
            wc_title_color      VARCHAR(7)    NOT NULL DEFAULT '#ffffff',
            wc_desc_color       VARCHAR(7)    NOT NULL DEFAULT '#ffffff',
            wc_avatar_color     VARCHAR(7)    NOT NULL DEFAULT '#ffffff',
            wc_title            VARCHAR(255)  NOT NULL DEFAULT '{user_name}',
            wc_desc             VARCHAR(255)  NOT NULL DEFAULT 'Welcome to {server}',
            wc_reactions        VARCHAR(255)  NOT NULL DEFAULT '',
            msg_join            TINYINT(1)    NOT NULL DEFAULT 0,
            msg_join_channel    VARCHAR(20)   NOT NULL DEFAULT '',
            msg_join_content    TEXT          NULL,
            dm_join             TINYINT(1)    NOT NULL DEFAULT 0,
            dm_join_content     TEXT          NULL,
            role_join           TINYINT(1)    NOT NULL DEFAULT 0,
            role_join_roles     JSON          NULL,
            msg_leave           TINYINT(1)    NOT NULL DEFAULT 0,
            msg_leave_channel   VARCHAR(20)   NOT NULL DEFAULT '',
            msg_leave_content   TEXT          NULL,
            msg_kick            TINYINT(1)    NOT NULL DEFAULT 0,
            msg_kick_channel    VARCHAR(20)   NOT NULL DEFAULT '',
            msg_kick_content    TEXT          NULL,
            msg_ban             TINYINT(1)    NOT NULL DEFAULT 0,
            msg_ban_channel     VARCHAR(20)   NOT NULL DEFAULT '',
            msg_ban_content     TEXT          NULL,
            event_joins         TINYINT(1)    NOT NULL DEFAULT 1,
            event_bans          TINYINT(1)    NOT NULL DEFAULT 1,
            event_leaves_kicks  TINYINT(1)    NOT NULL DEFAULT 1,
            event_membership    TINYINT(1)    NOT NULL DEFAULT 1,
            updated_at          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (bot_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // Add card-config columns to existing tables (safe if already present)
    $newCols = [
        "wc_channel VARCHAR(20) NOT NULL DEFAULT ''",
        "wc_bg VARCHAR(64) NOT NULL DEFAULT 'default'",
        "wc_bg_color VARCHAR(64) NOT NULL DEFAULT 'brilliant_red'",
        "wc_title_color VARCHAR(7) NOT NULL DEFAULT '#ffffff'",
        "wc_desc_color VARCHAR(7) NOT NULL DEFAULT '#ffffff'",
        "wc_avatar_color VARCHAR(7) NOT NULL DEFAULT '#ffffff'",
        "wc_title VARCHAR(255) NOT NULL DEFAULT '{user_name}'",
        "wc_desc VARCHAR(255) NOT NULL DEFAULT 'Welcome to {server}'",
        "wc_reactions VARCHAR(255) NOT NULL DEFAULT ''",
    ];
    foreach ($newCols as $colDef) {
        try {
            // MySQL does not support IF NOT EXISTS for ADD COLUMN; catch duplicate-column error (1060)
            $pdo->exec("ALTER TABLE bot_welcomer_settings ADD COLUMN {$colDef}");
        } catch (Throwable) {}
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_welcomer_commands (
            id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            bot_id      INT UNSIGNED  NOT NULL,
            command_key VARCHAR(64)   NOT NULL,
            description VARCHAR(255)  NOT NULL DEFAULT '',
            created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_wlcm_cmd_bot (bot_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_welcomer_module_events (
            id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            bot_id      INT UNSIGNED  NOT NULL,
            event_key   VARCHAR(64)   NOT NULL,
            description VARCHAR(255)  NOT NULL DEFAULT '',
            created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_wlcm_evt_bot (bot_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Throwable $e) {
    echo '<p style="color:#fca5a5;padding:24px">DB Fehler: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    return;
}

/* ── Module toggle AJAX ── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    bh_mod_handle_ajax($pdo, $botId);
}

/* ── AJAX: toggle_feature ── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['_ajax']) && $_POST['_ajax'] === '1') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $field = (string)($_POST['field'] ?? '');

    // String field: welcome_card_tpl
    if ($field === 'welcome_card_tpl') {
        $allowed_tpl = ['', 'channel', 'dm', 'both'];
        $tpl = (string)($_POST['value'] ?? '');
        if (!in_array($tpl, $allowed_tpl, true)) {
            echo json_encode(['ok' => false, 'error' => 'invalid_value']);
            exit;
        }
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO bot_welcomer_settings (bot_id, welcome_card_tpl) VALUES (:b, :v)
                 ON DUPLICATE KEY UPDATE welcome_card_tpl = VALUES(welcome_card_tpl)"
            );
            $stmt->execute([':b' => $botId, ':v' => $tpl]);
            echo json_encode(['ok' => true]);
        } catch (Throwable) {
            echo json_encode(['ok' => false, 'error' => 'db_error']);
        }
        exit;
    }

    // Save card config fields
    if ($field === 'save_card_config') {
        $wc_channel      = trim((string)($_POST['wc_channel']      ?? ''));
        $wc_bg           = trim((string)($_POST['wc_bg']           ?? 'default'));
        $wc_bg_color     = trim((string)($_POST['wc_bg_color']     ?? 'brilliant_red'));
        $wc_title_color  = trim((string)($_POST['wc_title_color']  ?? '#ffffff'));
        $wc_desc_color   = trim((string)($_POST['wc_desc_color']   ?? '#ffffff'));
        $wc_avatar_color = trim((string)($_POST['wc_avatar_color'] ?? '#ffffff'));
        $wc_title        = trim((string)($_POST['wc_title']        ?? '{user_name}'));
        $wc_desc         = trim((string)($_POST['wc_desc']         ?? 'Welcome to {server}'));
        $wc_reactions    = trim((string)($_POST['wc_reactions']    ?? ''));

        $hex = '/^#[0-9a-fA-F]{6}$/';
        if (!preg_match($hex, $wc_title_color))  $wc_title_color  = '#ffffff';
        if (!preg_match($hex, $wc_desc_color))   $wc_desc_color   = '#ffffff';
        if (!preg_match($hex, $wc_avatar_color)) $wc_avatar_color = '#ffffff';

        $allowedBg  = ['default', 'solid', 'gradient'];
        $allowedBgC = ['brilliant_red','ocean_blue','forest_green','purple_haze','sunset_orange','midnight_dark','arctic_white'];
        if (!in_array($wc_bg,       $allowedBg,  true)) $wc_bg       = 'default';
        if (!in_array($wc_bg_color, $allowedBgC, true)) $wc_bg_color = 'brilliant_red';

        try {
            $pdo->prepare(
                "INSERT INTO bot_welcomer_settings
                    (bot_id, wc_channel, wc_bg, wc_bg_color, wc_title_color, wc_desc_color, wc_avatar_color, wc_title, wc_desc, wc_reactions)
                 VALUES (:b, :ch, :bg, :bgc, :tc, :dc, :ac, :t, :d, :r)
                 ON DUPLICATE KEY UPDATE
                    wc_channel = VALUES(wc_channel), wc_bg = VALUES(wc_bg), wc_bg_color = VALUES(wc_bg_color),
                    wc_title_color = VALUES(wc_title_color), wc_desc_color = VALUES(wc_desc_color),
                    wc_avatar_color = VALUES(wc_avatar_color),
                    wc_title = VALUES(wc_title), wc_desc = VALUES(wc_desc), wc_reactions = VALUES(wc_reactions)"
            )->execute([
                ':b'   => $botId, ':ch' => $wc_channel, ':bg' => $wc_bg,
                ':bgc' => $wc_bg_color, ':tc' => $wc_title_color,
                ':dc'  => $wc_desc_color, ':ac' => $wc_avatar_color,
                ':t'   => $wc_title, ':d' => $wc_desc, ':r' => $wc_reactions,
            ]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    $val = (int)($_POST['value'] ?? 0);
    $val = $val ? 1 : 0;

    $allowed = [
        'msg_join', 'dm_join', 'role_join',
        'msg_leave', 'msg_kick', 'msg_ban',
        'event_joins', 'event_bans', 'event_leaves_kicks', 'event_membership',
    ];

    // SECURITY: Use explicit mapping for dynamic field names
    $fieldMap = array_combine($allowed, $allowed);

    if (!isset($fieldMap[$field])) {
        echo json_encode(['ok' => false, 'error' => 'invalid_field']);
        exit;
    }

    $safeField = $fieldMap[$field];

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO bot_welcomer_settings (bot_id, {$safeField}) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE {$safeField} = ?"
        );
        $stmt->execute([$botId, $val, $val]);
        echo json_encode(['ok' => true]);
    } catch (Throwable) {
        echo json_encode(['ok' => false, 'error' => 'db_error']);
    }
    exit;
}

/* ── Form POST ── */
$flashOk  = null;
$flashErr = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    // save_card_config is now handled via AJAX in the _ajax block above

    if ($action === 'save_feature_config') {
        $feat     = (string)($_POST['feat'] ?? '');
        $allowed  = ['msg_join', 'dm_join', 'role_join', 'msg_leave', 'msg_kick', 'msg_ban'];
        if (!in_array($feat, $allowed, true)) {
            $flashErr = 'Ungültiges Feature.';
        } else {
            try {
                $sets = [];
                $params = [':b' => $botId];

                if ($feat === 'dm_join') {
                    $content = trim((string)($_POST['dm_join_content'] ?? 'Welcome, {user_name}!'));
                    $pdo->prepare(
                        "INSERT INTO bot_welcomer_settings (bot_id, dm_join_content) VALUES (:b, :c)
                         ON DUPLICATE KEY UPDATE dm_join_content = :c"
                    )->execute([':b' => $botId, ':c' => $content]);
                } elseif ($feat === 'role_join') {
                    $raw   = trim((string)($_POST['role_join_roles'] ?? ''));
                    $roles = array_values(array_filter(array_map('trim', explode(',', $raw))));
                    $json  = json_encode($roles);
                    $pdo->prepare(
                        "INSERT INTO bot_welcomer_settings (bot_id, role_join_roles) VALUES (:b, :r)
                         ON DUPLICATE KEY UPDATE role_join_roles = :r"
                    )->execute([':b' => $botId, ':r' => $json]);
                } else {
                    // channel + content features
                    $chCol  = $feat . '_channel';
                    $cntCol = $feat . '_content';
                    $ch     = trim((string)($_POST[$chCol]  ?? ''));
                    $cnt    = trim((string)($_POST[$cntCol] ?? ''));
                    $pdo->prepare(
                        "INSERT INTO bot_welcomer_settings (bot_id, {$chCol}, {$cntCol}) VALUES (:b, :ch, :cnt)
                         ON DUPLICATE KEY UPDATE {$chCol} = :ch, {$cntCol} = :cnt"
                    )->execute([':b' => $botId, ':ch' => $ch, ':cnt' => $cnt]);
                }

                $flashOk = 'Gespeichert.';
            } catch (Throwable $e) {
                $flashErr = 'Fehler: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'add_command') {
        $key  = trim((string)($_POST['command_key'] ?? ''));
        $desc = trim((string)($_POST['command_desc'] ?? ''));
        if ($key === '') {
            $flashErr = 'Command key darf nicht leer sein.';
        } else {
            try {
                $s = $pdo->prepare('INSERT INTO bot_welcomer_commands (bot_id, command_key, description) VALUES (:b, :k, :d)');
                $s->execute([':b' => $botId, ':k' => $key, ':d' => $desc]);
                $flashOk = 'Command hinzugefügt.';
            } catch (Throwable) {
                $flashErr = 'Fehler beim Speichern.';
            }
        }
    }

    if ($action === 'delete_command') {
        $id = (int)($_POST['entry_id'] ?? 0);
        if ($id > 0) {
            $s = $pdo->prepare('DELETE FROM bot_welcomer_commands WHERE id = :id AND bot_id = :b LIMIT 1');
            $s->execute([':id' => $id, ':b' => $botId]);
            $flashOk = 'Command gelöscht.';
        }
    }

    if ($action === 'add_event') {
        $key  = trim((string)($_POST['event_key'] ?? ''));
        $desc = trim((string)($_POST['event_desc'] ?? ''));
        if ($key === '') {
            $flashErr = 'Event key darf nicht leer sein.';
        } else {
            try {
                $s = $pdo->prepare('INSERT INTO bot_welcomer_module_events (bot_id, event_key, description) VALUES (:b, :k, :d)');
                $s->execute([':b' => $botId, ':k' => $key, ':d' => $desc]);
                $flashOk = 'Event hinzugefügt.';
            } catch (Throwable) {
                $flashErr = 'Fehler beim Speichern.';
            }
        }
    }

    if ($action === 'delete_event') {
        $id = (int)($_POST['entry_id'] ?? 0);
        if ($id > 0) {
            $s = $pdo->prepare('DELETE FROM bot_welcomer_module_events WHERE id = :id AND bot_id = :b LIMIT 1');
            $s->execute([':id' => $id, ':b' => $botId]);
            $flashOk = 'Event gelöscht.';
        }
    }
}

/* ── Load settings ── */
$s = $pdo->prepare('SELECT * FROM bot_welcomer_settings WHERE bot_id = :b LIMIT 1');
$s->execute([':b' => $botId]);
$settings = $s->fetch();
if (!is_array($settings)) {
    $settings = [];
}

function wlcm_bool(array $s, string $key, int $default = 0): bool {
    return isset($s[$key]) ? (bool)(int)$s[$key] : (bool)$default;
}
function wlcm_str(array $s, string $key, string $default = ''): string {
    return isset($s[$key]) ? (string)$s[$key] : $default;
}

/* ── Load commands + events lists ── */
$cmdStmt = $pdo->prepare('SELECT * FROM bot_welcomer_commands WHERE bot_id = :b ORDER BY id DESC');
$cmdStmt->execute([':b' => $botId]);
$commands = $cmdStmt->fetchAll();

$evtStmt = $pdo->prepare('SELECT * FROM bot_welcomer_module_events WHERE bot_id = :b ORDER BY id DESC');
$evtStmt->execute([':b' => $botId]);
$moduleEvents = $evtStmt->fetchAll();

/* ── Current values ── */
$cardTpl       = wlcm_str($settings, 'welcome_card_tpl', '');
$wcChannel     = wlcm_str($settings, 'wc_channel',      '');
$wcBg          = wlcm_str($settings, 'wc_bg',           'default');
$wcBgColor     = wlcm_str($settings, 'wc_bg_color',     'brilliant_red');
$wcTitleColor  = wlcm_str($settings, 'wc_title_color',  '#ffffff');
$wcDescColor   = wlcm_str($settings, 'wc_desc_color',   '#ffffff');
$wcAvatarColor = wlcm_str($settings, 'wc_avatar_color', '#ffffff');
$wcTitle       = wlcm_str($settings, 'wc_title',        '{user_name}');
$wcDesc        = wlcm_str($settings, 'wc_desc',         'Welcome to {server}');
$wcReactions   = wlcm_str($settings, 'wc_reactions',    '');

$showConfig  = $cardTpl !== '';
$showChannel = in_array($cardTpl, ['channel', 'both'], true);

/* ── Page URL for form actions ── */
$pageUrl = '/dashboard?view=welcomer&bot_id=' . $botId;

/* ── Feature rows (excluding welcome card) ── */
$features = [
    [
        'field' => 'msg_join',
        'title' => 'Message on Join',
        'desc'  => 'Automatically send a message to a channel once a user joins the server.',
        'cfg'   => 'channel+content',
        'vars'  => '{user_name}, {user}, {user_id}, {server}, {member_count}',
        'ch_default' => 'Welcome, {user_name}!',
    ],
    [
        'field' => 'dm_join',
        'title' => 'Direct Message on Join',
        'desc'  => 'Automatically send a direct message to users who join the server.',
        'cfg'   => 'content',
        'vars'  => '{user_name}, {user}, {server}',
        'ch_default' => 'Welcome to {server}, {user_name}!',
    ],
    [
        'field' => 'role_join',
        'title' => 'Add Role on Join',
        'desc'  => 'Automatically add one or more roles to users who join the server.',
        'cfg'   => 'roles',
    ],
    [
        'field' => 'msg_leave',
        'title' => 'Message on Leave',
        'desc'  => 'Automatically send a message to a channel once a user leaves the server.',
        'cfg'   => 'channel+content',
        'vars'  => '{user_name}, {user}, {user_id}, {server}',
        'ch_default' => 'Goodbye, {user_name}!',
    ],
    [
        'field' => 'msg_kick',
        'title' => 'Message on Kick',
        'desc'  => 'Automatically send a message to a channel once a user gets kicked from the server.',
        'cfg'   => 'channel+content',
        'vars'  => '{user_name}, {user}, {user_id}, {server}',
        'ch_default' => '{user_name} was kicked.',
    ],
    [
        'field' => 'msg_ban',
        'title' => 'Message on Ban',
        'desc'  => 'Automatically send a message to a channel once a user gets banned from this server.',
        'cfg'   => 'channel+content',
        'vars'  => '{user_name}, {user}, {user_id}, {server}',
        'ch_default' => '{user_name} was banned.',
    ],
];

$eventHandlers = [
    ['field' => 'event_joins',        'title' => 'Joins Handler',                           'desc' => 'When a new user joins the server.'],
    ['field' => 'event_bans',         'title' => 'Bans Handler',                            'desc' => 'When a user is banned from the server.'],
    ['field' => 'event_leaves_kicks', 'title' => 'Leaves and Kicks Handler',                'desc' => 'When a user leaves or is kicked from the server.'],
    ['field' => 'event_membership',   'title' => 'Membership Screening Acceptance Handler', 'desc' => 'When a guild member is updated.'],
];

$bgOptions = [
    'default'  => 'Default Background Image',
    'solid'    => 'Solid Color',
    'gradient' => 'Gradient',
];
$bgColorOptions = [
    'brilliant_red'   => 'Brilliant Red',
    'ocean_blue'      => 'Ocean Blue',
    'forest_green'    => 'Forest Green',
    'purple_haze'     => 'Purple Haze',
    'sunset_orange'   => 'Sunset Orange',
    'midnight_dark'   => 'Midnight Dark',
    'arctic_white'    => 'Arctic White',
];
$modEnabled = bh_mod_is_enabled($pdo, $botId, 'module:welcomer');
?>
<?= bh_mod_render($modEnabled, $botId, 'module:welcomer', 'Welcomer', 'Willkommens- und Abschiedsnachrichten für diesen Bot ein- oder ausschalten.') ?>
<div id="bh-mod-body">
<div class="bh-wlcm-page">

    <div class="bh-wlcm-head">
        <div class="bh-wlcm-kicker">Bot Feature</div>
        <h1 class="bh-wlcm-title">Welcomer</h1>
        <p class="bh-wlcm-subtitle">Willkommens- und Abschiedsnachrichten für deinen Server.</p>
    </div>

    <?php if ($flashOk !== null): ?>
        <div class="bh-alert bh-alert--ok"><?= htmlspecialchars($flashOk, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($flashErr !== null): ?>
        <div class="bh-alert bh-alert--err"><?= htmlspecialchars($flashErr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <!-- ── Welcome Card (separate card) ── -->
    <div class="bh-card">
        <div class="bh-card-hdr">
            <div>
                <div class="bh-wlcm-card__sec-kicker">WELCOME</div>
                <div class="bh-card-title" style="margin:0">Welcome Card</div>
            </div>
        </div>
        <!-- Dropdown field -->
        <div class="bh-wlcm-cfg-field">
            <label class="bh-wlcm-cfg-label">Willkommensbild senden</label>
            <div class="bh-wlcm-cfg-sublabel">Automatisch ein Willkommensbild versenden wenn ein User dem Server beitritt.</div>
            <select
                class="bh-select"
                data-field="welcome_card_tpl"
                data-bot-id="<?= $botId ?>"
                onchange="bhWlcmToggle(this, 'select-str'); bhWlcmCardUpdate(this.value)"
            >
                <option value=""        <?= $cardTpl === ''        ? 'selected' : '' ?>>Deaktiviert</option>
                <option value="channel" <?= $cardTpl === 'channel' ? 'selected' : '' ?>>In einen Channel senden</option>
                <option value="dm"      <?= $cardTpl === 'dm'      ? 'selected' : '' ?>>Als Direktnachricht senden</option>
                <option value="both"    <?= $cardTpl === 'both'    ? 'selected' : '' ?>>Channel und Direktnachricht</option>
            </select>
        </div>

        <!-- Config panel (shown when not disabled) -->
        <div id="bh-wlcm-card-config" class="bh-wlcm-cfg" style="display:<?= $showConfig ? 'block' : 'none' ?>">
            <div id="bh-wlcm-card-flash" class="bh-alert" style="display:none"></div>
            <form id="bh-wlcm-card-form">

                <!-- Channel section (only for 'channel' / 'both') -->
                <div id="bh-wlcm-cfg-ch-sec" style="display:<?= $showChannel ? 'block' : 'none' ?>">
                    <div class="bh-wlcm-cfg-field">
                        <div class="bh-wlcm-cfg-label">Kanal</div>
                        <div class="bh-wlcm-cfg-sublabel">Der Kanal, in den das Willkommensbild gesendet wird.</div>
                        <input type="hidden" name="wc_channel" id="wlcm_wc_channel_val"
                               value="<?= htmlspecialchars($wcChannel, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="bh-wlcm-picker-row" id="wlcm_wc_channel_box">
                            <button type="button" class="bh-wlcm-picker-add" id="wlcm_wc_channel_btn">+</button>
                        </div>
                    </div>
                </div>

                <!-- Design fields (always visible when config open) -->
                <div class="bh-wlcm-cfg-field">
                    <div class="bh-wlcm-cfg-label">Hintergrund</div>
                    <div class="bh-wlcm-cfg-sublabel">Hintergrundstil für das Willkommensbild wählen.</div>
                    <select name="wc_bg" class="bh-select">
                        <?php foreach ($bgOptions as $val => $label): ?>
                            <option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>" <?= $wcBg === $val ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="bh-wlcm-cfg-field">
                    <div class="bh-wlcm-cfg-label">Hintergrundfarbe</div>
                    <div class="bh-wlcm-cfg-sublabel">Eine der vordefinierten Hintergrundfarben wählen.</div>
                    <select name="wc_bg_color" class="bh-select">
                        <?php foreach ($bgColorOptions as $val => $label): ?>
                            <option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>" <?= $wcBgColor === $val ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="bh-wlcm-cfg-field">
                    <div class="bh-wlcm-cfg-label">Titelfarbe</div>
                    <div class="bh-wlcm-cfg-sublabel">Farbe des Titeltexts auf dem Willkommensbild.</div>
                    <div class="bh-wlcm-color-row">
                        <input type="color" name="wc_title_color_picker"
                               value="<?= htmlspecialchars($wcTitleColor, ENT_QUOTES, 'UTF-8') ?>"
                               oninput="bhSyncColor(this,'wc_title_color')">
                        <input type="text"  name="wc_title_color"
                               class="bh-embed-input" style="width:110px;font-family:monospace"
                               value="<?= htmlspecialchars($wcTitleColor, ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="#ffffff"
                               oninput="bhSyncColorText(this,'wc_title_color_picker')">
                    </div>
                </div>

                <div class="bh-wlcm-cfg-field">
                    <div class="bh-wlcm-cfg-label">Beschreibungsfarbe</div>
                    <div class="bh-wlcm-cfg-sublabel">Farbe des Beschreibungstexts auf dem Willkommensbild.</div>
                    <div class="bh-wlcm-color-row">
                        <input type="color" name="wc_desc_color_picker"
                               value="<?= htmlspecialchars($wcDescColor, ENT_QUOTES, 'UTF-8') ?>"
                               oninput="bhSyncColor(this,'wc_desc_color')">
                        <input type="text"  name="wc_desc_color"
                               class="bh-embed-input" style="width:110px;font-family:monospace"
                               value="<?= htmlspecialchars($wcDescColor, ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="#ffffff"
                               oninput="bhSyncColorText(this,'wc_desc_color_picker')">
                    </div>
                </div>

                <div class="bh-wlcm-cfg-field">
                    <div class="bh-wlcm-cfg-label">Avatar-Rahmenfarbe</div>
                    <div class="bh-wlcm-cfg-sublabel">Farbe des Rahmens um das Profilbild.</div>
                    <div class="bh-wlcm-color-row">
                        <input type="color" name="wc_avatar_color_picker"
                               value="<?= htmlspecialchars($wcAvatarColor, ENT_QUOTES, 'UTF-8') ?>"
                               oninput="bhSyncColor(this,'wc_avatar_color')">
                        <input type="text"  name="wc_avatar_color"
                               class="bh-embed-input" style="width:110px;font-family:monospace"
                               value="<?= htmlspecialchars($wcAvatarColor, ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="#ffffff"
                               oninput="bhSyncColorText(this,'wc_avatar_color_picker')">
                    </div>
                </div>

                <div class="bh-wlcm-cfg-field">
                    <div class="bh-wlcm-cfg-label">Banner-Titel</div>
                    <div class="bh-wlcm-cfg-sublabel">Erste Textzeile auf dem Banner. Alle Variablen können verwendet werden.</div>
                    <div class="bh-wlcm-cfg-hint">z.B. <code>{user_name}</code></div>
                    <input type="text" name="wc_title" class="bh-input"
                           placeholder="{user_name}"
                           value="<?= htmlspecialchars($wcTitle, ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="bh-wlcm-cfg-field">
                    <div class="bh-wlcm-cfg-label">Banner-Beschreibung</div>
                    <div class="bh-wlcm-cfg-sublabel">Zweite Textzeile auf dem Banner. Alle Variablen können verwendet werden.</div>
                    <div class="bh-wlcm-cfg-hint">z.B. <code>Welcome to {server}</code></div>
                    <input type="text" name="wc_desc" class="bh-input"
                           placeholder="Welcome to {server}"
                           value="<?= htmlspecialchars($wcDesc, ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <!-- Channel Reactions (only for 'channel' / 'both') -->
                <div id="bh-wlcm-cfg-react-sec" style="display:<?= $showChannel ? 'block' : 'none' ?>">
                    <div class="bh-wlcm-cfg-field">
                        <div class="bh-wlcm-cfg-label">Kanal-Reaktionen</div>
                        <div class="bh-wlcm-cfg-sublabel">Bis zu 5 Emoji, die automatisch unter die Willkommensnachricht reagiert werden.</div>
                        <input type="text" name="wc_reactions" class="bh-input"
                               placeholder="👋, 🎉, ❤️"
                               value="<?= htmlspecialchars($wcReactions, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>

                <div class="bh-wlcm-cfg-save-row">
                    <button type="button" class="bh-wlcm-btn" onclick="bhWlcmSaveCard()">Speichern</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Other feature toggles ── -->
    <div class="bh-card">
        <?php foreach ($features as $feat):
            $checked   = wlcm_bool($settings, $feat['field']);
            $cfgPanelId = 'bh-wlcm-cfg-' . $feat['field'];
            $cfgType    = $feat['cfg'] ?? '';
        ?>
        <div class="bh-wlcm-feature">
            <div class="bh-wlcm-feature__left">
                <div class="bh-wlcm-feature__kicker bh-wlcm-feature__kicker--welcome">WELCOME</div>
                <div class="bh-wlcm-feature__title"><?= htmlspecialchars($feat['title'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="bh-wlcm-feature__desc"><?= htmlspecialchars($feat['desc'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <label class="bh-toggle">
                <input
                    class="bh-toggle-input"
                    type="checkbox"
                    data-field="<?= htmlspecialchars($feat['field'], ENT_QUOTES, 'UTF-8') ?>"
                    data-bot-id="<?= $botId ?>"
                    data-cfg-panel="<?= $cfgPanelId ?>"
                    <?= $checked ? 'checked' : '' ?>
                    onchange="bhWlcmToggle(this, 'checkbox'); bhWlcmFeatCfgToggle(this)"
                >
                <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
            </label>
        </div>

        <!-- Config panel -->
        <div id="<?= $cfgPanelId ?>" class="bh-wlcm-cfg" style="display:<?= $checked ? 'block' : 'none' ?>">
            <form method="post" action="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="save_feature_config">
                <input type="hidden" name="feat"   value="<?= htmlspecialchars($feat['field'], ENT_QUOTES, 'UTF-8') ?>">

                <?php if ($cfgType === 'channel+content'): ?>
                    <div class="bh-wlcm-cfg-field">
                        <div class="bh-wlcm-cfg-label">Kanal</div>
                        <div class="bh-wlcm-cfg-sublabel">Der Kanal, in den die Nachricht gesendet wird.</div>
                        <input type="hidden" name="<?= $feat['field'] ?>_channel"
                               id="wlcm_<?= $feat['field'] ?>_ch_val"
                               value="<?= htmlspecialchars(wlcm_str($settings, $feat['field'] . '_channel'), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="bh-wlcm-picker-row" id="wlcm_<?= $feat['field'] ?>_ch_box">
                            <button type="button" class="bh-wlcm-picker-add"
                                    id="wlcm_<?= $feat['field'] ?>_ch_btn">+</button>
                        </div>
                    </div>
                    <div class="bh-wlcm-cfg-field">
                        <div class="bh-wlcm-cfg-label">Nachrichteninhalt</div>
                        <?php if (!empty($feat['vars'])): ?>
                            <div class="bh-wlcm-cfg-hint">Variablen: <code><?= htmlspecialchars($feat['vars'], ENT_QUOTES, 'UTF-8') ?></code></div>
                        <?php endif; ?>
                        <input type="text" name="<?= $feat['field'] ?>_content"
                               class="bh-input"
                               placeholder="<?= htmlspecialchars($feat['ch_default'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                               value="<?= htmlspecialchars(wlcm_str($settings, $feat['field'] . '_content', $feat['ch_default'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                <?php elseif ($cfgType === 'content'): ?>
                    <div class="bh-wlcm-cfg-field">
                        <div class="bh-wlcm-cfg-label">Nachrichteninhalt</div>
                        <?php if (!empty($feat['vars'])): ?>
                            <div class="bh-wlcm-cfg-hint">Variablen: <code><?= htmlspecialchars($feat['vars'], ENT_QUOTES, 'UTF-8') ?></code></div>
                        <?php endif; ?>
                        <input type="text" name="dm_join_content"
                               class="bh-input"
                               placeholder="<?= htmlspecialchars($feat['ch_default'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                               value="<?= htmlspecialchars(wlcm_str($settings, 'dm_join_content', $feat['ch_default'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                <?php elseif ($cfgType === 'roles'): ?>
                    <div class="bh-wlcm-cfg-field">
                        <div class="bh-wlcm-cfg-label">Rollen-IDs</div>
                        <div class="bh-wlcm-cfg-sublabel">Kommagetrennte Liste von Rollen-IDs, die beim Beitritt vergeben werden.</div>
                        <?php
                            $rawRoles  = wlcm_str($settings, 'role_join_roles', '[]');
                            $rolesArr  = json_decode($rawRoles, true);
                            $rolesStr  = is_array($rolesArr) ? implode(', ', $rolesArr) : '';
                        ?>
                        <input type="text" name="role_join_roles"
                               class="bh-input"
                               placeholder="123456789, 987654321"
                               value="<?= htmlspecialchars($rolesStr, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                <?php endif; ?>

                <div class="bh-wlcm-cfg-save-row">
                    <button type="submit" class="bh-wlcm-btn">Speichern</button>
                </div>
            </form>
        </div>

        <?php endforeach; ?>
    </div>

    <!-- ── Module: Commands ── -->
    <div class="bh-card">
        <div class="bh-card-hdr">
            <div class="bh-wlcm-card__sec-kicker">MODULE</div>
            <div class="bh-card-title">Commands</div>
        </div>

        <div class="bh-wlcm-module-bar">
            <span class="bh-wlcm-module-bar__text">Dieser Command hat Zugriff auf alle Variablen und Einstellungen dieses Moduls.</span>
            <button class="bh-wlcm-btn" type="button" onclick="document.getElementById('bh-wlcm-add-cmd').style.display='flex'">Hinzufügen</button>
        </div>

        <?php if (count($commands) > 0): ?>
            <div class="bh-wlcm-list">
                <?php foreach ($commands as $cmd): ?>
                    <div class="bh-wlcm-list-row">
                        <div>
                            <div class="bh-wlcm-list-row__key"><?= htmlspecialchars((string)$cmd['command_key'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php if (trim((string)$cmd['description']) !== ''): ?>
                                <div class="bh-wlcm-list-row__desc"><?= htmlspecialchars((string)$cmd['description'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </div>
                        <form method="post" action="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="delete_command">
                            <input type="hidden" name="entry_id" value="<?= (int)$cmd['id'] ?>">
                            <button type="submit" class="bh-wlcm-btn bh-wlcm-btn--danger">Löschen</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="bh-wlcm-empty">Keine Commands vorhanden.</div>
        <?php endif; ?>

        <form method="post" action="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>"
              id="bh-wlcm-add-cmd" class="bh-wlcm-add-form" style="display:none">
            <input type="hidden" name="action" value="add_command">
            <input class="bh-input" type="text" name="command_key"  placeholder="Command Key (z.B. welcome)" required>
            <input class="bh-input" type="text" name="command_desc" placeholder="Beschreibung (optional)">
            <button type="submit" class="bh-wlcm-btn">Speichern</button>
        </form>
    </div>

    <!-- ── Module: Events ── -->
    <div class="bh-card">
        <div class="bh-card-hdr">
            <div class="bh-wlcm-card__sec-kicker">MODULE</div>
            <div class="bh-card-title">Events</div>
        </div>

        <div class="bh-wlcm-events-grid">
            <?php foreach ($eventHandlers as $evh):
                $checked = wlcm_bool($settings, $evh['field'], 1);
            ?>
            <div class="bh-wlcm-feature">
                <div class="bh-wlcm-feature__left">
                    <div class="bh-wlcm-feature__kicker bh-wlcm-feature__kicker--module">MODULE</div>
                    <div class="bh-wlcm-feature__title"><?= htmlspecialchars($evh['title'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="bh-wlcm-feature__desc"><?= htmlspecialchars($evh['desc'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <label class="bh-toggle">
                    <input
                        class="bh-toggle-input"
                        type="checkbox"
                        data-field="<?= htmlspecialchars($evh['field'], ENT_QUOTES, 'UTF-8') ?>"
                        data-bot-id="<?= $botId ?>"
                        <?= $checked ? 'checked' : '' ?>
                        onchange="bhWlcmToggle(this, 'checkbox')"
                    >
                    <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                </label>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="bh-wlcm-module-bar">
            <span class="bh-wlcm-module-bar__text">Dieses Event hat Zugriff auf alle Variablen und Einstellungen dieses Moduls.</span>
            <button class="bh-wlcm-btn" type="button" onclick="document.getElementById('bh-wlcm-add-evt').style.display='flex'">Hinzufügen</button>
        </div>

        <?php if (count($moduleEvents) > 0): ?>
            <div class="bh-wlcm-list">
                <?php foreach ($moduleEvents as $evt): ?>
                    <div class="bh-wlcm-list-row">
                        <div>
                            <div class="bh-wlcm-list-row__key"><?= htmlspecialchars((string)$evt['event_key'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php if (trim((string)$evt['description']) !== ''): ?>
                                <div class="bh-wlcm-list-row__desc"><?= htmlspecialchars((string)$evt['description'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </div>
                        <form method="post" action="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="delete_event">
                            <input type="hidden" name="entry_id" value="<?= (int)$evt['id'] ?>">
                            <button type="submit" class="bh-wlcm-btn bh-wlcm-btn--danger">Löschen</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="bh-wlcm-empty">Keine Custom Events vorhanden.</div>
        <?php endif; ?>

        <form method="post" action="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>"
              id="bh-wlcm-add-evt" class="bh-wlcm-add-form" style="display:none">
            <input type="hidden" name="action" value="add_event">
            <input class="bh-input" type="text" name="event_key"  placeholder="Event Key (z.B. on_join)" required>
            <input class="bh-input" type="text" name="event_desc" placeholder="Beschreibung (optional)">
            <button type="submit" class="bh-wlcm-btn">Speichern</button>
        </form>
    </div>

</div>
</div><!-- /bh-mod-body -->

<script>
(function () {
    const BOT_ID = <?= (int)$botId ?>;

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function setupPicker(boxId, valInputId) {
        var box    = document.getElementById(boxId);
        var valIn  = document.getElementById(valInputId);
        if (!box || !valIn) return;
        var addBtn = box.querySelector('.bh-wlcm-picker-add');

        function renderTag(id, name) {
            box.querySelectorAll('.bh-wlcm-ch-tag').forEach(function (t) { t.remove(); });
            if (!id) return;
            var tag = document.createElement('span');
            tag.className = 'bh-wlcm-ch-tag';
            tag.innerHTML = '#' + escHtml(name || id)
                + '<button type="button" class="bh-wlcm-ch-tag-rm" title="Entfernen">×</button>';
            tag.querySelector('.bh-wlcm-ch-tag-rm').addEventListener('click', function () {
                valIn.value = '';
                tag.remove();
            });
            box.insertBefore(tag, addBtn);
        }

        // Init with existing value (show ID as label until picker is used)
        if (valIn.value) renderTag(valIn.value, valIn.value);

        addBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            BhPerm.openPicker(this, BOT_ID, 'channels', [], function (item) {
                valIn.value = item.id;
                renderTag(item.id, item.name || item.id);
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        setupPicker('wlcm_wc_channel_box',    'wlcm_wc_channel_val');
        <?php foreach ($features as $feat): if (($feat['cfg'] ?? '') !== 'channel+content') continue; ?>
        setupPicker('wlcm_<?= $feat['field'] ?>_ch_box', 'wlcm_<?= $feat['field'] ?>_ch_val');
        <?php endforeach; ?>
    });
}());

function bhWlcmToggle(el, type) {
    var field = el.dataset.field;
    var botId = el.dataset.botId;
    var value = type === 'checkbox'   ? (el.checked ? 1 : 0)
              : type === 'select-str' ? el.value
              : parseInt(el.value, 10);

    var fd = new FormData();
    fd.append('_ajax', '1');
    fd.append('field', field);
    fd.append('value', value);
    fd.append('bot_id', botId);

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .catch(function () {});
}

function bhWlcmSaveCard() {
    var form    = document.getElementById('bh-wlcm-card-form');
    var flashEl = document.getElementById('bh-wlcm-card-flash');
    if (!form) return;

    var fd = new FormData(form);
    fd.append('_ajax', '1');
    fd.append('field', 'save_card_config');
    // Channel value from hidden input
    var chVal = document.getElementById('wlcm_wc_channel_val');
    if (chVal) fd.set('wc_channel', chVal.value);

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!flashEl) return;
            flashEl.className = 'bh-alert ' + (d.ok ? 'bh-alert--ok' : 'bh-alert--err');
            flashEl.textContent = d.ok ? 'Welcome Card gespeichert.' : ('Fehler: ' + (d.error || 'Unbekannt'));
            flashEl.style.display = 'block';
            clearTimeout(flashEl._t);
            flashEl._t = setTimeout(function () { flashEl.style.display = 'none'; }, 4000);
        })
        .catch(function () {
            if (flashEl) {
                flashEl.className = 'bh-alert bh-alert--err';
                flashEl.textContent = 'Netzwerkfehler.';
                flashEl.style.display = 'block';
            }
        });
}

function bhWlcmCardUpdate(val) {
    var config      = document.getElementById('bh-wlcm-card-config');
    var chSec       = document.getElementById('bh-wlcm-cfg-ch-sec');
    var reactSec    = document.getElementById('bh-wlcm-cfg-react-sec');
    if (!config) return;

    var showConfig  = val !== '';
    var showChannel = val === 'channel' || val === 'both';

    config.style.display = showConfig ? 'block' : 'none';
    if (chSec)    chSec.style.display    = showChannel ? 'block' : 'none';
    if (reactSec) reactSec.style.display = showChannel ? 'block' : 'none';
}

function bhWlcmFeatCfgToggle(el) {
    var panelId = el.dataset.cfgPanel;
    if (!panelId) return;
    var panel = document.getElementById(panelId);
    if (panel) panel.style.display = el.checked ? 'block' : 'none';
}

function bhSyncColor(picker, targetName) {
    var input = document.querySelector('[name="' + targetName + '"]');
    if (input) input.value = picker.value;
}
function bhSyncColorText(input, pickerName) {
    var picker = document.querySelector('[name="' + pickerName + '"]');
    if (picker && /^#[0-9a-fA-F]{6}$/.test(input.value)) picker.value = input.value;
}
</script>
