<?php
declare(strict_types=1);
$projectRoot = dirname(__DIR__);
$lockPath    = $projectRoot . '/db/config/install.lock';

$alreadyInstalled = is_file($lockPath);

$host   = (string)($_SERVER['HTTP_HOST'] ?? '');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $scheme . '://' . $host;

function check_url_status(string $url, ?string &$raw, ?string &$err): ?int
{
    $raw = null;
    $err = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        curl_exec($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        if ($curlErr) { $err = $curlErr; return null; }
        $raw = 'HTTP status ' . $status;
        return $status ?: null;
    }

    // Fallback: raw socket (HTTP only)
    $parsed = parse_url($url);
    $ip     = '127.0.0.1';
    $port   = (int)($parsed['port'] ?? (($parsed['scheme'] ?? 'http') === 'https' ? 443 : 80));
    $path   = ($parsed['path'] ?? '/');

    $fp = @fsockopen($ip, $port, $errno, $errstr, 2.0);
    if (!$fp) { $err = "{$errno} {$errstr}"; return null; }
    stream_set_timeout($fp, 2);
    @fwrite($fp, "GET {$path} HTTP/1.1\r\nHost: {$parsed['host']}\r\nConnection: close\r\n\r\n");
    $line = @fgets($fp, 512);
    @fclose($fp);
    if (!$line) { $err = 'No response'; return null; }
    $raw = trim($line);
    if (preg_match('~^HTTP/\d[\d.]*\s+(\d{3})~', $raw, $m)) { return (int)$m[1]; }
    $err = 'Unrecognized status line: ' . $raw;
    return null;
}

if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * Pflicht-Gate 1: /db/ muss HTTP geblockt sein (403/404)
 * Wir testen bewusst die Ping-Datei, weil sie existiert.
 */
$dbPingPath = '/db/installer/ping.txt';
$dbGate = [
    'path' => $dbPingPath,
    'status' => null,
    'raw' => null,
    'error' => null,
    'ok' => false,
];

/**
 * Pflicht-Gate 2: try_files muss aktiv sein
 * Pretty route /install/install darf NICHT nginx-404 sein.
 */
$routeTestPath = '/install/install';
$routeGate = [
    'path' => $routeTestPath,
    'status' => null,
    'raw' => null,
    'error' => null,
    'ok' => false,
];

if ($host !== '') {
    // Gate 1
    $dbStatus = check_url_status($baseUrl . $dbPingPath, $dbGate['raw'], $dbGate['error']);
    $dbGate['status'] = $dbStatus;
    if ($dbStatus === 403 || $dbStatus === 404) {
        $dbGate['ok'] = true;
    }

    // Gate 2
    $routeStatus = check_url_status($baseUrl . $routeTestPath, $routeGate['raw'], $routeGate['error']);
    $routeGate['status'] = $routeStatus;

    if ($routeStatus !== 404 && $routeStatus !== null) {
        $routeGate['ok'] = true;
    }
}

$canProceed = (!$alreadyInstalled) && $dbGate['ok'];

?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>BotHub Installer – Requirements</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/installer.css">
</head>
<body>

<div class="page">
    <div class="container">
        <div class="card">

            <div class="card-header">
                <div class="badge"><span class="badge-dot"></span>Installer • Step 1/3</div>
                <h1>System Requirements</h1>
                <p class="sub">
                    Installation ist nur möglich wenn:
                    <br>• <strong>/db/ per HTTP geblockt</strong> ist (403/404)
                    <br>• <strong>try_files Routing</strong> aktiv ist (Pretty Routes)
                </p>
            </div>

            <div class="card-body">

                <?php if ($alreadyInstalled): ?>
                    <div class="alert alert-success">
                        <strong>Bereits installiert.</strong><br>
                        Lock-Datei gefunden.
                    </div>
                    <?php exit; ?>
                <?php endif; ?>

                <div class="section-title">1) Pflicht: /db/ deny</div>

                <div class="field">
                    <div class="label">Test-Pfad</div>
                    <div class="code"><?= h($dbGate['path']) ?></div>
                </div>

                <div class="field" style="margin-top:12px;">
                    <div class="label">HTTP Status (localhost socket)</div>
                    <div class="code"><?= $dbGate['status'] === null ? 'unbekannt' : (string)$dbGate['status'] ?></div>
                    <?php if ($dbGate['raw']): ?><div class="help"><?= h($dbGate['raw']) ?></div><?php endif; ?>
                    <?php if ($dbGate['error']): ?><div class="help"><?= h($dbGate['error']) ?></div><?php endif; ?>
                </div>

                <?php if ($dbGate['ok']): ?>
                    <div class="alert alert-success">
                        <strong>OK:</strong> /db/ ist geblockt (403/404).
                    </div>
                <?php else: ?>
                    <div class="alert alert-danger">
                        <strong>FEHLER:</strong> /db/ ist nicht sicher geblockt.<br>
                        <span class="mini">Ohne DENY geht es nicht weiter. Erlaubt sind nur 403/404.</span>
                    </div>

                    <div class="field" style="margin-top:12px;">
                        <div class="label">NGINX DENY Snippet (Pflicht)</div>
                        <div class="code">location ^~ /db/ {
    deny all;
    return 403;
}</div>
                        <div class="help">Danach nginx reload + diese Seite neu laden, bis Status 403/404 erscheint.</div>
                    </div>
                <?php endif; ?>

                <div class="sep"></div>

                <div class="section-title">2) Pflicht: try_files Routing</div>

                <div class="field">
                    <div class="label">Test-Pfad</div>
                    <div class="code"><?= h($routeGate['path']) ?></div>
                </div>

                <div class="field" style="margin-top:12px;">
                    <div class="label">HTTP Status (localhost socket)</div>
                    <div class="code"><?= $routeGate['status'] === null ? 'unbekannt' : (string)$routeGate['status'] ?></div>
                    <?php if ($routeGate['raw']): ?><div class="help"><?= h($routeGate['raw']) ?></div><?php endif; ?>
                    <?php if ($routeGate['error']): ?><div class="help"><?= h($routeGate['error']) ?></div><?php endif; ?>
                </div>

                <?php if ($routeGate['ok']): ?>
                    <div class="alert alert-success">
                        <strong>OK:</strong> try_files Routing scheint aktiv (kein nginx-404).
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <strong>Hinweis:</strong> Pretty Routes noch nicht aktiv (try_files fehlt).<br>
                        <span class="mini">Der Installer funktioniert trotzdem. try_files wird als Teil der nginx-Konfiguration eingerichtet.</span>
                    </div>

                    <div class="field" style="margin-top:12px;">
                        <div class="label">NGINX try_files Snippet (Pflicht)</div>
                        <div class="code">location / {
    try_files $uri $uri/ /index.php?$query_string;
}</div>
                    </div>
                <?php endif; ?>

                <div class="actions">
                    <?php if ($canProceed): ?>
                        <a class="btn" href="/install/install.php">Weiter →</a>
                        <div class="mini">Step 2/3: DB Verbindung + Rechte testen + Schema importieren.</div>
                    <?php else: ?>
                        <a class="btn btn-secondary" href="/install/">Neu prüfen</a>
                        <div class="mini">Weiter erst, wenn beide Checks grün sind.</div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>
</div>

<div class="watermark">made with &lt;3 by @kljub</div>

</body>
</html>