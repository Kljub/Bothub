<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo    = bh_get_pdo();
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    echo '<p class="text-red-500">Nicht angemeldet.</p>';
    return;
}

// ── Ensure tables ─────────────────────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS system_youtube_token (
        id            INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
        access_token  TEXT           NOT NULL,
        refresh_token VARCHAR(2048)  NOT NULL DEFAULT '',
        expires_at    DATETIME       NOT NULL,
        email         VARCHAR(255)   NOT NULL DEFAULT '',
        created_at    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$pdo->exec("
    INSERT IGNORE INTO admin_settings (setting_key, setting_value) VALUES
        ('google_oauth_client_id',     ''),
        ('google_oauth_client_secret', '')
");

// ── Helpers ───────────────────────────────────────────────────────────────────
function yt_get_setting(PDO $pdo, string $key): string
{
    $s = $pdo->prepare('SELECT setting_value FROM admin_settings WHERE setting_key = ?');
    $s->execute([$key]);
    return (string)($s->fetchColumn() ?? '');
}

function yt_set_setting(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare('INSERT INTO admin_settings (setting_key, setting_value) VALUES (?,?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([$key, $value]);
}

function yt_google_auth_url(string $clientId, string $redirectUri, string $state): string
{
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => $clientId,
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'scope'         => 'openid email https://www.googleapis.com/auth/youtube',
        'access_type'   => 'offline',
        'prompt'        => 'consent',
        'state'         => $state,
    ]);
}

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$baseUrl    = rtrim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'), '/');
$scheme     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$redirectUri = $scheme . '://' . $baseUrl . '/dashboard?view=youtube-auth';

// ── AJAX: save credentials ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'save_creds' && $isAjax) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $clientId     = trim((string)($_POST['client_id']     ?? ''));
    $clientSecret = trim((string)($_POST['client_secret'] ?? ''));

    if ($clientId === '' || $clientSecret === '') {
        echo json_encode(['ok' => false, 'error' => 'client_id und client_secret sind Pflichtfelder']);
        exit;
    }

    yt_set_setting($pdo, 'google_oauth_client_id', $clientId);
    yt_set_setting($pdo, 'google_oauth_client_secret', $clientSecret);
    echo json_encode(['ok' => true]);
    exit;
}

// ── AJAX: disconnect ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'disconnect' && $isAjax) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $pdo->exec('DELETE FROM system_youtube_token');
    echo json_encode(['ok' => true]);
    exit;
}

// ── OAuth: start redirect ─────────────────────────────────────────────────────
if (isset($_GET['connect']) && $_GET['connect'] === '1') {
    $clientId = yt_get_setting($pdo, 'google_oauth_client_id');
    if ($clientId === '') {
        $_SESSION['yt_oauth_error'] = 'Bitte zuerst Client ID speichern.';
        header('Location: /dashboard?view=youtube-auth', true, 302);
        exit;
    }

    $state = bin2hex(random_bytes(16));
    $_SESSION['google_oauth_state'] = $state;

    header('Location: ' . yt_google_auth_url($clientId, $redirectUri, $state), true, 302);
    exit;
}

// ── OAuth: handle callback ────────────────────────────────────────────────────
if (isset($_GET['code'])) {
    $oauthError = null;

    do {
        $state         = (string)($_GET['state'] ?? '');
        $expectedState = (string)($_SESSION['google_oauth_state'] ?? '');
        unset($_SESSION['google_oauth_state']);

        if ($state === '' || $state !== $expectedState) {
            $oauthError = 'Ungültiger State-Parameter (CSRF-Schutz).';
            break;
        }

        $clientId     = yt_get_setting($pdo, 'google_oauth_client_id');
        $clientSecret = yt_get_setting($pdo, 'google_oauth_client_secret');

        if ($clientId === '' || $clientSecret === '') {
            $oauthError = 'Google OAuth Credentials fehlen.';
            break;
        }

        $tokenResp = @file_get_contents('https://oauth2.googleapis.com/token', false, stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query([
                    'code'          => (string)$_GET['code'],
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                    'redirect_uri'  => $redirectUri,
                    'grant_type'    => 'authorization_code',
                ]),
            ],
        ]));

        if ($tokenResp === false) {
            $oauthError = 'Token-Austausch fehlgeschlagen (Netzwerkfehler).';
            break;
        }

        $tokenData = json_decode($tokenResp, true);
        if (!is_array($tokenData) || empty($tokenData['access_token'])) {
            $oauthError = 'Kein Access Token erhalten: ' . ($tokenData['error_description'] ?? $tokenData['error'] ?? 'unbekannt');
            break;
        }

        // Fetch user email from Google
        $userInfoResp = @file_get_contents('https://www.googleapis.com/oauth2/v3/userinfo', false, stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'Authorization: Bearer ' . $tokenData['access_token'] . "\r\n",
            ],
        ]));
        $userInfo = is_string($userInfoResp) ? json_decode($userInfoResp, true) : [];
        $email = (string)($userInfo['email'] ?? '');

        $expiresAt = date('Y-m-d H:i:s', time() + (int)($tokenData['expires_in'] ?? 3600));

        $pdo->exec('DELETE FROM system_youtube_token');
        $pdo->prepare('INSERT INTO system_youtube_token (access_token, refresh_token, expires_at, email) VALUES (?,?,?,?)')
            ->execute([
                $tokenData['access_token'],
                $tokenData['refresh_token'] ?? '',
                $expiresAt,
                $email,
            ]);

        $_SESSION['yt_oauth_success'] = 'YouTube-Konto verbunden: ' . $email;
    } while (false);

    if ($oauthError !== null) {
        $_SESSION['yt_oauth_error'] = $oauthError;
    }

    header('Location: /dashboard?view=youtube-auth', true, 302);
    exit;
}

