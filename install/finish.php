<?php
declare(strict_types=1);

session_start();

$projectRoot = dirname(__DIR__); // Dynamische Ermittlung des Projekt-Stammverzeichnisses
require_once $projectRoot . '/functions/html.php';

$cfgDir = $projectRoot . '/db/config';
$lockPath = $cfgDir . '/install.lock';
$appCfgPath = $cfgDir . '/app.php';
$secretCfgPath = $cfgDir . '/secret.php';

if (is_file($lockPath)) {
    http_response_code(404);
    echo '404 Not Found';
    exit;
}

if (!is_dir($cfgDir)) {
    http_response_code(500);
    echo 'Config-Ordner fehlt: ' . h($cfgDir);
    exit;
}

$db = $_SESSION['installer_db'] ?? null;
if (!is_array($db)) {
    http_response_code(500);
    echo 'DB-Credentials fehlen in der Session (installer_db). Bitte gehe zurück zu Step "DB installieren".';
    exit;
}

$app = $_SESSION['installer_app'] ?? null;
if (!is_array($app)) {
    http_response_code(500);
    echo 'App-Konfiguration fehlt in der Session (installer_app). Bitte gehe zurück zu Step "DB installieren".';
    exit;
}

foreach (['host', 'port', 'dbname', 'user', 'pass'] as $k) {
    if (!array_key_exists($k, $db)) {
        http_response_code(500);
        echo 'DB-Credentials unvollständig. Fehlender Key: ' . h($k);
        exit;
    }
}

if (!array_key_exists('base_url', $app)) {
    http_response_code(500);
    echo 'App-Konfiguration unvollständig. Fehlender Key: base_url';
    exit;
}

$baseUrl = trim((string)$app['base_url']);
if ($baseUrl === '') {
    http_response_code(500);
    echo 'Website URL ist leer.';
    exit;
}

$appCfg = "<?php\n";
$appCfg .= "declare(strict_types=1);\n";
$appCfg .= "\n";
$appCfg .= "return [\n";
$appCfg .= "    'db' => [\n";
$appCfg .= "        'host' => " . var_export((string)$db['host'], true) . ",\n";
$appCfg .= "        'port' => " . var_export((string)$db['port'], true) . ",\n";
$appCfg .= "        'name' => " . var_export((string)$db['dbname'], true) . ",\n";
$appCfg .= "        'user' => " . var_export((string)$db['user'], true) . ",\n";
$appCfg .= "        'pass' => " . var_export((string)$db['pass'], true) . ",\n";
$appCfg .= "    ],\n";
$appCfg .= "    'base_url' => " . var_export($baseUrl, true) . ",\n";
$appCfg .= "];\n";

$appOk = file_put_contents($appCfgPath, $appCfg, LOCK_EX);
if ($appOk === false) {
    http_response_code(500);
    echo 'Konnte app.php nicht schreiben: ' . h($appCfgPath);
    exit;
}

$data = [
    'installed_at' => gmdate('c'),
    'schema' => 'db_v1',
    'note' => 'BotHub Installer lock',
];

$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    $json = '{"installed_at":"' . gmdate('c') . '","schema":"db_v1"}';
}

$ok = file_put_contents($lockPath, $json . "\n", LOCK_EX);
if ($ok === false) {
    http_response_code(500);
    echo 'Konnte install.lock nicht schreiben: ' . h($lockPath);
    exit;
}

$_SESSION['installer_can_download_core'] = true;

unset($_SESSION['installer_db']);
unset($_SESSION['installer_app']);
unset($_SESSION['installer_secret_path']);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>BotHub Installer – Finish</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/installer.css">
</head>
<body>

<div class="page">
    <div class="container">
        <div class="card">

            <div class="card-header">
                <div class="badge">
                    <span class="badge-dot"></span>
                    Installer • Completed
                </div>

                <h1>Installation abgeschlossen</h1>
                <p class="sub">
                    Config + Lock-Datei wurden geschrieben. Der Installer ist ab jetzt gesperrt, der Core-Download bleibt verfügbar.
                </p>
            </div>

            <div class="card-body">
                <div class="alert alert-success">
                    <strong>Done.</strong> Installation erfolgreich abgeschlossen.
                </div>

                <div class="sep"></div>

                <div class="field" style="margin-top: 14px;">
                    <div class="label">Website URL</div>
                    <div class="code"><?= h($baseUrl) ?></div>
                </div>

                <div class="field" style="margin-top: 14px;">
                    <div class="label">Config-Datei</div>
                    <div class="code"><?= h($appCfgPath) ?></div>
                </div>

                <div class="field" style="margin-top: 14px;">
                    <div class="label">Lock-Datei</div>
                    <div class="code"><?= h($lockPath) ?></div>
                </div>

                <div class="field" style="margin-top: 14px;">
                    <div class="label">Secret-Datei</div>
                    <div class="code"><?= h($secretCfgPath) ?></div>
                </div>

                <div class="field" style="margin-top: 14px;">
                    <div class="label">Lock Inhalt</div>
                    <div class="code"><?= h($json) ?></div>
                </div>

                <div class="actions">
                    <a class="btn" href="/install/download_core.php">Core.zip herunterladen →</a>
                    <a class="btn" id="btn-dashboard" href="/dashboard">Zum Dashboard →</a>
                </div>

                <div class="mini" style="margin-top: 12px;">
                    Weiterleitung zum Dashboard in <span id="redirect-countdown">10</span> Sekunden...
                </div>

                <script>
                (function () {
                    var count = 10;
                    var el = document.getElementById('redirect-countdown');
                    var iv = setInterval(function () {
                        count--;
                        if (el) el.textContent = count;
                        if (count <= 0) {
                            clearInterval(iv);
                            window.location.href = '/dashboard';
                        }
                    }, 1000);
                    // Cancel redirect if user clicks the core download
                    var dlBtn = document.querySelector('a[href="/install/download_core.php"]');
                    if (dlBtn) dlBtn.addEventListener('click', function () { clearInterval(iv); if (el) el.closest('.mini').style.display = 'none'; });
                }());
                </script>

                <div class="mini">
                    Der Core-Download liest serverseitig die <strong>/db/config/secret.php</strong>, übernimmt den <strong>APP_KEY</strong> automatisch
                    und baut daraus eine fertige <strong>core.zip</strong>.
                </div>

                <div class="mini">
                    Hinweis: Wenn du neu installieren willst, musst du die Lock-Datei (und ggf. app.php) manuell löschen.
                </div>
            </div>

        </div>
    </div>
</div>

<div class="watermark">made with &lt;3 by @kljub</div>

</body>
</html>