<?php
declare(strict_types=1);
/** @var int|null $currentBotId */
/** @var int $userId */
require_once dirname(__DIR__) . '/functions/db_functions/commands.php';
require_once dirname(__DIR__) . '/functions/custom_commands.php';

if (!isset($currentBotId) || !is_int($currentBotId) || $currentBotId <= 0) {
    echo '<p style="color:#fca5a5;padding:24px">Kein Bot ausgewählt.</p>';
    return;
}

$botId = $currentBotId;

/* ── Ensure table exists ── */
try {
    $pdo = bh_get_pdo();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bot_music_settings (
            bot_id                  INT UNSIGNED        NOT NULL,
            enabled                 TINYINT(1)          NOT NULL DEFAULT 1,
            default_volume          TINYINT UNSIGNED    NOT NULL DEFAULT 50,
            queue_limit             SMALLINT UNSIGNED   NOT NULL DEFAULT 100,
            dj_role_id              VARCHAR(20)         NULL DEFAULT NULL,
            music_channel_id        VARCHAR(20)         NULL DEFAULT NULL,
            leave_on_empty          TINYINT(1)          NOT NULL DEFAULT 1,
            leave_on_finish         TINYINT(1)          NOT NULL DEFAULT 0,
            announce_songs          TINYINT(1)          NOT NULL DEFAULT 1,
            src_youtube             TINYINT(1)          NOT NULL DEFAULT 1,
            src_spotify             TINYINT(1)          NOT NULL DEFAULT 0,
            src_soundcloud          TINYINT(1)          NOT NULL DEFAULT 0,
            src_deezer              TINYINT(1)          NOT NULL DEFAULT 0,
            src_apple_music         TINYINT(1)          NOT NULL DEFAULT 0,
            src_plex                TINYINT(1)          NOT NULL DEFAULT 0,
            spotify_client_id       VARCHAR(128)        NULL DEFAULT NULL,
            spotify_client_secret   VARCHAR(128)        NULL DEFAULT NULL,
            cmd_play                TINYINT(1)          NOT NULL DEFAULT 1,
            cmd_skip                TINYINT(1)          NOT NULL DEFAULT 1,
            cmd_stop                TINYINT(1)          NOT NULL DEFAULT 1,
            cmd_queue               TINYINT(1)          NOT NULL DEFAULT 1,
            cmd_nowplaying          TINYINT(1)          NOT NULL DEFAULT 1,
            cmd_pause               TINYINT(1)          NOT NULL DEFAULT 1,
            cmd_resume              TINYINT(1)          NOT NULL DEFAULT 1,
            cmd_volume              TINYINT(1)          NOT NULL DEFAULT 1,
            cmd_shuffle             TINYINT(1)          NOT NULL DEFAULT 1,
            cmd_loop                TINYINT(1)          NOT NULL DEFAULT 1,
            cmd_lyrics              TINYINT(1)          NOT NULL DEFAULT 0,
            updated_at              TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (bot_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Throwable $e) {
    echo '<p style="color:#fca5a5;padding:24px">DB-Fehler: ' . htmlspecialchars($e->getMessage()) . '</p>';
    return;
}

/* ── Migrate: add cmd_* columns if the table was created by the old installer ── */
try {
    $cmdCols = [
        'cmd_play'       => 'TINYINT(1) NOT NULL DEFAULT 1',
        'cmd_skip'       => 'TINYINT(1) NOT NULL DEFAULT 1',
        'cmd_stop'       => 'TINYINT(1) NOT NULL DEFAULT 1',
        'cmd_queue'      => 'TINYINT(1) NOT NULL DEFAULT 1',
        'cmd_nowplaying' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'cmd_pause'      => 'TINYINT(1) NOT NULL DEFAULT 1',
        'cmd_resume'     => 'TINYINT(1) NOT NULL DEFAULT 1',
        'cmd_volume'     => 'TINYINT(1) NOT NULL DEFAULT 1',
        'cmd_shuffle'    => 'TINYINT(1) NOT NULL DEFAULT 1',
        'cmd_loop'       => 'TINYINT(1) NOT NULL DEFAULT 1',
        'cmd_lyrics'     => 'TINYINT(1) NOT NULL DEFAULT 0',
    ];
    $existingCols = $pdo->query("SHOW COLUMNS FROM bot_music_settings")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($cmdCols as $col => $def) {
        if (!in_array($col, $existingCols, true)) {
            $pdo->exec("ALTER TABLE bot_music_settings ADD COLUMN {$col} {$def}");
        }
    }
} catch (Throwable) {}

/* ── Load or create settings row ── */
$stmt = $pdo->prepare('SELECT * FROM bot_music_settings WHERE bot_id = ? LIMIT 1');
$stmt->execute([$botId]);
$ms = $stmt->fetch() ?: [];

if (empty($ms)) {
    $pdo->prepare('INSERT IGNORE INTO bot_music_settings (bot_id) VALUES (?)')->execute([$botId]);
    $stmt->execute([$botId]);
    $ms = $stmt->fetch() ?: [];
}

/* ── Seed all music command keys so slash-sync can find them ── */
try {
    $musicCmdKeys = ['music-play','music-skip','music-stop','music-queue','music-nowplaying',
                     'music-pause','music-resume','music-volume','music-shuffle','music-loop','music-lyrics'];
    $seedStmt = $pdo->prepare("
        INSERT IGNORE INTO commands (bot_id, command_key, command_type, name, description, is_enabled, created_at, updated_at)
        VALUES (?, ?, 'module', ?, NULL, 1, NOW(), NOW())
    ");
    $seededCount = 0;
    foreach ($musicCmdKeys as $mk) {
        $seedStmt->execute([$botId, $mk, $mk]);
        $seededCount += $seedStmt->rowCount();
    }
    // Trigger slash-sync when new rows were inserted so Discord picks them up immediately
    if ($seededCount > 0) {
        try { bh_notify_slash_sync($botId); } catch (Throwable) {}
    }
} catch (Throwable) {}

$g = static function (string $key, $default = 0) use ($ms) {
    return $ms[$key] ?? $default;
};

/* ── AJAX toggle handler ── */
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $field = trim((string)($_POST['field'] ?? ''));
    $type  = trim((string)($_POST['type']  ?? 'bool'));

    $boolAllowed = [
        'enabled', 'leave_on_empty', 'leave_on_finish', 'announce_songs',
        'src_youtube', 'src_spotify', 'src_soundcloud', 'src_deezer',
        'src_apple_music', 'src_plex',
    ];

    $musicCmdMap = [
        'cmd_play'       => 'music-play',
        'cmd_skip'       => 'music-skip',
        'cmd_stop'       => 'music-stop',
        'cmd_queue'      => 'music-queue',
        'cmd_nowplaying' => 'music-nowplaying',
        'cmd_pause'      => 'music-pause',
        'cmd_resume'     => 'music-resume',
        'cmd_volume'     => 'music-volume',
        'cmd_shuffle'    => 'music-shuffle',
        'cmd_loop'       => 'music-loop',
        'cmd_lyrics'     => 'music-lyrics',
    ];

    if ($type === 'bool' && in_array($field, $boolAllowed, true)) {
        $val = ($_POST['value'] ?? '') === '1' ? 1 : 0;
        $upd = $pdo->prepare("UPDATE bot_music_settings SET {$field} = ? WHERE bot_id = ?");
        $upd->execute([$val, $botId]);
        echo json_encode(['ok' => true, 'field' => $field, 'value' => $val]);
        exit;
    }

    if ($type === 'bool' && isset($musicCmdMap[$field])) {
        $val = ($_POST['value'] ?? '') === '1' ? 1 : 0;
        // Update both the commands table (for slash-sync) and bot_music_settings (for runtime check)
        bhcmd_set_module_enabled($pdo, $botId, $musicCmdMap[$field], $val);
        $pdo->prepare("UPDATE bot_music_settings SET {$field} = ? WHERE bot_id = ?")->execute([$val, $botId]);
        try { bh_notify_slash_sync($botId); } catch (Throwable) {}
        echo json_encode(['ok' => true, 'field' => $field, 'value' => $val]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'invalid_field']);
    exit;
}

/* ── Settings save ── */
$saveAlert = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    try {
        $vol     = max(0, min(100, (int)($_POST['default_volume'] ?? 50)));
        $qlimit  = max(1, min(500, (int)($_POST['queue_limit'] ?? 100)));
        $djRole  = trim((string)($_POST['dj_role_id'] ?? ''));
        $mChan   = trim((string)($_POST['music_channel_id'] ?? ''));
        $spId    = trim((string)($_POST['spotify_client_id'] ?? ''));
        $spSec   = trim((string)($_POST['spotify_client_secret'] ?? ''));

        $pdo->prepare("
            UPDATE bot_music_settings
            SET default_volume = ?, queue_limit = ?, dj_role_id = ?,
                music_channel_id = ?, spotify_client_id = ?, spotify_client_secret = ?
            WHERE bot_id = ?
        ")->execute([
            $vol, $qlimit,
            $djRole !== '' ? $djRole : null,
            $mChan  !== '' ? $mChan  : null,
            $spId   !== '' ? $spId   : null,
            $spSec  !== '' ? $spSec  : null,
            $botId,
        ]);

        /* reload */
        $stmt->execute([$botId]);
        $ms = $stmt->fetch() ?: $ms;
        $saveAlert = ['ok', 'Einstellungen gespeichert.'];
    } catch (Throwable $e) {
        $saveAlert = ['err', 'Fehler: ' . $e->getMessage()];
    }
}

