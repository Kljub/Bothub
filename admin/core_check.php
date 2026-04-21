<?php
declare(strict_types=1);

session_start();

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/functions/admin_guard.php';
require_once $projectRoot . '/functions/html.php';

$adminUser = bh_admin_require_user();
$pageTitle = 'Core Check';

$dashboardStatusOk = false;
$dashboardStatusCode = 0;
$dashboardResponseBody = '';
$dashboardError = null;
$dashboardCheckedUrl = '';

$manualStatusOk = false;
$manualStatusCode = 0;
$manualResponseBody = '';
$manualError = null;
$manualCheckedUrl = '';
$manualEndpoint = '';

try {
    $secretPath = $projectRoot . '/db/config/secret.php';
    if (!is_file($secretPath) || !is_readable($secretPath)) {
        throw new RuntimeException('Secret-Datei nicht gefunden: ' . $secretPath);
    }

    $secretCfg = require $secretPath;
    if (!is_array($secretCfg)) {
        throw new RuntimeException('Secret-Datei ist ungültig.');
    }

    $appKey = trim((string)($secretCfg['APP_KEY'] ?? ''));
    if ($appKey === '') {
        throw new RuntimeException('APP_KEY fehlt in /db/config/secret.php');
    }

    $appCfgPath = $projectRoot . '/db/config/app.php';
    if (!is_file($appCfgPath) || !is_readable($appCfgPath)) {
        throw new RuntimeException('App-Config nicht gefunden: ' . $appCfgPath);
    }

    $appCfg = require $appCfgPath;
    if (!is_array($appCfg)) {
        throw new RuntimeException('App-Config ist ungültig.');
    }

    $baseUrl = trim((string)($appCfg['base_url'] ?? 'http://localhost'));
    if ($baseUrl === '') {
        $baseUrl = 'http://localhost';
    }

    $baseUrl = rtrim($baseUrl, '/');
    $dashboardCheckedUrl = $baseUrl . '/api/v1/core_ping.php';

    $urlParts = parse_url($dashboardCheckedUrl);
    if (!is_array($urlParts)) {
        throw new RuntimeException('Core-Check URL ist ungültig: ' . $dashboardCheckedUrl);
    }

    $scheme = strtolower((string)($urlParts['scheme'] ?? 'http'));
    $host = trim((string)($urlParts['host'] ?? ''));
    $port = isset($urlParts['port']) ? (int)$urlParts['port'] : ($scheme === 'https' ? 443 : 80);

    if ($host === '') {
        throw new RuntimeException('Host konnte aus der Dashboard-Check URL nicht gelesen werden.');
    }

    $ch = curl_init($dashboardCheckedUrl);
    if ($ch === false) {
        throw new RuntimeException('curl_init fehlgeschlagen.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $appKey,
            'Accept: application/json',
        ],
        CURLOPT_RESOLVE => [
            $host . ':' . $port . ':127.0.0.1',
        ],
    ]);

    $raw = curl_exec($ch);
    $dashboardStatusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($raw === false) {
        $curlError = curl_error($ch);
        throw new RuntimeException('Request fehlgeschlagen: ' . $curlError);
    }

    $dashboardResponseBody = trim((string)$raw);
    $dashboardStatusOk = ($dashboardStatusCode >= 200 && $dashboardStatusCode < 300);
} catch (Throwable $e) {
    $dashboardError = $e->getMessage();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $manualEndpoint = trim((string)($_POST['core_endpoint'] ?? ''));
    $manualCheckedUrl = $manualEndpoint;

    if ($manualEndpoint === '') {
        $manualError = 'Bitte einen Core Endpoint eintragen.';
    } elseif (!preg_match('~^https?://~i', $manualEndpoint)) {
        $manualError = 'Die URL muss mit http:// oder https:// beginnen.';
    } elseif (filter_var($manualEndpoint, FILTER_VALIDATE_URL) === false) {
        $manualError = 'Die eingegebene URL ist ungültig.';
    } else {
        try {
            $ch = curl_init($manualEndpoint);
            if ($ch === false) {
                throw new RuntimeException('curl_init fehlgeschlagen.');
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json, text/plain;q=0.9, */*;q=0.8',
                ],
            ]);

            $raw = curl_exec($ch);
            $manualStatusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($raw === false) {
                $curlError = curl_error($ch);
                throw new RuntimeException('Request fehlgeschlagen: ' . $curlError);
            }

            $manualResponseBody = trim((string)$raw);
            $manualStatusOk = ($manualStatusCode >= 200 && $manualStatusCode < 300);
        } catch (Throwable $e) {
            $manualError = $e->getMessage();
        }
    }
}

ob_start();
?>
<main class="grow">
    <div class="px-4 sm:px-6 lg:px-8 py-8 w-full max-w-7xl mx-auto">
        <div class="mb-8">
            <h1 class="text-2xl md:text-3xl text-gray-800 dark:text-gray-100 font-bold">
                Core Check
            </h1>
            <div class="text-sm text-gray-500 dark:text-gray-400 mt-2">
                Prüft den internen Dashboard-Endpoint und zusätzlich einen frei angegebenen Core Endpoint.
            </div>
        </div>

        <div class="grid grid-cols-12 gap-6 mb-6">
            <div class="col-span-full xl:col-span-4 bg-white dark:bg-gray-800 shadow-xs rounded-xl">
                <div class="p-5">
                    <div class="text-sm font-semibold text-gray-800 dark:text-gray-100">Dashboard Check</div>
                    <div class="mt-3 text-2xl font-bold <?= $dashboardStatusOk ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' ?>">
                        <?= $dashboardStatusOk ? 'OK' : 'Fehler' ?>
                    </div>

                    <div class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                        HTTP Status: <?= (int)$dashboardStatusCode ?>
                    </div>

                    <?php if ($dashboardCheckedUrl !== ''): ?>
                        <div class="mt-2 text-xs text-gray-500 dark:text-gray-400 break-all">
                            <?= h($dashboardCheckedUrl) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-span-full xl:col-span-8 bg-white dark:bg-gray-800 shadow-xs rounded-xl">
                <div class="p-5 border-b border-gray-100 dark:border-gray-700/60">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Dashboard Antwort</h2>
                </div>

                <div class="p-5">
                    <?php if ($dashboardError !== null): ?>
                        <div class="mb-4 rounded-xl border border-rose-200 dark:border-rose-700/60 bg-rose-50 dark:bg-rose-500/10 px-4 py-3 text-sm text-rose-700 dark:text-rose-300">
                            <?= h($dashboardError) ?>
                        </div>
                    <?php endif; ?>

                    <pre class="text-xs whitespace-pre-wrap break-words bg-gray-50 dark:bg-gray-900/40 rounded-lg p-4 text-gray-700 dark:text-gray-200"><?= h($dashboardResponseBody !== '' ? $dashboardResponseBody : 'Keine Antwort') ?></pre>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-12 gap-6">
            <div class="col-span-full xl:col-span-5 bg-white dark:bg-gray-800 shadow-xs rounded-xl">
                <div class="p-5 border-b border-gray-100 dark:border-gray-700/60">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Manueller Core Endpoint</h2>
                </div>

                <div class="p-5">
                    <form method="post" autocomplete="off">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Core Endpoint URL
                            </label>
                            <input
                                type="text"
                                name="core_endpoint"
                                value="<?= h($manualEndpoint) ?>"
                                class="form-input w-full bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-700 text-gray-900 dark:text-gray-100"
                                placeholder="http://localhost:3000/ping"
                                required
                            >
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                                Beispiel: http://localhost:3000/ping
                            </div>
                        </div>

                        <button
                            type="submit"
                            class="btn bg-violet-500 hover:bg-violet-600 text-white"
                        >
                            Endpoint prüfen
                        </button>
                    </form>

                    <?php if ($manualCheckedUrl !== ''): ?>
                        <div class="mt-5">
                            <div class="text-sm font-semibold text-gray-800 dark:text-gray-100">Letzter Check</div>
                            <div class="mt-2 text-xs text-gray-500 dark:text-gray-400 break-all">
                                <?= h($manualCheckedUrl) ?>
                            </div>
                            <div class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                HTTP Status: <?= (int)$manualStatusCode ?>
                            </div>
                            <div class="mt-2 text-sm font-semibold <?= $manualStatusOk ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' ?>">
                                <?= $manualStatusOk ? 'OK' : 'Fehler' ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-span-full xl:col-span-7 bg-white dark:bg-gray-800 shadow-xs rounded-xl">
                <div class="p-5 border-b border-gray-100 dark:border-gray-700/60">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Endpoint Antwort</h2>
                </div>

                <div class="p-5">
                    <?php if ($manualError !== null): ?>
                        <div class="mb-4 rounded-xl border border-rose-200 dark:border-rose-700/60 bg-rose-50 dark:bg-rose-500/10 px-4 py-3 text-sm text-rose-700 dark:text-rose-300">
                            <?= h($manualError) ?>
                        </div>
                    <?php endif; ?>

                    <pre class="text-xs whitespace-pre-wrap break-words bg-gray-50 dark:bg-gray-900/40 rounded-lg p-4 text-gray-700 dark:text-gray-200"><?= h($manualResponseBody !== '' ? $manualResponseBody : 'Noch kein manueller Endpoint geprüft.') ?></pre>
                </div>
            </div>
        </div>
    </div>
</main>
<?php
$contentHtml = (string)ob_get_clean();

require __DIR__ . '/_layout.php';