// ── Handle Google error callback (e.g. user denied) ──────────────────────────
if (isset($_GET['error'])) {
    $_SESSION['yt_oauth_error'] = 'Autorisierung abgelehnt: ' . htmlspecialchars((string)$_GET['error'], ENT_QUOTES);
    header('Location: /dashboard?view=youtube-auth', true, 302);
    exit;
}

// ── Load current state ────────────────────────────────────────────────────────
$clientId     = yt_get_setting($pdo, 'google_oauth_client_id');
$clientSecret = yt_get_setting($pdo, 'google_oauth_client_secret');

$tokenStmt = $pdo->query('SELECT * FROM system_youtube_token ORDER BY id DESC LIMIT 1');
$token     = $tokenStmt ? ($tokenStmt->fetch() ?: null) : null;
$connected = $token !== null && trim((string)($token['access_token'] ?? '')) !== '';

$successMsg = $_SESSION['yt_oauth_success'] ?? null;
$errorMsg   = $_SESSION['yt_oauth_error']   ?? null;
unset($_SESSION['yt_oauth_success'], $_SESSION['yt_oauth_error']);
?>

<div class="space-y-6">

    <?php if ($successMsg): ?>
    <div class="px-4 py-3 rounded-lg text-sm bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300">
        <?= htmlspecialchars($successMsg, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
    <div class="px-4 py-3 rounded-lg text-sm bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300">
        <?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <!-- Connection Status -->
    <div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl p-6">
        <div class="text-xs font-semibold text-violet-500 uppercase tracking-wider mb-1">YouTube</div>
        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">Google-Konto Verknüpfung</h2>

        <?php if ($connected): ?>
        <div class="flex items-center gap-3 mb-4">
            <div class="w-3 h-3 rounded-full bg-green-500"></div>
            <div>
                <div class="text-sm font-medium text-gray-800 dark:text-gray-100">Verbunden</div>
                <div class="text-xs text-gray-500 dark:text-gray-400"><?= htmlspecialchars((string)($token['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                <?php
                $exp = strtotime((string)($token['expires_at'] ?? ''));
                if ($exp) {
                    $expLabel = $exp > time() ? 'Token gültig bis ' . date('d.m.Y H:i', $exp) : 'Token abgelaufen – wird automatisch erneuert';
                    echo '<div class="text-xs text-gray-400 dark:text-gray-500">' . htmlspecialchars($expLabel, ENT_QUOTES, 'UTF-8') . '</div>';
                }
                ?>
            </div>
        </div>
        <button type="button" id="yt-disconnect-btn"
            class="btn bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-medium">
            Verbindung trennen
        </button>
        <?php else: ?>
        <div class="flex items-center gap-3 mb-4">
            <div class="w-3 h-3 rounded-full bg-gray-400"></div>
            <div class="text-sm text-gray-500 dark:text-gray-400">Kein Google-Konto verbunden</div>
        </div>
        <a href="/dashboard?view=youtube-auth&connect=1"
            class="inline-flex items-center gap-2 btn bg-violet-500 hover:bg-violet-600 text-white px-5 py-2 rounded-lg text-sm font-medium">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#fff"/>
                <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#fff"/>
                <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#fff"/>
                <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#fff"/>
            </svg>
            Mit Google anmelden
        </a>
        <?php endif; ?>
    </div>

    <!-- Google OAuth Credentials -->
    <div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl p-6">
        <div class="text-xs font-semibold text-violet-500 uppercase tracking-wider mb-1">Konfiguration</div>
        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-2">Google OAuth Credentials</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">
            Erstelle ein Projekt in der
            <a href="https://console.cloud.google.com/" target="_blank" rel="noopener" class="text-violet-500 hover:underline">Google Cloud Console</a>,
            aktiviere die <strong>YouTube Data API v3</strong> und erstelle OAuth 2.0-Zugangsdaten (Typ: Webanwendung).
            Trage als autorisierten Redirect-URI ein:<br>
            <code class="text-xs bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded mt-1 inline-block select-all">
                <?= htmlspecialchars($redirectUri, ENT_QUOTES, 'UTF-8') ?>
            </code>
        </p>

        <div id="creds-toast" class="hidden px-4 py-3 rounded-lg text-sm mb-4"></div>

        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Client ID</label>
                <input type="text" id="yt-client-id"
                    value="<?= htmlspecialchars($clientId, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="123456789-xxxx.apps.googleusercontent.com"
                    class="w-full form-input bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-800 dark:text-gray-100 px-3 py-2 focus:ring-2 focus:ring-violet-500 focus:border-violet-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Client Secret</label>
                <input type="password" id="yt-client-secret"
                    value="<?= htmlspecialchars($clientSecret, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="GOCSPX-…"
                    class="w-full form-input bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-800 dark:text-gray-100 px-3 py-2 focus:ring-2 focus:ring-violet-500 focus:border-violet-500">
            </div>
            <div class="pt-1">
                <button type="button" id="yt-save-creds-btn"
                    class="btn bg-violet-500 hover:bg-violet-600 text-white px-5 py-2 rounded-lg text-sm font-medium">
                    Speichern
                </button>
            </div>
        </div>
    </div>

    <!-- How it works -->
    <div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl p-6">
        <div class="text-xs font-semibold text-violet-500 uppercase tracking-wider mb-1">Info</div>
        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-3">Wie funktioniert das?</h2>
        <ol class="list-decimal list-inside space-y-2 text-sm text-gray-600 dark:text-gray-400">
            <li>Erstelle ein Google Cloud-Projekt und aktiviere die <strong>YouTube Data API v3</strong>.</li>
            <li>Erstelle OAuth 2.0-Zugangsdaten (Typ: <em>Webanwendung</em>) und trage die Redirect-URI ein.</li>
            <li>Speichere Client ID und Secret oben.</li>
            <li>Klicke auf <em>Mit Google anmelden</em> und melde dich mit deinem Google/Gmail-Konto an.</li>
            <li>Der Music-Bot nutzt das gespeicherte Token automatisch für YouTube-Streaming (altersgeschützte Videos, DAVE-Auth).</li>
        </ol>
        <p class="text-xs text-gray-400 dark:text-gray-500 mt-3">
            Hinweis: Halte die App im Google Cloud-Projekt im Status "Testen" und füge deine E-Mail-Adresse als Testnutzer hinzu.
        </p>
    </div>
</div>

<script>
(function () {
    var url = '/dashboard?view=youtube-auth';

    var saveCredsBtn = document.getElementById('yt-save-creds-btn');
    if (saveCredsBtn) {
        saveCredsBtn.addEventListener('click', async function () {
            var clientId     = document.getElementById('yt-client-id').value.trim();
            var clientSecret = document.getElementById('yt-client-secret').value.trim();
            var res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: new URLSearchParams({ _action: 'save_creds', client_id: clientId, client_secret: clientSecret })
            });
            var data = await res.json();
            var toast = document.getElementById('creds-toast');
            toast.textContent = data.ok ? 'Credentials gespeichert.' : ('Fehler: ' + (data.error || 'Unbekannt'));
            toast.className = 'px-4 py-3 rounded-lg text-sm mb-4 ' + (data.ok
                ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300'
                : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300');
            clearTimeout(toast._t);
            toast._t = setTimeout(function () { toast.className = 'hidden'; }, 3000);
        });
    }

    var disconnectBtn = document.getElementById('yt-disconnect-btn');
    if (disconnectBtn) {
        disconnectBtn.addEventListener('click', async function () {
            if (!confirm('Verbindung wirklich trennen?')) return;
            await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: new URLSearchParams({ _action: 'disconnect' })
            });
            window.location.reload();
        });
    }
}());
</script>