$sources = [
    [
        'key'   => 'src_youtube',
        'name'  => 'YouTube',
        'slug'  => 'youtube',
        'desc'  => 'Kostenlos · Songs, Playlists, Livestreams',
        'icon'  => '<svg viewBox="0 0 24 24" width="20" height="20" fill="#ff4444"><path d="M23.5 6.2a3 3 0 0 0-2.1-2.1C19.5 3.6 12 3.6 12 3.6s-7.5 0-9.4.5A3 3 0 0 0 .5 6.2C0 8.1 0 12 0 12s0 3.9.5 5.8a3 3 0 0 0 2.1 2.1c1.9.5 9.4.5 9.4.5s7.5 0 9.4-.5a3 3 0 0 0 2.1-2.1C24 15.9 24 12 24 12s0-3.9-.5-5.8ZM9.6 15.6V8.4L15.8 12l-6.2 3.6Z"/></svg>',
    ],
    [
        'key'   => 'src_spotify',
        'name'  => 'Spotify',
        'slug'  => 'spotify',
        'desc'  => 'Tracks, Alben & Playlists via API',
        'icon'  => '<svg viewBox="0 0 24 24" width="20" height="20" fill="#1ed760"><path d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.52 17.28c-.24.36-.66.48-1.02.24-2.82-1.74-6.36-2.1-10.56-1.14-.42.12-.78-.18-.9-.54-.12-.42.18-.78.54-.9 4.56-1.02 8.52-.6 11.64 1.32.42.18.48.66.3 1.02zm1.44-3.3c-.3.42-.84.54-1.26.24-3.24-1.98-8.16-2.58-11.94-1.38-.48.12-.99-.12-1.11-.6-.12-.48.12-.99.6-1.11 4.38-1.32 9.78-.66 13.5 1.62.36.18.54.78.21 1.23zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.3c-.6.18-1.2-.18-1.38-.72-.18-.6.18-1.2.72-1.38C8.58 5.94 15.6 6.18 19.8 8.7c.54.3.72 1.02.42 1.56-.3.42-.96.6-1.5.36l.06-.3-.24.66z"/></svg>',
    ],
    [
        'key'   => 'src_soundcloud',
        'name'  => 'SoundCloud',
        'slug'  => 'soundcloud',
        'desc'  => 'Indie Tracks & Remixes',
        'icon'  => '<svg viewBox="0 0 24 24" width="20" height="20" fill="#ff5500"><path d="M1.175 12.225c-.056 0-.094.037-.1.094l-.233 2.154.233 2.105c.006.06.045.094.1.094.053 0 .09-.04.1-.094l.27-2.105-.27-2.154c-.01-.06-.05-.094-.1-.094zm1.351-.876c-.074 0-.12.05-.127.12l-.198 3.03.197 2.99c.007.07.053.12.128.12.072 0 .12-.05.127-.12l.228-2.99-.228-3.03c-.007-.07-.055-.12-.127-.12zm1.366-.3c-.088 0-.147.062-.15.148l-.175 3.21.175 3.12c.003.086.062.148.15.148.085 0 .143-.062.147-.148l.2-3.12-.2-3.21c-.004-.086-.062-.148-.147-.148zm1.38-.14c-.1 0-.167.07-.17.168l-.15 3.28.15 3.247c.003.098.07.168.17.168.1 0 .165-.07.168-.168l.17-3.247-.17-3.28c-.003-.098-.068-.168-.168-.168zm1.395.006c-.113 0-.187.08-.19.19l-.126 3.22.126 3.213c.003.11.077.19.19.19.112 0 .186-.08.19-.19l.145-3.213-.145-3.22c-.004-.11-.078-.19-.19-.19zm1.41.134c-.126 0-.21.09-.214.214l-.1 3.072.1 3.18c.004.124.088.214.213.214.124 0 .208-.09.213-.214l.115-3.18-.115-3.072c-.005-.124-.09-.214-.213-.214zm1.425.273c-.138 0-.23.1-.234.235l-.076 2.8.076 3.145c.004.135.096.235.234.235.137 0 .23-.1.234-.235l.087-3.145-.087-2.8c-.004-.135-.097-.235-.234-.235zm1.44.408c-.152 0-.253.11-.257.26l-.05 2.39.05 3.11c.004.15.105.26.257.26.15 0 .252-.11.256-.26l.06-3.11-.06-2.39c-.004-.15-.106-.26-.256-.26zm1.457.54c-.165 0-.275.12-.278.283l-.025 1.847.025 3.072c.003.163.113.283.278.283.163 0 .274-.12.277-.283l.03-3.072-.03-1.847c-.003-.163-.114-.283-.277-.283zm1.473.667c-.177 0-.295.13-.298.307l0 1.18.0 3.032c.003.177.12.307.298.307.176 0 .295-.13.298-.307l.0-3.032-.0-1.18c-.003-.177-.122-.307-.298-.307zm5.95-1.748c-.318 0-.62.064-.896.18-.185-2.12-1.955-3.77-4.12-3.77-.56 0-1.093.113-1.57.316-.177.072-.225.146-.226.213v7.38c.001.07.055.127.127.134h6.685c.63 0 1.14-.51 1.14-1.14V12.8c0-1.32-1.07-2.39-2.39-2.39l.25.0z"/></svg>',
    ],
    [
        'key'   => 'src_deezer',
        'name'  => 'Deezer',
        'slug'  => 'deezer',
        'desc'  => 'Tracks & Playlists per Suche',
        'icon'  => '<svg viewBox="0 0 24 24" width="20" height="20" fill="#a200ff"><path d="M18.944 16.777H24v2.221h-5.056v-2.221zm0-4.332H24v2.222h-5.056v-2.222zm0-4.333H24v2.222h-5.056V8.112zM12.542 21.11H17.6v2.222h-5.056v-2.222zm0-4.333H17.6v2.221h-5.056v-2.221zm0-4.332H17.6v2.222h-5.056v-2.222zm0-4.333H17.6v2.222h-5.056V8.112zM6.138 21.11h5.058v2.222H6.138v-2.222zm0-4.333h5.058v2.221H6.138v-2.221zm0-4.332h5.058v2.222H6.138v-2.222zM0 21.11h5.058v2.222H0v-2.222zm0-4.333h5.058v2.221H0v-2.221z"/></svg>',
    ],
    [
        'key'   => 'src_apple_music',
        'name'  => 'Apple Music',
        'slug'  => 'apple',
        'desc'  => 'Metadaten über Apple Music API',
        'icon'  => '<svg viewBox="0 0 24 24" width="20" height="20" fill="#fc3c44"><path d="M23.994 6.124a9.23 9.23 0 0 0-.24-2.19c-.317-1.31-1.048-2.31-2.19-3.03a5.022 5.022 0 0 0-1.785-.737c-.389-.084-.78-.142-1.174-.18-.159-.015-.32-.027-.48-.04H5.582c-.18.013-.36.026-.54.04-.37.036-.738.09-1.106.175A5.1 5.1 0 0 0 .23 3.915a5.3 5.3 0 0 0-.18.69c-.06.32-.1.642-.12.965C0 5.91-.001 6.254 0 6.6v10.8c.001.346.003.692.037 1.038.048.503.139 1.002.352 1.47a5.05 5.05 0 0 0 2.748 2.748c.468.213.967.304 1.47.352.347.034.693.036 1.04.037h10.8c.347-.001.692-.003 1.038-.037.503-.048 1.002-.139 1.47-.352a5.05 5.05 0 0 0 2.748-2.748c.213-.468.304-.967.352-1.47.034-.346.036-.692.037-1.038V6.6c0-.16-.004-.32-.006-.476zm-8.316 10.404c0 .338-.256.528-.558.528-.294 0-.57-.182-.57-.52V10.6c0-.296.2-.544.5-.544h3.526c.33 0 .508.248.508.49 0 .314-.25.506-.508.506h-2.897v.596l.001 4.875v.001zm-4.85-1.394V11.6a4.33 4.33 0 0 1 .168-1.184 2.75 2.75 0 0 1 .478-.882 2.14 2.14 0 0 1 .74-.54c.3-.134.636-.2.978-.2.22 0 .43.03.617.087v1.067a.994.994 0 0 0-.52-.15c-.498 0-.782.32-.916.64-.1.24-.145.5-.146.763v4.01c0 .338-.256.527-.558.527-.293 0-.56-.183-.58-.517l-.262-4.016zm-1.354.014l.001 1.38c0 .34-.256.528-.557.528-.294 0-.565-.184-.566-.52V11.6c0-.296.205-.545.505-.545h.017zm0-3.6V10.3c0-.25.19-.46.44-.49l.126-.006h-.001-.01c-.29.006-.555.2-.556.52v1.13z"/></svg>',
    ],
    [
        'key'   => 'src_plex',
        'name'  => 'Plex',
        'slug'  => 'plex',
        'desc'  => 'Aus deiner Plex Mediathek streamen',
        'icon'  => '<svg viewBox="0 0 24 24" width="20" height="20" fill="#e5a00d"><path d="M11.998 0C5.375 0 0 5.376 0 12s5.375 12 11.998 12C18.625 24 24 18.624 24 12S18.625 0 11.998 0zm5.464 13.703l-4.208 5.544a1.502 1.502 0 0 1-1.196.598 1.499 1.499 0 0 1-1.196-.598l-4.208-5.544a1.506 1.506 0 0 1 0-1.404l4.208-5.544A1.499 1.499 0 0 1 12.058 6.16c.449 0 .871.205 1.196.595l4.208 5.544a1.508 1.508 0 0 1 0 1.404z"/></svg>',
    ],
];

