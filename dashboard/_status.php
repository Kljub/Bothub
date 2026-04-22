<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/functions/html.php';
require_once $projectRoot . '/functions/custom_commands.php';

if (!function_exists('bh_notify_status_apply')) {
    function bh_notify_status_apply(int $botId): void
    {
        if ($botId <= 0) return;
        try {
            $secretPath = dirname(__DIR__) . '/db/config/secret.php';
            if (!is_file($secretPath) || !is_readable($secretPath)) return;
            $secret = require $secretPath;
            $appKey = trim((string)($secret['APP_KEY'] ?? ''));
            if ($appKey === '') return;

            $pdo     = bh_cc_get_pdo();
            $stmt    = $pdo->query("SELECT endpoint FROM core_runners WHERE endpoint != '' ORDER BY id ASC");
            $runners = $stmt ? $stmt->fetchAll() : [];
            if (!is_array($runners) || count($runners) === 0) return;

            foreach ($runners as $runner) {
                $endpoint = rtrim(trim((string)($runner['endpoint'] ?? '')), '/');
                if ($endpoint === '') continue;
                $ch = curl_init($endpoint . '/status/bot/' . $botId . '/apply');
                if ($ch === false) continue;
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => '',
                    CURLOPT_TIMEOUT        => 4,
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_HTTPHEADER     => [
                        'Authorization: Bearer ' . $appKey,
                        'Content-Type: application/json',
                    ],
                ]);
                curl_exec($ch);
            }
        } catch (Throwable) {}
    }
}

$pdo    = bh_get_pdo();
$userId = (int)($_SESSION['user_id'] ?? 0);
$botId  = $currentBotId ?? 0;

if ($botId <= 0) {
    echo '<p class="text-red-500">Kein Bot ausgewählt.</p>';
    return;
}

$ownerCheck = $pdo->prepare('SELECT id FROM bot_instances WHERE id = ? AND owner_user_id = ? LIMIT 1');
$ownerCheck->execute([$botId, $userId]);
if (!$ownerCheck->fetch()) {
    echo '<p class="text-red-500">Zugriff verweigert.</p>';
    return;
}

// ── Ensure tables exist ──────────────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS bot_status_settings (
        id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        bot_id           INT UNSIGNED NOT NULL,
        mode             ENUM('fixed','rotating','command','disabled')                           NOT NULL DEFAULT 'disabled',
        presence_status  ENUM('online','idle','dnd','invisible')                                 NOT NULL DEFAULT 'online',
        status_type      ENUM('watching','playing','listening','streaming','competing','custom')  NOT NULL DEFAULT 'playing',
        status_text      VARCHAR(128) NOT NULL DEFAULT '',
        stream_url       VARCHAR(255) NOT NULL DEFAULT '',
        rotating_interval INT UNSIGNED NOT NULL DEFAULT 60,
        cmd_change_status TINYINT(1)  NOT NULL DEFAULT 0,
        event_restart    TINYINT(1)   NOT NULL DEFAULT 1,
        event_update     TINYINT(1)   NOT NULL DEFAULT 1,
        event_rotating   TINYINT(1)   NOT NULL DEFAULT 1,
        UNIQUE KEY uq_bot_id (bot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS bot_status_rotations (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        bot_id      INT UNSIGNED NOT NULL,
        status_type ENUM('watching','playing','listening','streaming','competing','custom') NOT NULL DEFAULT 'playing',
        status_text VARCHAR(128) NOT NULL DEFAULT '',
        stream_url  VARCHAR(255) NOT NULL DEFAULT '',
        sort_order  INT UNSIGNED NOT NULL DEFAULT 0,
        INDEX idx_bot_id (bot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Silently add columns if migrating from old schema
try { $pdo->exec("ALTER TABLE bot_status_settings ADD COLUMN presence_status ENUM('online','idle','dnd','invisible') NOT NULL DEFAULT 'online' AFTER mode"); } catch (Throwable) {}
try { $pdo->exec("ALTER TABLE bot_status_settings ADD COLUMN stream_url VARCHAR(255) NOT NULL DEFAULT '' AFTER status_text"); } catch (Throwable) {}
try { $pdo->exec("ALTER TABLE bot_status_rotations ADD COLUMN stream_url VARCHAR(255) NOT NULL DEFAULT '' AFTER status_text"); } catch (Throwable) {}

$pdo->prepare("INSERT IGNORE INTO bot_status_settings (bot_id) VALUES (?)")->execute([$botId]);

$stmt = $pdo->prepare('SELECT * FROM bot_status_settings WHERE bot_id = ? LIMIT 1');
$stmt->execute([$botId]);
$ms = $stmt->fetch() ?: [];
$g = function(string $k, mixed $d = '') use (&$ms) { return $ms[$k] ?? $d; };

$validTypes    = ['watching', 'playing', 'listening', 'streaming', 'competing', 'custom'];
$validPresence = ['online', 'idle', 'dnd', 'invisible'];

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// ── CSRF check for all AJAX POSTs ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    $csrfHeader = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? ''));
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrfHeader)) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf_mismatch']);
        exit;
    }
}

