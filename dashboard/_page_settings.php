<?php
declare(strict_types=1);
/** @var array $sidebarBots */
/** @var int|null $currentBotId */
/** @var int $userId */

require_once __DIR__ . '/../discord/discord_api.php';
require_once __DIR__ . '/../functions/bot_token.php';

if (!isset($sidebarBots) || !is_array($sidebarBots)) {
    $sidebarBots = [];
}
if (!isset($currentBotId) || !is_int($currentBotId)) {
    $currentBotId = null;
}

$activeTab = (string)($_GET['tab'] ?? 'profile');
if ($activeTab !== 'status') {
    $activeTab = 'profile';
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$flashOk = null;
$flashErr = null;
if (is_array($flash)) {
    $type = (string)($flash['type'] ?? '');
    $msg = (string)($flash['msg'] ?? '');
    if ($msg !== '') {
        if ($type === 'ok') {
            $flashOk = $msg;
        } elseif ($type === 'err') {
            $flashErr = $msg;
        }
    }
}

$botName = null;
if ($currentBotId !== null && $currentBotId > 0) {
    foreach ($sidebarBots as $bot) {
        $botId = (int)($bot['id'] ?? 0);
        if ($botId === $currentBotId) {
            $candidate = trim((string)($bot['name'] ?? ''));
            $botName = $candidate !== '' ? $candidate : ('Bot #' . $botId);
            break;
        }
    }
}

function bh_settings_set_flash(string $type, string $msg): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'msg' => $msg,
    ];
}

function bh_settings_redirect(?int $botId, string $tab = 'profile'): never
{
    $location = '/dashboard/settings';
    $query = [];

    if ($botId !== null && $botId > 0) {
        $query['bot_id'] = (string)$botId;
    }
    $query['tab'] = $tab === 'status' ? 'status' : 'profile';

    $qs = http_build_query($query);
    if ($qs !== '') {
        $location .= '?' . $qs;
    }

    header('Location: ' . $location, true, 302);
    exit;
}

function bh_settings_fetch_bot_row(int $userId, int $botId): ?array
{
    $pdo = bh_get_pdo();

    $sql = 'SELECT id,
                   owner_user_id,
                   display_name,
                   discord_bot_user_id,
                   bot_token_encrypted,
                   bot_token_enc_meta,
                   desired_state,
                   runtime_status,
                   last_error,
                   last_started_at,
                   last_stopped_at,
                   is_active
            FROM bot_instances
            WHERE id = :id
              AND owner_user_id = :uid
            LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id' => $botId,
        ':uid' => $userId,
    ]);

    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function bh_settings_try_get_plain_bot_token(array $botRow): array
{
    $result = bh_bot_token_resolve($botRow);

    return [
        'ok'    => $result['ok'],
        'token' => $result['token'],
        'hint'  => $result['error'],
    ];
}

function bh_settings_discord_avatar_url(?string $userId, ?string $avatarHash): ?string
{
    $uid  = trim((string)$userId);
    $hash = trim((string)$avatarHash);
    if ($uid === '' || $hash === '') return null;
    return 'https://cdn.discordapp.com/avatars/' . rawurlencode($uid) . '/' . rawurlencode($hash) . '.png?size=256';
}

function bh_settings_discord_banner_url(?string $userId, ?string $bannerHash): ?string
{
    $uid  = trim((string)$userId);
    $hash = trim((string)$bannerHash);
    if ($uid === '' || $hash === '') return null;
    $ext = str_starts_with($hash, 'a_') ? 'gif' : 'png';
    return 'https://cdn.discordapp.com/banners/' . rawurlencode($uid) . '/' . rawurlencode($hash) . '.' . $ext . '?size=600';
}