$commands = [
    ['key' => 'cmd_play',       'name' => '/play',        'desc' => 'Song oder URL in Queue laden'],
    ['key' => 'cmd_skip',       'name' => '/skip',        'desc' => 'Aktuellen Song überspringen'],
    ['key' => 'cmd_stop',       'name' => '/stop',        'desc' => 'Musik stoppen & Queue leeren'],
    ['key' => 'cmd_queue',      'name' => '/queue',       'desc' => 'Aktuelle Queue anzeigen'],
    ['key' => 'cmd_nowplaying', 'name' => '/nowplaying',  'desc' => 'Aktuellen Song anzeigen'],
    ['key' => 'cmd_pause',      'name' => '/pause',       'desc' => 'Wiedergabe pausieren'],
    ['key' => 'cmd_resume',     'name' => '/resume',      'desc' => 'Wiedergabe fortsetzen'],
    ['key' => 'cmd_volume',     'name' => '/volume',      'desc' => 'Lautstärke anpassen (0–100)'],
    ['key' => 'cmd_shuffle',    'name' => '/shuffle',     'desc' => 'Queue zufällig mischen'],
    ['key' => 'cmd_loop',       'name' => '/loop',        'desc' => 'Song / Queue loopen'],
    ['key' => 'cmd_lyrics',     'name' => '/lyrics',      'desc' => 'Songtext anzeigen'],
];