// ── AJAX: toggle bool fields ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'toggle' && $isAjax) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $field   = (string)($_POST['field'] ?? '');
    $map = [
        'cmd_change_status' => 'cmd_change_status',
        'event_restart'    => 'event_restart',
        'event_update'     => 'event_update',
        'event_rotating'   => 'event_rotating'
    ];

    if (!isset($map[$field])) {
        echo json_encode(['ok' => false, 'error' => 'invalid_field']);
        exit;
    }

    $val = ($_POST['value'] ?? '') === '1' ? 1 : 0;
    $sql = "UPDATE bot_status_settings SET " . $map[$field] . " = ? WHERE bot_id = ?";
    $pdo->prepare($sql)->execute([$val, $botId]);
    bh_notify_status_apply($botId);
    echo json_encode(['ok' => true, 'field' => $field, 'value' => $val]);
    exit;
}

// ── AJAX: set mode ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'set_mode' && $isAjax) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $mode       = (string)($_POST['mode'] ?? '');
    $validModes = ['fixed', 'rotating', 'command', 'disabled'];
    if (!in_array($mode, $validModes, true)) {
        echo json_encode(['ok' => false, 'error' => 'invalid_mode']);
        exit;
    }

    $pdo->prepare("UPDATE bot_status_settings SET mode = ? WHERE bot_id = ?")->execute([$mode, $botId]);
    bh_notify_status_apply($botId);
    echo json_encode(['ok' => true, 'mode' => $mode]);
    exit;
}

// ── AJAX: set presence ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'set_presence' && $isAjax) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $presence = (string)($_POST['presence'] ?? '');
    if (!in_array($presence, $validPresence, true)) {
        echo json_encode(['ok' => false, 'error' => 'invalid_presence']);
        exit;
    }

    $pdo->prepare("UPDATE bot_status_settings SET presence_status = ? WHERE bot_id = ?")->execute([$presence, $botId]);
    bh_notify_status_apply($botId);
    echo json_encode(['ok' => true, 'presence' => $presence]);
    exit;
}

// ── AJAX: add rotation entry ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'add_rotation' && $isAjax) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $type      = (string)($_POST['status_type'] ?? 'playing');
    $text      = trim((string)($_POST['status_text'] ?? ''));
    $streamUrl = mb_substr(trim((string)($_POST['stream_url'] ?? '')), 0, 255);

    if (!in_array($type, $validTypes, true)) {
        echo json_encode(['ok' => false, 'error' => 'invalid_type']);
        exit;
    }
    if ($text === '' && $type !== 'custom') {
        echo json_encode(['ok' => false, 'error' => 'text_required']);
        exit;
    }
    $text = mb_substr($text, 0, 128);

    $maxOrder = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM bot_status_rotations WHERE bot_id = ?');
    $maxOrder->execute([$botId]);
    $nextOrder = (int)$maxOrder->fetchColumn() + 1;

    $ins = $pdo->prepare('INSERT INTO bot_status_rotations (bot_id, status_type, status_text, stream_url, sort_order) VALUES (?,?,?,?,?)');
    $ins->execute([$botId, $type, $text, $streamUrl, $nextOrder]);
    $newId = (int)$pdo->lastInsertId();

    echo json_encode(['ok' => true, 'id' => $newId, 'status_type' => $type, 'status_text' => $text, 'stream_url' => $streamUrl]);
    exit;
}