function bh_settings_uploaded_avatar_to_data_uri(array $file): array
{
    if (!isset($file['error']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) {
        return [
            'ok' => false,
            'data_uri' => null,
            'error' => 'Kein Avatar ausgewählt.',
        ];
    }

    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        return [
            'ok' => false,
            'data_uri' => null,
            'error' => 'Avatar Upload fehlgeschlagen (Code ' . $error . ').',
        ];
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return [
            'ok' => false,
            'data_uri' => null,
            'error' => 'Upload-Datei ist ungültig.',
        ];
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 2 * 1024 * 1024) {
        return [
            'ok' => false,
            'data_uri' => null,
            'error' => 'Avatar Datei ist zu groß oder leer (max. 2 MiB).',
        ];
    }

    $bin = file_get_contents($tmpName);
    if ($bin === false || $bin === '') {
        return [
            'ok' => false,
            'data_uri' => null,
            'error' => 'Avatar Datei konnte nicht gelesen werden.',
        ];
    }

    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($tmpName);
        $mime  = is_string($mime) ? strtolower(trim($mime)) : '';
    } elseif (function_exists('mime_content_type')) {
        $mime = strtolower(trim((string)mime_content_type($tmpName)));
    } else {
        // Magic-bytes fallback
        $magic = substr($bin, 0, 12);
        if (str_starts_with($magic, "\x89PNG"))          $mime = 'image/png';
        elseif (str_starts_with($magic, "\xFF\xD8\xFF")) $mime = 'image/jpeg';
        elseif (str_starts_with($magic, 'GIF8'))         $mime = 'image/gif';
        elseif (str_starts_with($magic, 'RIFF') && substr($magic, 8, 4) === 'WEBP') $mime = 'image/webp';
        else $mime = '';
    }

    $allowed = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
    if (!in_array($mime, $allowed, true)) {
        return [
            'ok' => false,
            'data_uri' => null,
            'error' => 'Avatar MIME nicht erlaubt: ' . ($mime !== '' ? $mime : 'unknown'),
        ];
    }

    $b64 = base64_encode($bin);
    if ($b64 === '') {
        return [
            'ok' => false,
            'data_uri' => null,
            'error' => 'base64_encode fehlgeschlagen.',
        ];
    }

    return [
        'ok' => true,
        'data_uri' => 'data:' . $mime . ';base64,' . $b64,
        'error' => null,
    ];
}

function bh_settings_update_desired_state(int $userId, int $botId, string $desiredState): bool
{
    $pdo = bh_get_pdo();

    $sql = 'UPDATE bot_instances
            SET desired_state = :desired_state
            WHERE id = :id
              AND owner_user_id = :uid
            LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':desired_state' => $desiredState,
        ':id' => $botId,
        ':uid' => $userId,
    ]);

    return $stmt->rowCount() > 0;
}

function bh_settings_delete_bot(int $userId, int $botId): bool
{
    $pdo = bh_get_pdo();

    $sql = 'DELETE FROM bot_instances
            WHERE id = :id
              AND owner_user_id = :uid
            LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id' => $botId,
        ':uid' => $userId,
    ]);

    return $stmt->rowCount() > 0;
}

function bh_settings_status_label(string $runtimeStatus, string $desiredState): string
{
    if ($runtimeStatus === 'running') {
        return 'Online';
    }

    if ($runtimeStatus === 'error') {
        return 'Fehler';
    }

    if ($desiredState === 'running') {
        return 'Startet...';
    }

    return 'Offline';
}

function bh_settings_status_badge_class(string $runtimeStatus, string $desiredState): string
{
    if ($runtimeStatus === 'running') {
        return 'bh-status-badge bh-status-badge--online';
    }

    if ($runtimeStatus === 'error') {
        return 'bh-status-badge bh-status-badge--error';
    }

    if ($desiredState === 'running') {
        return 'bh-status-badge bh-status-badge--pending';
    }

    return 'bh-status-badge bh-status-badge--offline';
}

