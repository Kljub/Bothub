<?php
declare(strict_types=1);

session_start();

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/functions/admin_guard.php';
require_once $projectRoot . '/functions/html.php';
require_once $projectRoot . '/auth/_db.php';

$adminUser = bh_admin_require_user();
$pageTitle = 'Settings';

$messages    = [];
$formErrors  = [];
$pingInterval = 60;

function bh_admin_settings_ensure(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS admin_settings (
            setting_key   VARCHAR(64)  NOT NULL,
            setting_value TEXT         NULL,
            updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function bh_admin_settings_get(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM admin_settings WHERE setting_key = :k LIMIT 1');
    $stmt->execute([':k' => $key]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return $default;
    }
    $val = $row['setting_value'] ?? null;
    return $val !== null ? (string)$val : $default;
}

function bh_admin_settings_set(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare(
        'INSERT INTO admin_settings (setting_key, setting_value)
         VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP'
    )->execute([':k' => $key, ':v' => $value]);
}

function bh_twitch_config_ensure(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS twitch_app_config (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            config_key   VARCHAR(64)  NOT NULL,
            config_value TEXT         NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_config_key (config_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function bh_twitch_config_get(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT config_value FROM twitch_app_config WHERE config_key = :k LIMIT 1');
    $stmt->execute([':k' => $key]);
    $row = $stmt->fetch();
    return is_array($row) ? (string)($row['config_value'] ?? $default) : $default;
}

function bh_twitch_config_set(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare(
        'INSERT INTO twitch_app_config (config_key, config_value)
         VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
    )->execute([':k' => $key, ':v' => $value]);
}

function bh_twitch_api_test(string $clientId, string $clientSecret): array
{
    if ($clientId === '' || $clientSecret === '') {
        return ['ok' => false, 'error' => 'Keine Credentials gespeichert.'];
    }

    // Step 1: fetch access token
    $tokenUrl  = 'https://id.twitch.tv/oauth2/token';
    $tokenBody = http_build_query([
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'grant_type'    => 'client_credentials',
    ]);

    $ch = curl_init($tokenUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $tokenBody,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $tokenRaw    = curl_exec($ch);
    $tokenStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr     = curl_error($ch);

    if ($curlErr !== '') {
        return ['ok' => false, 'error' => 'cURL Fehler: ' . $curlErr];
    }

    $tokenJson = json_decode((string)$tokenRaw, true);

    if ($tokenStatus !== 200 || !isset($tokenJson['access_token'])) {
        $msg = $tokenJson['message'] ?? ($tokenJson['error'] ?? ('HTTP ' . $tokenStatus));
        return ['ok' => false, 'error' => 'Token-Anfrage fehlgeschlagen: ' . $msg];
    }

    $accessToken = (string)$tokenJson['access_token'];
    $expiresIn   = (int)($tokenJson['expires_in'] ?? 0);

    // Step 2: test API call — fetch info for "twitch" (always exists)
    $ch2 = curl_init('https://api.twitch.tv/helix/users?login=twitch');
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Client-Id: ' . $clientId,
            'Authorization: Bearer ' . $accessToken,
        ],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $usersRaw    = curl_exec($ch2);
    $usersStatus = curl_getinfo($ch2, CURLINFO_HTTP_CODE);

    $usersJson = json_decode((string)$usersRaw, true);

    if ($usersStatus !== 200 || !isset($usersJson['data'])) {
        return ['ok' => false, 'error' => 'Helix /users Anfrage fehlgeschlagen: HTTP ' . $usersStatus];
    }

    $user = $usersJson['data'][0] ?? null;

    return [
        'ok'          => true,
        'token_type'  => $tokenJson['token_type'] ?? 'bearer',
        'expires_in'  => $expiresIn,
        'user_id'     => $user['id']           ?? '—',
        'user_name'   => $user['display_name'] ?? '—',
        'broadcaster' => $user['broadcaster_type'] ?? '—',
    ];
}

try {
    $pdo = bh_pdo();
    bh_admin_settings_ensure($pdo);
    bh_twitch_config_ensure($pdo);

    // ── AJAX: Twitch API Test ─────────────────────────────────────────────────
    $contentType = trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $contentType === 'application/json') {
        while (ob_get_level() > 0) { ob_end_clean(); }
        $raw    = (string)file_get_contents('php://input');
        $data   = json_decode($raw, true);
        $action = (string)($data['action'] ?? '');
        header('Content-Type: application/json; charset=utf-8');

        if ($action === 'twitch_test') {
            $cid  = bh_twitch_config_get($pdo, 'client_id');
            $csec = bh_twitch_config_get($pdo, 'client_secret');
            echo json_encode(bh_twitch_api_test($cid, $csec));
            exit;
        }

        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $hasError = false;

        // Twitch Credentials
        if (isset($_POST['twitch_client_id'])) {
            $twitchClientId     = trim((string)$_POST['twitch_client_id']);
            $twitchClientSecret = trim((string)($_POST['twitch_client_secret'] ?? ''));
            if ($twitchClientId !== '') {
                bh_twitch_config_set($pdo, 'client_id', $twitchClientId);
            }
            if ($twitchClientSecret !== '') {
                bh_twitch_config_set($pdo, 'client_secret', $twitchClientSecret);
            }
        }

        // Core Ping Interval
        if (isset($_POST['core_ping_interval'])) {
            $raw = (int)$_POST['core_ping_interval'];
            if ($raw < 10) {
                $formErrors[] = 'Interval muss mindestens 10 Sekunden sein.';
                $hasError = true;
            } elseif ($raw > 3600) {
                $formErrors[] = 'Interval darf maximal 3600 Sekunden (1 Stunde) sein.';
                $hasError = true;
            } else {
                bh_admin_settings_set($pdo, 'core_ping_interval', (string)$raw);
            }
        }

        // Allow Registration
        if (isset($_POST['allow_registration'])) {
            $reg = $_POST['allow_registration'] === '1' ? '1' : '0';
            bh_admin_settings_set($pdo, 'allow_registration', $reg);
        }

        if (!$hasError) {
            $messages[] = 'Einstellungen gespeichert.';
        }
    }

    $pingInterval = (int)bh_admin_settings_get($pdo, 'core_ping_interval', '60');
    if ($pingInterval < 10) {
        $pingInterval = 60;
    }
    $allowRegistration  = bh_admin_settings_get($pdo, 'allow_registration', '1');
    $twitchClientId     = bh_twitch_config_get($pdo, 'client_id');
    $twitchClientSecret = bh_twitch_config_get($pdo, 'client_secret');
} catch (Throwable $e) {
    $formErrors[] = 'DB Fehler: ' . $e->getMessage();
}

ob_start();
?>
<main class="grow">
    <div class="px-4 sm:px-6 lg:px-8 py-8 w-full max-w-4xl mx-auto">
        <div class="mb-8">
            <h1 class="text-2xl md:text-3xl text-gray-800 dark:text-gray-100 font-bold">
                Settings
            </h1>
            <div class="text-sm text-gray-500 dark:text-gray-400 mt-2">
                Globale Einstellungen für das BotHub Dashboard.
            </div>
        </div>

        <?php foreach ($messages as $msg): ?>
            <div class="mb-4 rounded-xl border border-emerald-200 dark:border-emerald-700/60 bg-emerald-50 dark:bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-300">
                <?= h($msg) ?>
            </div>
        <?php endforeach; ?>

        <?php foreach ($formErrors as $err): ?>
            <div class="mb-4 rounded-xl border border-rose-200 dark:border-rose-700/60 bg-rose-50 dark:bg-rose-500/10 px-4 py-3 text-sm text-rose-700 dark:text-rose-300">
                <?= h($err) ?>
            </div>
        <?php endforeach; ?>

        <form method="post" autocomplete="off">

        <!-- Registration -->
        <div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl mb-6">
            <div class="p-5 border-b border-gray-100 dark:border-gray-700/60">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Registrierung</h2>
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    Steuert ob sich neue Nutzer im Dashboard registrieren können.
                </div>
            </div>
            <div class="p-5">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-gray-700 dark:text-gray-300">
                            Registrierung erlauben
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            Wenn deaktiviert können sich keine neuen Accounts mehr erstellen.
                        </div>
                    </div>
                    <label class="bh-toggle-label">
                        <input type="hidden" name="allow_registration" value="0">
                        <input
                            type="checkbox"
                            name="allow_registration"
                            value="1"
                            class="bh-toggle-input"
                            <?= $allowRegistration === '1' ? 'checked' : '' ?>
                        >
                        <span class="bh-toggle-track">
                            <span class="bh-toggle-thumb"></span>
                        </span>
                    </label>
                    <style>
                    .bh-toggle-label { display:inline-flex; align-items:center; cursor:pointer; }
                    .bh-toggle-input { position:absolute; opacity:0; width:0; height:0; }
                    .bh-toggle-track {
                        position:relative; display:inline-block;
                        width:44px; height:24px; border-radius:12px;
                        background:#d1d5db; transition:background .2s;
                    }
                    .bh-toggle-thumb {
                        position:absolute; top:2px; left:2px;
                        width:20px; height:20px; border-radius:50%;
                        background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.3);
                        transition:transform .2s;
                    }
                    .bh-toggle-input:checked + .bh-toggle-track { background:#8b5cf6; }
                    .bh-toggle-input:checked + .bh-toggle-track .bh-toggle-thumb { transform:translateX(20px); }
                    </style>
                </div>
            </div>
        </div>

        <!-- Core Ping -->
        <div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl mb-6">
            <div class="p-5 border-b border-gray-100 dark:border-gray-700/60">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Core Ping</h2>
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    Wie oft das Dashboard den Core über <code>/health</code> anpingen soll.
                </div>
            </div>
            <div class="p-5">
                <div class="mb-5">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Ping-Interval (Sekunden)
                    </label>
                    <input
                        type="number"
                        name="core_ping_interval"
                        value="<?= (int)$pingInterval ?>"
                        min="10"
                        max="3600"
                        step="5"
                        class="form-input w-48 bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-700 text-gray-900 dark:text-gray-100"
                        required
                    >
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                        Minimum: 10 s · Maximum: 3600 s (1 h) · Standard: 60 s
                    </div>
                </div>
            </div>
        </div>

        <!-- Twitch API Credentials -->
        <div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl mb-6">
            <div class="p-5 border-b border-gray-100 dark:border-gray-700/60">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Twitch API Credentials</h2>
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    Globale Twitch App-Zugangsdaten für Twitch Notifications — gültig für alle Bots.
                    Erstelle eine App unter <a href="https://dev.twitch.tv/console/apps" target="_blank" rel="noopener noreferrer" class="text-violet-500 hover:underline">dev.twitch.tv</a>.
                </div>
            </div>
            <div class="p-5">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5" for="twitch_client_id">
                            Client ID
                        </label>
                        <input
                            type="text"
                            id="twitch_client_id"
                            name="twitch_client_id"
                            value="<?= htmlspecialchars($twitchClientId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                            placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                            autocomplete="off"
                            class="form-input w-full bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-700 text-gray-900 dark:text-gray-100 font-mono text-sm"
                        >
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5" for="twitch_client_secret">
                            Client Secret
                            <?php if ($twitchClientSecret !== ''): ?>
                                <span class="ml-1 text-emerald-500 font-normal">(gespeichert)</span>
                            <?php endif; ?>
                        </label>
                        <input
                            type="password"
                            id="twitch_client_secret"
                            name="twitch_client_secret"
                            value=""
                            placeholder="<?= $twitchClientSecret !== '' ? '••••••••••••••••' : 'Client Secret eingeben' ?>"
                            autocomplete="new-password"
                            class="form-input w-full bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-700 text-gray-900 dark:text-gray-100"
                        >
                        <div class="text-xs text-gray-400 dark:text-gray-500 mt-1">Leer lassen um das Secret nicht zu ändern.</div>
                    </div>
                </div>

                <!-- API Test -->
                <div class="border-t border-gray-100 dark:border-gray-700/60 pt-4">
                    <div class="flex items-center gap-3 flex-wrap">
                        <button type="button" id="twitch-test-btn" onclick="bhTwitchTest()"
                            class="inline-flex items-center gap-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-800 text-gray-700 dark:text-gray-200 text-xs font-semibold px-3 py-2 transition-colors">
                            <svg id="twitch-test-spinner" xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16" style="display:none" class="animate-spin">
                                <path d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2v1z"/>
                                <path d="M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466z"/>
                            </svg>
                            <svg id="twitch-test-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M9 5a.5.5 0 0 0-1 0v3H5a.5.5 0 0 0 0 1h3.5a.5.5 0 0 0 .5-.5V5z"/>
                                <path d="M12.854.146a.5.5 0 0 0-.707 0L10.5 1.793 14.207 5.5l1.647-1.646a.5.5 0 0 0 0-.708l-3-3zm.646 6.061L9.793 2.5 3.293 9H3.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.207l6.5-6.5zm-7.468 7.468A.5.5 0 0 1 6 13.5V13h-.5a.5.5 0 0 1-.5-.5V12h-.5a.5.5 0 0 1-.5-.5V11h-.5a.5.5 0 0 1-.5-.5V10h-.5a.499.499 0 0 1-.175-.032l-.179.178a.5.5 0 0 0-.11.168l-2 5a.5.5 0 0 0 .65.65l5-2a.5.5 0 0 0 .168-.11l.178-.178z"/>
                            </svg>
                            API testen
                        </button>
                        <span class="text-xs text-gray-400 dark:text-gray-500">Testet Token-Anfrage + Helix API mit den gespeicherten Credentials.</span>
                    </div>

                    <div id="twitch-test-result" class="mt-3" style="display:none">
                        <!-- filled by JS -->
                    </div>
                </div>
            </div>
        </div>

        <button type="submit" class="btn bg-violet-500 hover:bg-violet-600 text-white">
            Speichern
        </button>

        </form>
    </div>
</main>
<script>
(function () {
    async function bhTwitchTest() {
        const btn     = document.getElementById('twitch-test-btn');
        const spinner = document.getElementById('twitch-test-spinner');
        const icon    = document.getElementById('twitch-test-icon');
        const result  = document.getElementById('twitch-test-result');

        btn.disabled    = true;
        spinner.style.display = '';
        icon.style.display    = 'none';
        result.style.display  = 'none';
        result.innerHTML      = '';

        try {
            const res  = await fetch(location.href, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ action: 'twitch_test' }),
            });
            const json = await res.json();

            if (json.ok) {
                const expiresHours = json.expires_in ? Math.round(json.expires_in / 3600) : '?';
                result.innerHTML = `
                    <div class="rounded-xl border border-emerald-200 dark:border-emerald-700/60 bg-emerald-50 dark:bg-emerald-500/10 px-4 py-3 text-xs text-emerald-700 dark:text-emerald-300">
                        <div class="font-semibold mb-2">✓ API funktioniert</div>
                        <div class="grid grid-cols-2 gap-x-6 gap-y-1 font-mono">
                            <span class="text-emerald-600 dark:text-emerald-400">Token Typ</span>
                            <span>${escHtml(json.token_type)}</span>
                            <span class="text-emerald-600 dark:text-emerald-400">Gültig für</span>
                            <span>${expiresHours}h (${json.expires_in}s)</span>
                            <span class="text-emerald-600 dark:text-emerald-400">Test-User</span>
                            <span>${escHtml(json.user_name)} (ID: ${escHtml(json.user_id)})</span>
                            <span class="text-emerald-600 dark:text-emerald-400">Broadcaster-Typ</span>
                            <span>${escHtml(json.broadcaster)}</span>
                        </div>
                    </div>`;
            } else {
                result.innerHTML = `
                    <div class="rounded-xl border border-rose-200 dark:border-rose-700/60 bg-rose-50 dark:bg-rose-500/10 px-4 py-3 text-xs text-rose-700 dark:text-rose-300">
                        <div class="font-semibold mb-1">✗ Test fehlgeschlagen</div>
                        <div>${escHtml(json.error || 'Unbekannter Fehler')}</div>
                    </div>`;
            }
        } catch (e) {
            result.innerHTML = `
                <div class="rounded-xl border border-rose-200 dark:border-rose-700/60 bg-rose-50 dark:bg-rose-500/10 px-4 py-3 text-xs text-rose-700 dark:text-rose-300">
                    <div class="font-semibold mb-1">✗ Netzwerkfehler</div>
                    <div>${escHtml(e.message || String(e))}</div>
                </div>`;
        } finally {
            btn.disabled          = false;
            spinner.style.display = 'none';
            icon.style.display    = '';
            result.style.display  = '';
        }
    }

    function escHtml(str) {
        return String(str ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    window.bhTwitchTest = bhTwitchTest;
}());
</script>
<?php
$contentHtml = (string)ob_get_clean();
require __DIR__ . '/_layout.php';