// ── AJAX: delete rotation entry ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'del_rotation' && $isAjax) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $rotId = (int)($_POST['rotation_id'] ?? 0);
    if ($rotId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'invalid_id']);
        exit;
    }
    $pdo->prepare('DELETE FROM bot_status_rotations WHERE id = ? AND bot_id = ?')->execute([$rotId, $botId]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── AJAX: save settings ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'save_settings' && $isAjax) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    try {
        $type      = (string)($_POST['status_type'] ?? 'playing');
        $text      = mb_substr(trim((string)($_POST['status_text'] ?? '')), 0, 128);
        $streamUrl = mb_substr(trim((string)($_POST['stream_url'] ?? '')), 0, 255);
        $interval  = max(10, min(3600, (int)($_POST['rotating_interval'] ?? 60)));

        if (!in_array($type, $validTypes, true)) $type = 'playing';

        $pdo->prepare("
            UPDATE bot_status_settings
            SET status_type = ?, status_text = ?, stream_url = ?, rotating_interval = ?
            WHERE bot_id = ?
        ")->execute([$type, $text, $streamUrl, $interval, $botId]);

        bh_notify_status_apply($botId);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Load rotations for display
$rotStmt = $pdo->prepare('SELECT * FROM bot_status_rotations WHERE bot_id = ? ORDER BY sort_order ASC, id ASC');
$rotStmt->execute([$botId]);
$rotations = $rotStmt->fetchAll();

$currentMode     = (string)($g('mode', 'disabled'));
$currentPresence = (string)($g('presence_status', 'online'));
?>

<div class="space-y-6" x-data="statusModule()" x-init="init()">

    <div id="settings-toast" class="hidden px-4 py-3 rounded-lg text-sm"></div>

    <!-- ── Presence Status ──────────────────────────────────────────────────── -->
    <div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl p-6">
        <div class="text-xs font-semibold text-violet-500 uppercase tracking-wider mb-1">Presence</div>
        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-1">Presence Status</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">
            Legt fest, wie der Bot in der Discord-Mitgliederliste erscheint. Invisible erlaubt unsichtbaren Betrieb — der Bot kann trotzdem Befehle empfangen.
        </p>

        <div class="flex flex-wrap justify-center gap-6" x-data="{ presence: <?= json_encode($currentPresence) ?> }">

            <!-- Online -->
            <button type="button"
                @click="presence = 'online'; setPresence('online')"
                :class="presence === 'online'
                    ? 'ring-2 ring-emerald-500 bg-emerald-50 dark:bg-emerald-500/10 border-emerald-300 dark:border-emerald-600'
                    : 'border-gray-200 dark:border-gray-700 hover:border-emerald-300 dark:hover:border-emerald-600'"
                class="flex flex-row items-center gap-2.5 rounded-xl border px-4 py-3 transition-all cursor-pointer">
                <span class="w-3.5 h-3.5 rounded-full bg-emerald-500 shrink-0 shadow-sm shadow-emerald-300"></span>
                <span class="text-sm font-medium" style="color:#22c55e">Online</span>
            </button>

            <!-- Idle -->
            <button type="button"
                @click="presence = 'idle'; setPresence('idle')"
                :class="presence === 'idle'
                    ? 'ring-2 ring-yellow-400 bg-yellow-50 dark:bg-yellow-400/10 border-yellow-300 dark:border-yellow-600'
                    : 'border-gray-200 dark:border-gray-700 hover:border-yellow-300 dark:hover:border-yellow-600'"
                class="flex flex-row items-center gap-2.5 rounded-xl border px-4 py-3 transition-all cursor-pointer">
                <svg class="w-3.5 h-3.5 text-yellow-400 shrink-0" viewBox="0 0 16 16" fill="currentColor">
                    <path d="M6 .278a.77.77 0 0 1 .08.858 7.208 7.208 0 0 0-.878 3.46c0 4.021 3.278 7.277 7.318 7.277q.792-.001 1.533-.16a.787.787 0 0 1 .81.316.733.733 0 0 1-.031.893A8.349 8.349 0 0 1 8.344 16C3.734 16 0 12.286 0 7.71 0 4.266 2.114 1.312 5.124.06A.752.752 0 0 1 6 .278z"/>
                </svg>
                <span class="text-sm font-medium" style="color:#facc15">Idle</span>
            </button>

            <!-- Do not Disturb -->
            <button type="button"
                @click="presence = 'dnd'; setPresence('dnd')"
                :class="presence === 'dnd'
                    ? 'ring-2 ring-rose-500 bg-rose-50 dark:bg-rose-500/10 border-rose-300 dark:border-rose-600'
                    : 'border-gray-200 dark:border-gray-700 hover:border-rose-300 dark:hover:border-rose-600'"
                class="flex flex-row items-center gap-2.5 rounded-xl border px-4 py-3 transition-all cursor-pointer">
                <span class="relative w-3.5 h-3.5 shrink-0 flex items-center justify-center rounded-full bg-rose-500">
                    <span class="block w-2 h-0.5 bg-white rounded-full"></span>
                </span>
                <span class="text-sm font-medium" style="color:#ef4444">Do not Disturb</span>
            </button>

            <!-- Invisible -->
            <button type="button"
                @click="presence = 'invisible'; setPresence('invisible')"
                :class="presence === 'invisible'
                    ? 'ring-2 ring-gray-400 bg-gray-50 dark:bg-gray-700/50 border-gray-400 dark:border-gray-500'
                    : 'border-gray-200 dark:border-gray-700 hover:border-gray-400 dark:hover:border-gray-500'"
                class="flex flex-row items-center gap-2.5 rounded-xl border px-4 py-3 transition-all cursor-pointer">
                <span style="display:inline-block;width:14px;height:14px;border-radius:50%;border:2px solid #9ca3af;background:transparent;flex-shrink:0"></span>
                <span class="text-sm font-medium" style="color:#9ca3af">Invisible</span>
            </button>

        </div>

        <p class="text-xs text-gray-400 dark:text-gray-500 mt-3">
            Invisible: Der Bot erscheint offline, reagiert aber weiterhin auf Befehle und läuft im Hintergrund.
        </p>
    </div>

    <!-- ── Activity Mode ─────────────────────────────────────────────────────── -->
    <div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl p-6">
        <div class="text-xs font-semibold text-violet-500 uppercase tracking-wider mb-1">Activity</div>
        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">Activity Mode</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">Wähle zwischen einem festen, rotierenden oder per-Befehl steuerbaren Status.</p>
        <select
            x-model="mode"
            @change="setMode(mode)"
            class="w-full form-select bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-800 dark:text-gray-100 px-3 py-2 focus:ring-2 focus:ring-violet-500 focus:border-violet-500"
        >
            <option value="fixed">Fixed</option>
            <option value="rotating">Rotating</option>
            <option value="command">Command</option>
            <option value="disabled">Disabled</option>
        </select>
    </div>

    <!-- ── Activity Settings ─────────────────────────────────────────────────── -->
    <div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl p-6" x-show="mode !== 'disabled'" x-cloak>
        <div class="text-xs font-semibold text-violet-500 uppercase tracking-wider mb-1">Activity</div>
        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-6">Activity Settings</h2>

        <!-- Fixed / Command settings -->
        <div x-show="mode === 'fixed' || mode === 'command'" x-cloak>
            <div class="space-y-5">

                <!-- Activity Type -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Activity Type</label>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">Wähle den Aktivitätstyp des Bots.</p>
                    <select id="settings-status-type"
                        onchange="onActivityTypeChange(this.value, 'settings-stream-url-row')"
                        class="w-full form-select bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-800 dark:text-gray-100 px-3 py-2 focus:ring-2 focus:ring-violet-500 focus:border-violet-500">
                        <option value="playing"    <?= $g('status_type') === 'playing'    ? 'selected' : '' ?>>🎮 Playing</option>
                        <option value="watching"   <?= $g('status_type') === 'watching'   ? 'selected' : '' ?>>📺 Watching</option>
                        <option value="listening"  <?= $g('status_type') === 'listening'  ? 'selected' : '' ?>>🎵 Listening to</option>
                        <option value="streaming"  <?= $g('status_type') === 'streaming'  ? 'selected' : '' ?>>🟣 Streaming</option>
                        <option value="competing"  <?= $g('status_type') === 'competing'  ? 'selected' : '' ?>>🏆 Competing in</option>
                        <option value="custom"     <?= $g('status_type') === 'custom'     ? 'selected' : '' ?>>✏️ Custom</option>
                    </select>
                </div>

                <!-- Status Text -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        <span id="settings-text-label">Status Text</span>
                    </label>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-2" id="settings-text-hint">
                        Der Text der neben dem Aktivitätstyp angezeigt wird.
                    </p>
                    <input type="text" id="settings-status-text" maxlength="128"
                        value="<?= h((string)$g('status_text', '')) ?>"
                        placeholder="z.B. BotHub Dashboard"
                        class="w-full form-input bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-800 dark:text-gray-100 px-3 py-2 focus:ring-2 focus:ring-violet-500 focus:border-violet-500">
                </div>

                <!-- Stream URL (only for streaming) -->
                <div id="settings-stream-url-row" style="<?= $g('status_type') === 'streaming' ? '' : 'display:none' ?>">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Stream URL</label>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">Twitch- oder YouTube-URL des Streams (z.B. https://twitch.tv/dein_kanal).</p>
                    <input type="url" id="settings-stream-url" maxlength="255"
                        value="<?= h((string)$g('stream_url', '')) ?>"
                        placeholder="https://twitch.tv/dein_kanal"
                        class="w-full form-input bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-800 dark:text-gray-100 px-3 py-2 focus:ring-2 focus:ring-violet-500 focus:border-violet-500">
                </div>

                <div class="pt-2">
                    <button type="button" onclick="saveSettings()" class="btn bg-violet-500 hover:bg-violet-600 text-white px-5 py-2 rounded-lg text-sm font-medium">Speichern</button>
                </div>
            </div>
        </div>

        <!-- Rotating settings -->
        <div x-show="mode === 'rotating'" x-cloak>
            <div class="flex items-end gap-4 mb-6">
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Interval (Sekunden)</label>
                    <input type="number" id="settings-rotating-interval" min="10" max="3600"
                        value="<?= (int)$g('rotating_interval', 60) ?>"
                        class="w-full form-input bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-800 dark:text-gray-100 px-3 py-2 focus:ring-2 focus:ring-violet-500 focus:border-violet-500">
                </div>
                <button type="button" onclick="saveSettings()" class="btn bg-violet-500 hover:bg-violet-600 text-white px-5 py-2 rounded-lg text-sm font-medium">Speichern</button>
            </div>

            <!-- Rotation list -->
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Rotating Activities</h3>
            <div id="rotation-list" class="space-y-2 mb-4">
                <?php foreach ($rotations as $rot): ?>
                <div class="flex items-center gap-3 bg-gray-50 dark:bg-gray-700 rounded-lg px-4 py-2" data-id="<?= (int)$rot['id'] ?>">
                    <span class="text-xs text-gray-400 uppercase font-semibold w-20 shrink-0"><?= h($rot['status_type']) ?></span>
                    <span class="text-sm text-gray-800 dark:text-gray-100 flex-1 truncate"><?= h($rot['status_text']) ?>
                        <?php if (!empty($rot['stream_url'])): ?>
                            <span class="ml-1 text-xs text-violet-400">(<?= h($rot['stream_url']) ?>)</span>
                        <?php endif; ?>
                    </span>
                    <button type="button" onclick="deleteRotation(<?= (int)$rot['id'] ?>, this.closest('[data-id]'))"
                        class="text-red-400 hover:text-red-600 text-xs shrink-0">✕</button>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Add rotation -->
            <div class="space-y-3 border border-gray-100 dark:border-gray-700 rounded-xl p-4">
                <h4 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Eintrag hinzufügen</h4>
                <div class="flex gap-3 items-end flex-wrap">
                    <div class="w-40 shrink-0">
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Typ</label>
                        <select id="rot-type"
                            onchange="onActivityTypeChange(this.value, 'rot-stream-url-row')"
                            class="w-full form-select bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-800 dark:text-gray-100 px-2 py-2">
                            <option value="playing">🎮 Playing</option>
                            <option value="watching">📺 Watching</option>
                            <option value="listening">🎵 Listening to</option>
                            <option value="streaming">🟣 Streaming</option>
                            <option value="competing">🏆 Competing in</option>
                            <option value="custom">✏️ Custom</option>
                        </select>
                    </div>
                    <div class="flex-1 min-w-40">
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Text</label>
                        <input type="text" id="rot-text" maxlength="128" placeholder="z.B. BotHub"
                            class="w-full form-input bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-800 dark:text-gray-100 px-3 py-2">
                    </div>
                    <button type="button" onclick="addRotation()"
                        class="btn bg-violet-500 hover:bg-violet-600 text-white px-4 py-2 rounded-lg text-sm font-medium shrink-0">Add</button>
                </div>
                <!-- Rotation stream URL (shown only for streaming) -->
                <div id="rot-stream-url-row" class="hidden">
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Stream URL</label>
                    <input type="url" id="rot-stream-url" maxlength="255" placeholder="https://twitch.tv/dein_kanal"
                        class="w-full form-input bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-800 dark:text-gray-100 px-3 py-2">
                </div>
            </div>
        </div>
    </div>

    <!-- ── Commands ──────────────────────────────────────────────────────────── -->
    <div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl p-6" x-show="mode === 'command'" x-cloak>
        <div class="text-xs font-semibold text-violet-500 uppercase tracking-wider mb-1">Module</div>
        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">Commands</h2>
        <div class="flex items-center justify-between py-3 border-b border-gray-100 dark:border-gray-700">
            <div>
                <div class="text-sm font-medium text-gray-800 dark:text-gray-100">/change-status</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Change the status of the bot</div>
            </div>
            <label class="bh-toggle">
                <input class="bh-toggle-input" type="checkbox" id="toggle-cmd_change_status"
                    <?= (int)$g('cmd_change_status', 0) === 1 ? 'checked' : '' ?>
                    onchange="statusToggle('cmd_change_status', this.checked)">
                <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
            </label>
        </div>
    </div>

    <!-- ── Events ────────────────────────────────────────────────────────────── -->
    <div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl p-6" x-show="mode !== 'disabled'" x-cloak>
        <div class="text-xs font-semibold text-violet-500 uppercase tracking-wider mb-1">Module</div>
        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">Events</h2>

        <div class="divide-y divide-gray-100 dark:divide-gray-700">
            <div class="flex items-center justify-between py-3" x-show="mode === 'fixed' || mode === 'rotating'" x-cloak>
                <div>
                    <div class="text-sm font-medium text-gray-800 dark:text-gray-100">Restarts Handler</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">When the bot starts or is restarted</div>
                </div>
                <label class="bh-toggle">
                    <input class="bh-toggle-input" type="checkbox" id="toggle-event_restart"
                        <?= (int)$g('event_restart', 1) === 1 ? 'checked' : '' ?>
                        onchange="statusToggle('event_restart', this.checked)">
                    <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                </label>
            </div>

            <div class="flex items-center justify-between py-3" x-show="mode === 'fixed'" x-cloak>
                <div>
                    <div class="text-sm font-medium text-gray-800 dark:text-gray-100">Updates Handler</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">When the bot is updated through the dashboard</div>
                </div>
                <label class="bh-toggle">
                    <input class="bh-toggle-input" type="checkbox" id="toggle-event_update"
                        <?= (int)$g('event_update', 1) === 1 ? 'checked' : '' ?>
                        onchange="statusToggle('event_update', this.checked)">
                    <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                </label>
            </div>

            <div class="flex items-center justify-between py-3" x-show="mode === 'rotating'" x-cloak>
                <div>
                    <div class="text-sm font-medium text-gray-800 dark:text-gray-100">Rotating Handler</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">When a timed rotation is executed</div>
                </div>
                <label class="bh-toggle">
                    <input class="bh-toggle-input" type="checkbox" id="toggle-event_rotating"
                        <?= (int)$g('event_rotating', 1) === 1 ? 'checked' : '' ?>
                        onchange="statusToggle('event_rotating', this.checked)">
                    <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                </label>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var url = window.location.pathname + window.location.search;
    var csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    // Show/hide stream URL row + update text label when activity type changes
    window.onActivityTypeChange = function (type, streamRowId) {
        var row = document.getElementById(streamRowId);
        if (row) {
            row.style.display = (type === 'streaming') ? '' : 'none';
            row.classList.toggle('hidden', type !== 'streaming');
        }
        // Update label/hint for the fixed-mode text input
        var label = document.getElementById('settings-text-label');
        var hint  = document.getElementById('settings-text-hint');
        if (label) {
            var labels = {
                playing:   'Game / Title',
                watching:  'Was wird geschaut',
                listening: 'Titel / Song',
                streaming: 'Stream Titel',
                competing: 'Turnier / Event',
                custom:    'Eigener Text (Custom Status)',
            };
            label.textContent = labels[type] || 'Status Text';
        }
        if (hint) {
            var hints = {
                custom:   'Freier Text – wird als Custom Status angezeigt. Du kannst auch Emojis einbauen.',
                streaming: 'Titel des Streams. Trage unten die Stream-URL ein.',
            };
            hint.textContent = hints[type] || 'Der Text der neben dem Aktivitätstyp angezeigt wird.';
        }
    };

    // Init label on page load
    (function () {
        var sel = document.getElementById('settings-status-type');
        if (sel) window.onActivityTypeChange(sel.value, 'settings-stream-url-row');
    }());

    window.statusModule = function () {
        return {
            mode: <?= json_encode($currentMode) ?>,
            init() {},
            async setMode(m) {
                await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken },
                    body: new URLSearchParams({ _action: 'set_mode', mode: m })
                });
            }
        };
    };

    window.setPresence = async function (presence) {
        await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken },
            body: new URLSearchParams({ _action: 'set_presence', presence: presence })
        });
    };

    window.statusToggle = async function (field, checked) {
        await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken },
            body: new URLSearchParams({ _action: 'toggle', field: field, value: checked ? '1' : '0' })
        });
    };

    window.addRotation = async function () {
        var type      = document.getElementById('rot-type').value;
        var text      = document.getElementById('rot-text').value.trim();
        var streamUrl = (document.getElementById('rot-stream-url') || {}).value || '';

        if (!text && type !== 'custom') return;

        var res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken },
            body: new URLSearchParams({ _action: 'add_rotation', status_type: type, status_text: text, stream_url: streamUrl })
        });
        var data = await res.json();
        if (!data.ok) return;

        var list = document.getElementById('rotation-list');
        var div  = document.createElement('div');
        div.className = 'flex items-center gap-3 bg-gray-50 dark:bg-gray-700 rounded-lg px-4 py-2';
        div.dataset.id = data.id;

        var urlPart = data.stream_url
            ? ' <span class="ml-1 text-xs text-violet-400">(' + data.stream_url.replace(/</g, '&lt;') + ')</span>'
            : '';

        div.innerHTML =
            '<span class="text-xs text-gray-400 uppercase font-semibold w-20 shrink-0">' + data.status_type + '</span>'
            + '<span class="text-sm text-gray-800 dark:text-gray-100 flex-1 truncate">'
            + data.status_text.replace(/</g, '&lt;') + urlPart + '</span>'
            + '<button type="button" onclick="deleteRotation(' + data.id + ', this.closest(\'[data-id]\'))" class="text-red-400 hover:text-red-600 text-xs shrink-0">✕</button>';
        list.appendChild(div);

        document.getElementById('rot-text').value = '';
        if (document.getElementById('rot-stream-url')) {
            document.getElementById('rot-stream-url').value = '';
        }
    };

    window.saveSettings = async function () {
        var type      = (document.getElementById('settings-status-type')       || {}).value || 'playing';
        var text      = (document.getElementById('settings-status-text')       || {}).value || '';
        var streamUrl = (document.getElementById('settings-stream-url')        || {}).value || '';
        var interval  = (document.getElementById('settings-rotating-interval') || {}).value || '60';

        var res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken },
            body: new URLSearchParams({ _action: 'save_settings', status_type: type, status_text: text, stream_url: streamUrl, rotating_interval: interval })
        });
        var data = await res.json();
        var toast = document.getElementById('settings-toast');
        if (!toast) return;
        toast.textContent = data.ok ? 'Einstellungen gespeichert.' : ('Fehler: ' + (data.error || 'Unbekannt'));
        toast.className = 'px-4 py-3 rounded-lg text-sm ' + (data.ok
            ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300'
            : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300');
        clearTimeout(toast._t);
        toast._t = setTimeout(function () { toast.className = 'hidden'; }, 3000);
    };

    window.deleteRotation = async function (id, el) {
        var res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken },
            body: new URLSearchParams({ _action: 'del_rotation', rotation_id: id })
        });
        var data = await res.json();
        if (data.ok && el) el.remove();
    };
}());
</script>