function bh_settings_format_dt(?string $value): string
{
    $v = trim((string)$value);
    return $v !== '' ? $v : '—';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // Detect silent post_max_size overflow (PHP drops $_POST + $_FILES entirely)
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $botIdGet = (string)($_GET['bot_id'] ?? '');
        $botIdGuess = ctype_digit($botIdGet) ? (int)$botIdGet : null;
        bh_settings_set_flash('err', 'Die Datei ist zu groß für PHP (post_max_size). Bitte kleinere Datei wählen.');
        bh_settings_redirect($botIdGuess, 'profile');
    }

    // SECURITY: CSRF Check
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!isset($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
        $botIdGet = (string)($_GET['bot_id'] ?? '');
        bh_settings_set_flash('err', 'Ungültiges Sicherheits-Token (CSRF).');
        bh_settings_redirect(ctype_digit($botIdGet) ? (int)$botIdGet : null, 'profile');
    }

    $action = (string)($_POST['action'] ?? '');
    $botIdPost = (string)($_POST['bot_id'] ?? '');
    $botId = ctype_digit($botIdPost) ? (int)$botIdPost : 0;

    if ($action === 'update_profile') {
        if ($botId <= 0) {
            bh_settings_set_flash('err', 'Ungültiger Bot.');
            bh_settings_redirect(null, 'profile');
        }

        $row = bh_settings_fetch_bot_row($userId, $botId);
        if ($row === null) {
            bh_settings_set_flash('err', 'Bot nicht gefunden oder keine Berechtigung.');
            bh_settings_redirect($botId, 'profile');
        }

        $tokenData = bh_settings_try_get_plain_bot_token($row);
        if (!$tokenData['ok']) {
            bh_settings_set_flash('err', (string)($tokenData['hint'] ?? 'Token nicht verfügbar.'));
            bh_settings_redirect($botId, 'profile');
        }

        $newName = trim((string)($_POST['new_name'] ?? ''));
        $payload = [];

        if ($newName !== '') {
            $len = mb_strlen($newName, 'UTF-8');
            if ($len < 2 || $len > 32) {
                bh_settings_set_flash('err', 'Name muss 2–32 Zeichen haben.');
                bh_settings_redirect($botId, 'profile');
            }
            $payload['username'] = $newName;
        }

        if (isset($_FILES['avatar_file']) && is_array($_FILES['avatar_file'])) {
            $avatarError = (int)($_FILES['avatar_file']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($avatarError !== UPLOAD_ERR_NO_FILE) {
                $img = bh_settings_uploaded_avatar_to_data_uri($_FILES['avatar_file']);
                if (!$img['ok']) {
                    bh_settings_set_flash('err', (string)($img['error'] ?? 'Avatar konnte nicht verarbeitet werden.'));
                    bh_settings_redirect($botId, 'profile');
                }
                $payload['avatar'] = (string)$img['data_uri'];
            }
        }

        if (isset($_FILES['banner_file']) && is_array($_FILES['banner_file'])) {
            $bannerError = (int)($_FILES['banner_file']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($bannerError !== UPLOAD_ERR_NO_FILE) {
                $img = bh_settings_uploaded_avatar_to_data_uri($_FILES['banner_file']);
                if (!$img['ok']) {
                    bh_settings_set_flash('err', (string)($img['error'] ?? 'Banner konnte nicht verarbeitet werden.'));
                    bh_settings_redirect($botId, 'profile');
                }
                $payload['banner'] = (string)$img['data_uri'];
            }
        }

        if ($payload === []) {
            bh_settings_set_flash('err', 'Bitte Name, Avatar und/oder Banner angeben.');
            bh_settings_redirect($botId, 'profile');
        }

        $res = discord_api_request_bot((string)$tokenData['token'], 'PATCH', '/users/@me', $payload);
        if (!$res['ok']) {
            error_log("Discord Profile Update Error for Bot $botId: " . json_encode($res['error'] ?? 'unknown'));
            bh_settings_set_flash('err', 'Discord konnte das Profil nicht aktualisieren. Bitte prüfe den Bot-Token.');
            bh_settings_redirect($botId, 'profile');
        }

        bh_settings_set_flash('ok', 'Discord Profil wurde aktualisiert.');
        bh_settings_redirect($botId, 'profile');
    }

    if ($action === 'set_bot_online' || $action === 'set_bot_offline') {
        if ($botId <= 0) {
            bh_settings_set_flash('err', 'Ungültiger Bot.');
            bh_settings_redirect(null, 'status');
        }

        $row = bh_settings_fetch_bot_row($userId, $botId);
        if ($row === null) {
            bh_settings_set_flash('err', 'Bot nicht gefunden oder keine Berechtigung.');
            bh_settings_redirect($botId, 'status');
        }

        $desiredState = $action === 'set_bot_online' ? 'running' : 'stopped';
        $updated = bh_settings_update_desired_state($userId, $botId, $desiredState);

        if (!$updated) {
            bh_settings_set_flash('err', 'Bot Status konnte nicht gespeichert werden.');
            bh_settings_redirect($botId, 'status');
        }

        bh_settings_set_flash('ok', $action === 'set_bot_online' ? 'Bot wurde auf Online gesetzt.' : 'Bot wurde auf Offline gesetzt.');
        bh_settings_redirect($botId, 'status');
    }

    if ($action === 'update_token') {
        if ($botId <= 0) {
            bh_settings_set_flash('err', 'Ungültiger Bot.');
            bh_settings_redirect(null, 'profile');
        }

        $row = bh_settings_fetch_bot_row($userId, $botId);
        if ($row === null) {
            bh_settings_set_flash('err', 'Bot nicht gefunden oder keine Berechtigung.');
            bh_settings_redirect($botId, 'profile');
        }

        $newToken = trim((string)($_POST['new_token'] ?? ''));
        if ($newToken === '') {
            bh_settings_set_flash('err', 'Bitte einen Bot-Token eingeben.');
            bh_settings_redirect($botId, 'profile');
        }

        // Verify the token against Discord before saving
        $verifyRes = discord_api_get_me($newToken);
        if (!$verifyRes['ok']) {
            bh_settings_set_flash('err', 'Token ungültig oder von Discord abgelehnt: ' . (string)($verifyRes['error'] ?? 'Unbekannter Fehler'));
            bh_settings_redirect($botId, 'profile');
        }

        try {
            $tokenEnc = bh_bot_token_encrypt($newToken);
        } catch (Throwable $e) {
            bh_settings_set_flash('err', 'Token konnte nicht verschlüsselt werden: ' . $e->getMessage());
            bh_settings_redirect($botId, 'profile');
        }

        $pdo = bh_get_pdo();
        $stmt = $pdo->prepare(
            'UPDATE bot_instances
             SET bot_token_encrypted = :enc,
                 bot_token_enc_meta  = :meta,
                 desired_state       = \'stopped\',
                 runtime_status      = \'stopped\',
                 last_error          = NULL,
                 updated_at          = NOW()
             WHERE id = :id
               AND owner_user_id = :uid
             LIMIT 1'
        );
        $stmt->execute([
            ':enc'  => $tokenEnc['encrypted'],
            ':meta' => $tokenEnc['meta'],
            ':id'   => $botId,
            ':uid'  => $userId,
        ]);

        if ($stmt->rowCount() === 0) {
            bh_settings_set_flash('err', 'Token konnte nicht gespeichert werden.');
            bh_settings_redirect($botId, 'profile');
        }

        bh_settings_set_flash('ok', 'Bot-Token wurde aktualisiert. Bot kann jetzt neu gestartet werden.');
        bh_settings_redirect($botId, 'profile');
    }

    if ($action === 'delete_bot') {
        if ($botId <= 0) {
            bh_settings_set_flash('err', 'Ungültiger Bot.');
            bh_settings_redirect(null, 'profile');
        }

        $confirmDelete = trim((string)($_POST['confirm_delete'] ?? ''));
        if ($confirmDelete !== 'DELETE') {
            bh_settings_set_flash('err', 'Bitte DELETE zur Bestätigung eingeben.');
            bh_settings_redirect($botId, 'profile');
        }

        $row = bh_settings_fetch_bot_row($userId, $botId);
        if ($row === null) {
            bh_settings_set_flash('err', 'Bot nicht gefunden oder keine Berechtigung.');
            bh_settings_redirect($botId, 'profile');
        }

        $deleted = bh_settings_delete_bot($userId, $botId);
        if (!$deleted) {
            bh_settings_set_flash('err', 'Bot konnte nicht gelöscht werden.');
            bh_settings_redirect($botId, 'profile');
        }

        bh_settings_set_flash('ok', 'Bot wurde gelöscht.');
        header('Location: /dashboard', true, 302);
        exit;
    }
}

$discordName = null;
$discordUserId = null;
$discordAvatarHash = null;
$discordAvatarUrl = null;
$discordBannerHash = null;
$discordBannerUrl = null;
$discordError = null;
$tokenHint = null;

$desiredState = 'stopped';
$runtimeStatus = 'offline';
$lastError = null;
$lastStartedAt = null;
$lastStoppedAt = null;
$isBotActive = false;

if ($currentBotId !== null && $currentBotId > 0) {
    $botRow = bh_settings_fetch_bot_row($userId, $currentBotId);

    if ($botRow === null) {
        $discordError = 'Bot nicht gefunden oder keine Berechtigung.';
    } else {
        $desiredState = (string)($botRow['desired_state'] ?? 'stopped');
        $runtimeStatus = (string)($botRow['runtime_status'] ?? 'offline');
        $lastError = isset($botRow['last_error']) ? (string)$botRow['last_error'] : null;
        $lastStartedAt = isset($botRow['last_started_at']) ? (string)$botRow['last_started_at'] : null;
        $lastStoppedAt = isset($botRow['last_stopped_at']) ? (string)$botRow['last_stopped_at'] : null;
        $isBotActive = (int)($botRow['is_active'] ?? 0) === 1;

        $discordUserId = isset($botRow['discord_bot_user_id']) ? (string)$botRow['discord_bot_user_id'] : null;

        $tokenData = bh_settings_try_get_plain_bot_token($botRow);
        if (!$tokenData['ok']) {
            $tokenHint = (string)($tokenData['hint'] ?? null);
        } else {
            $res = discord_api_get_me((string)$tokenData['token']);
            if (!$res['ok']) {
                $discordError = (string)($res['error'] ?? 'Discord API Fehler');
            } else {
                $data = $res['data'] ?? null;
                if (is_array($data)) {
                    $discordUserId    = (string)($data['id'] ?? $discordUserId ?? '');
                    $username         = (string)($data['username'] ?? '');
                    $globalName       = (string)($data['global_name'] ?? '');
                    $discordName      = $globalName !== '' ? $globalName : $username;
                    $discordAvatarHash = (string)($data['avatar'] ?? '');
                    $discordAvatarUrl  = bh_settings_discord_avatar_url($discordUserId, $discordAvatarHash);
                    $discordBannerHash = isset($data['banner']) && $data['banner'] !== null ? (string)$data['banner'] : null;
                    $discordBannerUrl  = bh_settings_discord_banner_url($discordUserId, $discordBannerHash);
                }
            }
        }
    }
}

$displayName = (string)($discordName ?? $botName ?? '—');
$displayUserId = (string)($discordUserId ?? '—');
$statusLabel = bh_settings_status_label($runtimeStatus, $desiredState);
$statusBadgeClass = bh_settings_status_badge_class($runtimeStatus, $desiredState);

$baseQuery = [];
if ($currentBotId !== null && $currentBotId > 0) {
    $baseQuery['bot_id'] = (string)$currentBotId;
}

$profileQuery = $baseQuery;
$profileQuery['tab'] = 'profile';

$statusQuery = $baseQuery;
$statusQuery['tab'] = 'status';

$profileTabHref = '/dashboard/settings?' . http_build_query($profileQuery);
$statusTabHref = '/dashboard/settings?' . http_build_query($statusQuery);

$formAction = '/dashboard/settings';
if ($baseQuery !== []) {
    $formAction .= '?' . http_build_query($baseQuery);
}
?>
<link rel="stylesheet" href="/assets/css/_page_settings.css?v=2">

<div class="bh-settings-page">
    <div class="bh-settings-head">
        <div class="bh-settings-kicker">SETTINGS</div>
        <h1 class="bh-settings-title">Bot Settings</h1>
    </div>

    <?php if ($flashOk !== null): ?>
        <div class="bh-settings-alert bh-settings-alert--ok">
            <?= htmlspecialchars($flashOk, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($flashErr !== null): ?>
        <div class="bh-settings-alert bh-settings-alert--err">
            <?= htmlspecialchars($flashErr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($tokenHint !== null): ?>
        <div class="bh-settings-alert bh-settings-alert--warn">
            <?= htmlspecialchars($tokenHint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($discordError !== null): ?>
        <div class="bh-settings-alert bh-settings-alert--err">
            <?= htmlspecialchars($discordError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="bh-settings-tabs">
        <a href="<?= htmlspecialchars($profileTabHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="bh-settings-tab<?= $activeTab === 'profile' ? ' bh-settings-tab--active' : '' ?>">
            Profile
        </a>
        <a href="<?= htmlspecialchars($statusTabHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="bh-settings-tab<?= $activeTab === 'status' ? ' bh-settings-tab--active' : '' ?>">
            Bot Status
        </a>
    </div>

    <?php if ($activeTab === 'profile'): ?>
        <form method="post" enctype="multipart/form-data" action="<?= htmlspecialchars($formAction, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="bh-settings-form">
            <input type="hidden" name="action" value="update_profile">
            <input type="hidden" name="bot_id" value="<?= (int)($currentBotId ?? 0) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">

            <section class="bh-settings-card">
                <div class="bh-settings-card__header">
                    <div>
                        <div class="bh-settings-card__title">Bot Name <span class="bh-settings-info">ⓘ</span></div>
                        <div class="bh-settings-card__text">Your bot's username</div>
                    </div>
                </div>

                <div class="bh-settings-card__body">
                    <label class="bh-settings-label" for="new_name">Username</label>
                    <input
                        id="new_name"
                        name="new_name"
                        type="text"
                        class="bh-settings-input"
                        placeholder="<?= htmlspecialchars($displayName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        maxlength="32"
                        autocomplete="off"
                    >
                </div>
            </section>

            <section class="bh-settings-card">
                <div class="bh-settings-card__header">
                    <div>
                        <div class="bh-settings-card__title">Bot Avatar <span class="bh-settings-info">ⓘ</span></div>
                        <div class="bh-settings-card__text">Your bot's profile picture</div>
                    </div>
                </div>

                <div class="bh-settings-card__body">
                    <div class="bh-settings-avatar-row">
                        <div class="bh-settings-avatar-preview">
                            <?php if (is_string($discordAvatarUrl) && $discordAvatarUrl !== ''): ?>
                                <img src="<?= htmlspecialchars($discordAvatarUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="Bot Avatar">
                            <?php else: ?>
                                <span><?= htmlspecialchars(mb_strtoupper(mb_substr($displayName, 0, 1, 'UTF-8'), 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="bh-settings-avatar-meta">
                            <input
                                id="avatar_file"
                                name="avatar_file"
                                type="file"
                                class="bh-settings-file-input"
                                accept=".png,.jpg,.jpeg,.gif,.webp,image/png,image/jpeg,image/gif,image/webp"
                            >
                            <label for="avatar_file" class="bh-settings-upload-btn">Choose Image</label>
                            <div class="bh-settings-upload-help">Allowed: png, jpg, gif, webp · max. 2 MiB</div>
                            <div class="bh-settings-upload-help">Hinweis: Discord erlaubt max. 2 Avatar-Änderungen pro Stunde.</div>
                            <div class="bh-settings-upload-help">User ID: <?= htmlspecialchars($displayUserId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="bh-settings-card">
                <div class="bh-settings-card__header">
                    <div>
                        <div class="bh-settings-card__title">Bot Banner <span class="bh-settings-info">ⓘ</span></div>
                        <div class="bh-settings-card__text">Your bot's profile banner</div>
                    </div>
                </div>

                <div class="bh-settings-card__body">
                    <?php if (is_string($discordBannerUrl) && $discordBannerUrl !== ''): ?>
                        <div class="bh-settings-banner-preview">
                            <img src="<?= htmlspecialchars($discordBannerUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="Bot Banner">
                        </div>
                    <?php else: ?>
                        <div class="bh-settings-banner-empty">Kein Banner gesetzt</div>
                    <?php endif; ?>

                    <div style="margin-top:12px">
                        <input
                            id="banner_file"
                            name="banner_file"
                            type="file"
                            class="bh-settings-file-input"
                            accept=".png,.jpg,.jpeg,.gif,.webp,image/png,image/jpeg,image/gif,image/webp"
                        >
                        <label for="banner_file" class="bh-settings-upload-btn">Choose Banner</label>
                        <div class="bh-settings-upload-help">Empfohlen: 960×540 px · png, jpg, gif, webp · max. 2 MiB</div>
                    </div>
                </div>
            </section>

            <div class="bh-settings-actions">
                <button type="submit" class="bh-settings-save-btn">Save Changes</button>
            </div>
        </form>

        <form method="post" action="<?= htmlspecialchars($formAction, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="bh-settings-form">
            <input type="hidden" name="action" value="update_token">
            <input type="hidden" name="bot_id" value="<?= (int)($currentBotId ?? 0) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">

            <section class="bh-settings-card">
                <div class="bh-settings-card__header">
                    <div>
                        <div class="bh-settings-card__title">Bot Token</div>
                        <div class="bh-settings-card__text">Token aktualisieren — z. B. nach einem Reset im Discord Developer Portal. Der Bot wird dabei gestoppt.</div>
                    </div>
                </div>

                <div class="bh-settings-card__body">
                    <label class="bh-settings-label" for="new_token">Neuer Bot-Token</label>
                    <input
                        id="new_token"
                        name="new_token"
                        type="password"
                        class="bh-settings-input"
                        placeholder="Neuen Token hier einfügen"
                        autocomplete="off"
                        autocorrect="off"
                        spellcheck="false"
                    >
                    <div class="bh-settings-upload-help" style="margin-top:6px;">Der Token wird vor dem Speichern gegen Discord validiert.</div>
                </div>
            </section>

            <div class="bh-settings-actions">
                <button type="submit" class="bh-settings-save-btn">Token speichern</button>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($activeTab === 'status'): ?>
        <section class="bh-settings-card">
            <div class="bh-settings-card__header">
                <div>
                    <div class="bh-settings-card__title">Bot Status</div>
                    <div class="bh-settings-card__text">Hier kannst du den Bot sichtbar auf Online oder Offline setzen.</div>
                </div>
            </div>

            <div class="bh-settings-card__body">
                <div class="bh-status-panel">
                    <div class="<?= htmlspecialchars($statusBadgeClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </div>

                    <div class="bh-status-meta">
                        <div class="bh-status-row">
                            <span class="bh-status-key">Bot</span>
                            <span class="bh-status-value"><?= htmlspecialchars($displayName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        </div>
                        <div class="bh-status-row">
                            <span class="bh-status-key">Desired State</span>
                            <span class="bh-status-value"><?= htmlspecialchars($desiredState, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        </div>
                        <div class="bh-status-row">
                            <span class="bh-status-key">Runtime Status</span>
                            <span class="bh-status-value"><?= htmlspecialchars($runtimeStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        </div>
                        <div class="bh-status-row">
                            <span class="bh-status-key">Last Started</span>
                            <span class="bh-status-value"><?= htmlspecialchars(bh_settings_format_dt($lastStartedAt), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        </div>
                        <div class="bh-status-row">
                            <span class="bh-status-key">Last Stopped</span>
                            <span class="bh-status-value"><?= htmlspecialchars(bh_settings_format_dt($lastStoppedAt), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        </div>
                    </div>

                    <div class="bh-status-actions">
                        <form method="post" action="<?= htmlspecialchars($formAction, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="set_bot_online">
                            <input type="hidden" name="bot_id" value="<?= (int)($currentBotId ?? 0) ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
                            <button type="submit" class="bh-status-btn bh-status-btn--online">Online</button>
                        </form>

                        <form method="post" action="<?= htmlspecialchars($formAction, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="set_bot_offline">
                            <input type="hidden" name="bot_id" value="<?= (int)($currentBotId ?? 0) ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
                            <button type="submit" class="bh-status-btn bh-status-btn--offline">Offline</button>
                        </form>
                    </div>

                    <?php if ($lastError !== null && trim($lastError) !== ''): ?>
                        <div class="bh-settings-alert bh-settings-alert--err bh-settings-alert--inline">
                            <?= htmlspecialchars($lastError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!$isBotActive): ?>
                        <div class="bh-settings-upload-help">Hinweis: `is_active = 0`.</div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="bh-settings-card bh-settings-card--danger">
        <div class="bh-settings-card__header">
            <div>
                <div class="bh-settings-card__title">Danger Zone</div>
                <div class="bh-settings-card__text">Bot dauerhaft löschen. Diese Aktion kann nicht rückgängig gemacht werden.</div>
            </div>
        </div>

        <div class="bh-settings-card__body">
            <form method="post" action="<?= htmlspecialchars($formAction, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="bh-settings-danger-form">
                <input type="hidden" name="action" value="delete_bot">
                <input type="hidden" name="bot_id" value="<?= (int)($currentBotId ?? 0) ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">

                <label class="bh-settings-label" for="confirm_delete">Tippe DELETE zum Bestätigen</label>

                <div class="bh-settings-danger-row">
                    <input
                        id="confirm_delete"
                        name="confirm_delete"
                        type="text"
                        class="bh-settings-input bh-settings-input--danger"
                        placeholder="DELETE"
                        autocomplete="off"
                    >
                    <button type="submit" class="bh-status-btn bh-status-btn--offline">Bot löschen</button>
                </div>

                <div class="bh-settings-upload-help">Hinweis: Der Bot und die zugehörigen Daten werden entfernt.</div>
            </form>
        </div>
    </section>
</div>