$_musicCmdKeyMap = [
    'cmd_play'       => 'music-play',       'cmd_skip'       => 'music-skip',
    'cmd_stop'       => 'music-stop',       'cmd_queue'      => 'music-queue',
    'cmd_nowplaying' => 'music-nowplaying', 'cmd_pause'      => 'music-pause',
    'cmd_resume'     => 'music-resume',     'cmd_volume'     => 'music-volume',
    'cmd_shuffle'    => 'music-shuffle',    'cmd_loop'       => 'music-loop',
    'cmd_lyrics'     => 'music-lyrics',
];
$musicCmdEnabled = [];
foreach ($_musicCmdKeyMap as $fieldKey => $cmdKey) {
    try { $musicCmdEnabled[$fieldKey] = bhcmd_is_enabled($pdo, $botId, $cmdKey); }
    catch (Throwable) { $musicCmdEnabled[$fieldKey] = 1; }
}
?>

<div class="bh-music-page">

    <!-- Head -->
    <div class="bh-music-head">
        <div class="bh-music-kicker">Bot Feature</div>
        <h1 class="bh-music-title">Music</h1>
        <p class="bh-music-subtitle">Musik in Voice Channels abspielen — YouTube, Spotify, SoundCloud und mehr.</p>
    </div>

    <?php if ($saveAlert): ?>
        <div class="bh-alert bh-alert--<?= $saveAlert[0] === 'ok' ? 'ok' : 'err' ?>" style="margin-bottom:20px">
            <?= htmlspecialchars($saveAlert[1]) ?>
        </div>
    <?php endif; ?>

    <!-- ─── SOURCES ─── -->
    <div class="bh-card" style="padding:0;margin-bottom:20px">
        <div class="bh-card-hdr">
            <div class="bh-music-card__icon bh-music-card__icon--indigo">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#818cf8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/>
                </svg>
            </div>
            <div>
                <div class="bh-music-card__sec-kicker">Quellen</div>
                <div class="bh-card-title" style="margin:0">Musik-Quellen</div>
            </div>
        </div>

        <div class="bh-music-sources-grid">
            <?php foreach ($sources as $src): ?>
                <?php $checked = (int)$g($src['key']) === 1; ?>
                <div class="bh-music-source" id="bh-music-src-wrap-<?= $src['slug'] ?>">
                    <div class="bh-music-source__logo bh-music-source__logo--<?= $src['slug'] ?>">
                        <?= $src['icon'] ?>
                    </div>
                    <div class="bh-music-source__info">
                        <div class="bh-music-source__name"><?= htmlspecialchars($src['name']) ?></div>
                        <div class="bh-music-source__desc"><?= htmlspecialchars($src['desc']) ?></div>
                    </div>
                    <label class="bh-toggle">
                        <input class="bh-toggle-input" type="checkbox" <?= $checked ? 'checked' : '' ?>
                            data-field="<?= $src['key'] ?>"
                            onchange="bhMusicToggle(this)">
                        <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                    </label>
                </div>

                <?php if ($src['key'] === 'src_spotify'): ?>
                <div class="bh-music-creds<?= $checked ? ' is-open' : '' ?>"
                    id="bh-music-spotify-creds"
                    style="grid-column: 1 / -1;">
                    <div class="bh-music-creds__label">Spotify Client Credentials (optional – für direkte API-Auflösung)</div>
                    <div class="bh-music-creds__row">
                        <input type="text" class="bh-music-creds__input" name="spotify_client_id"
                            placeholder="Client ID" form="bh-music-settings-form"
                            value="<?= htmlspecialchars((string)$g('spotify_client_id', '')) ?>">
                        <input type="password" class="bh-music-creds__input" name="spotify_client_secret"
                            placeholder="Client Secret" form="bh-music-settings-form"
                            value="<?= htmlspecialchars((string)$g('spotify_client_secret', '')) ?>">
                    </div>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ─── SETTINGS ─── -->
    <div class="bh-card" style="padding:0;margin-bottom:20px">
        <div class="bh-card-hdr">
            <div class="bh-music-card__icon bh-music-card__icon--violet">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#a78bfa" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                </svg>
            </div>
            <div>
                <div class="bh-music-card__sec-kicker">Konfiguration</div>
                <div class="bh-card-title" style="margin:0">Einstellungen</div>
            </div>
        </div>

        <form id="bh-music-settings-form" method="post">
            <input type="hidden" name="save_settings" value="1">
            <div class="bh-card-body">

                <!-- Input fields -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px">
                    <div>
                        <label class="bh-label">Standard-Lautstärke</label>
                        <div class="bh-music-volume-row" style="margin-top:8px">
                            <input type="range" class="bh-music-range" name="default_volume"
                                min="0" max="100" step="1"
                                value="<?= (int)$g('default_volume', 50) ?>"
                                oninput="document.getElementById('bh-music-vol-val').textContent=this.value">
                            <span class="bh-music-range-val" id="bh-music-vol-val"><?= (int)$g('default_volume', 50) ?></span>
                        </div>
                        <p class="bh-hint">Startlautstärke für jede neue Session</p>
                    </div>
                    <div>
                        <label class="bh-label">Queue-Limit</label>
                        <input type="number" class="bh-input" style="max-width:110px;margin-top:4px"
                            name="queue_limit" min="1" max="500"
                            value="<?= (int)$g('queue_limit', 100) ?>">
                        <p class="bh-hint">Max. Songs in der Warteschlange (1–500)</p>
                    </div>
                    <div>
                        <label class="bh-label">DJ-Rolle <span style="font-weight:400;color:#4f5f80">(optional)</span></label>
                        <input type="text" class="bh-input" style="margin-top:4px"
                            name="dj_role_id" placeholder="Rollen-ID"
                            value="<?= htmlspecialchars((string)($ms['dj_role_id'] ?? '')) ?>">
                        <p class="bh-hint">Nur diese Rolle darf skip/stop etc. nutzen. Leer = jeder.</p>
                    </div>
                    <div>
                        <label class="bh-label">Musik-Textkanal <span style="font-weight:400;color:#4f5f80">(optional)</span></label>
                        <input type="hidden" name="music_channel_id" id="music-channel-val"
                            value="<?= htmlspecialchars((string)($ms['music_channel_id'] ?? '')) ?>">
                        <div class="it-picker-row" id="music-channel-box" style="margin-top:4px">
                            <button type="button" class="it-picker-add" id="music-channel-btn">+</button>
                        </div>
                        <p class="bh-hint">Musikbefehle nur in diesem Kanal erlauben. Leer = überall.</p>
                    </div>
                </div>

                <!-- Toggle rows -->
                <div class="bh-music-settings">
                    <div class="bh-music-field">
                        <div class="bh-music-field__left">
                            <div class="bh-music-field__label">Musik-Modul aktiviert</div>
                            <div class="bh-music-field__hint">Alle Musikbefehle ein- oder ausschalten</div>
                        </div>
                        <label class="bh-toggle">
                            <input class="bh-toggle-input" type="checkbox" <?= (int)$g('enabled') ? 'checked' : '' ?>
                                data-field="enabled" onchange="bhMusicToggle(this)">
                            <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                        </label>
                    </div>
                    <div class="bh-music-field">
                        <div class="bh-music-field__left">
                            <div class="bh-music-field__label">Voice verlassen wenn leer</div>
                            <div class="bh-music-field__hint">Bot verlässt den Channel wenn niemand mehr zuhört</div>
                        </div>
                        <label class="bh-toggle">
                            <input class="bh-toggle-input" type="checkbox" <?= (int)$g('leave_on_empty') ? 'checked' : '' ?>
                                data-field="leave_on_empty" onchange="bhMusicToggle(this)">
                            <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                        </label>
                    </div>
                    <div class="bh-music-field">
                        <div class="bh-music-field__left">
                            <div class="bh-music-field__label">Voice verlassen wenn Queue leer</div>
                            <div class="bh-music-field__hint">Bot verlässt den Channel nach dem letzten Song</div>
                        </div>
                        <label class="bh-toggle">
                            <input class="bh-toggle-input" type="checkbox" <?= (int)$g('leave_on_finish') ? 'checked' : '' ?>
                                data-field="leave_on_finish" onchange="bhMusicToggle(this)">
                            <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                        </label>
                    </div>
                    <div class="bh-music-field">
                        <div class="bh-music-field__left">
                            <div class="bh-music-field__label">Song-Ankündigung</div>
                            <div class="bh-music-field__hint">Embed im Textkanal wenn ein neuer Song startet</div>
                        </div>
                        <label class="bh-toggle">
                            <input class="bh-toggle-input" type="checkbox" <?= (int)$g('announce_songs') ? 'checked' : '' ?>
                                data-field="announce_songs" onchange="bhMusicToggle(this)">
                            <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                        </label>
                    </div>
                </div>

            </div>
            <div class="bh-music-save-bar">
                <button type="submit" class="bh-btn bh-btn--primary">Speichern</button>
            </div>
        </form>
    </div>

    <!-- ─── COMMANDS ─── -->
    <div class="bh-card" style="padding:0;margin-bottom:20px">
        <div class="bh-card-hdr">
            <div class="bh-music-card__icon bh-music-card__icon--emerald">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#34d399" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/>
                </svg>
            </div>
            <div>
                <div class="bh-music-card__sec-kicker">Slash Commands</div>
                <div class="bh-card-title" style="margin:0">Musikbefehle</div>
            </div>
        </div>

        <div class="bh-music-cmd-grid">
            <?php foreach ($commands as $cmd): ?>
                <div class="bh-music-cmd-row">
                    <div class="bh-music-cmd-row__info">
                        <div class="bh-music-cmd-row__name"><?= htmlspecialchars($cmd['name']) ?></div>
                        <div class="bh-music-cmd-row__desc"><?= htmlspecialchars($cmd['desc']) ?></div>
                    </div>
                    <label class="bh-toggle">
                        <input class="bh-toggle-input" type="checkbox" <?= ($musicCmdEnabled[$cmd['key']] ?? 1) ? 'checked' : '' ?>
                            data-field="<?= $cmd['key'] ?>" onchange="bhMusicToggle(this)">
                        <span class="bh-toggle-track"><span class="bh-toggle-thumb"></span></span>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

</div><!-- /.bh-music-page -->

<script>
(function () {
    var url = window.location.pathname + window.location.search;
    var BOT_ID = <?= (int)$botId ?>;

    bhSetupChannelPicker('music-channel-box', 'music-channel-val', 'music-channel-btn', BOT_ID);

    function bhMusicToggle(el) {
        var field = el.dataset.field;
        var val   = el.checked ? '1' : '0';

        /* Show Spotify creds if toggled on */
        if (field === 'src_spotify') {
            var creds = document.getElementById('bh-music-spotify-creds');
            if (creds) creds.classList.toggle('is-open', el.checked);
        }

        var fd = new FormData();
        fd.append('field', field);
        fd.append('type',  'bool');
        fd.append('value', val);

        fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.ok) {
                el.checked = !el.checked;
                console.error('[music] toggle error:', d.error);
            }
        })
        .catch(function () { el.checked = !el.checked; });
    }

    window.bhMusicToggle = bhMusicToggle;
}());
</script>
