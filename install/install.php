<?php
declare(strict_types=1);

session_start();

$projectRoot = dirname(__DIR__);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/dbqueue/dbinstall.php';
require_once $projectRoot . '/functions/html.php';

$lockPath = $projectRoot . '/db/config/install.lock';

if (is_file($lockPath)) {
    http_response_code(404);
    echo '404 Not Found';
    exit;
}

$errors = [];
$success = null;

$defaults = [
    'host' => '127.0.0.1',
    'port' => '3306',
    'dbname' => '',
    'user' => '',
    'pass' => '',
];

$detectedScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$detectedHost   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
$appDefaults = [
    'base_url' => $detectedScheme . '://' . $detectedHost,
];

$cfg = $defaults;
if (isset($_SESSION['installer_db']) && is_array($_SESSION['installer_db'])) {
    $cfg = array_merge($cfg, $_SESSION['installer_db']);
}

$appCfg = $appDefaults;
if (isset($_SESSION['installer_app']) && is_array($_SESSION['installer_app'])) {
    $appCfg = array_merge($appCfg, $_SESSION['installer_app']);
}

$lastSql = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $cfg['host'] = trim((string)($_POST['host'] ?? $cfg['host']));
    $cfg['port'] = trim((string)($_POST['port'] ?? $cfg['port']));
    $cfg['dbname'] = trim((string)($_POST['dbname'] ?? $cfg['dbname']));
    $cfg['user'] = trim((string)($_POST['user'] ?? $cfg['user']));
    $cfg['pass'] = (string)($_POST['pass'] ?? $cfg['pass']);

    $appCfg['base_url'] = trim((string)($_POST['base_url'] ?? $appCfg['base_url']));

    if ($appCfg['base_url'] === '') {
        $errors[] = 'Website URL fehlt.';
    } elseif (!preg_match('~^https?://~i', $appCfg['base_url'])) {
        $errors[] = 'Website URL muss mit http:// oder https:// beginnen.';
    } elseif (filter_var($appCfg['base_url'], FILTER_VALIDATE_URL) === false) {
        $errors[] = 'Website URL ist ungültig.';
    } else {
        $appCfg['base_url'] = rtrim($appCfg['base_url'], '/');
    }

    $_SESSION['installer_db'] = $cfg;
    $_SESSION['installer_app'] = $appCfg;

    if (!$errors) {
        $res = installer_try_connect($cfg);
        if (!$res['ok']) {
            $errors[] = 'DB Verbindung fehlgeschlagen: ' . (string)$res['error'];
        } else {
            try {
                $pdo = installer_pdo($cfg);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                $schemaResult = install_database_schema($pdo, $projectRoot);

                if (!$schemaResult['ok']) {
                    throw new RuntimeException('Schema-Import fehlgeschlagen: ' . implode(', ', $schemaResult['errors']));
                }

                $sec = installer_write_secret_if_missing($projectRoot);
                if (!$sec['ok']) {
                    throw new RuntimeException('Secret-Erzeugung fehlgeschlagen: ' . (string)$sec['error']);
                }

                $_SESSION['installer_secret_path'] = (string)$sec['path'];

                if (!$errors) {
                    $success = 'DB geprüft + Schema importiert + Secret bereit.';
                }
            } catch (Throwable $e) {
                $errors[] = 'Install Fehler: ' . $e->getMessage();

                if ($lastSql !== null) {
                    $preview = mb_substr($lastSql, 0, 700);
                    $errors[] = 'Letztes SQL (Preview): ' . $preview;
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>BotHub Installer – Datenbank</title>
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
                    Installer • Step 2/3
                </div>

                <h1>Datenbank einrichten</h1>
                <p class="sub">
                    Verbindung testen, Rechte prüfen, Schema importieren und die Website URL festlegen.
                </p>
            </div>

            <div class="card-body">

                <?php if ($errors): ?>
                    <div class="alert alert-danger">
                        <strong>Fehler</strong>
                        <ul class="ul">
                            <?php foreach ($errors as $e): ?>
                                <li><?= h((string)$e) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <strong>Erfolg:</strong> <?= h($success) ?><br>
                        <span class="mini">Weiter mit dem Admin-Account.</span>
                    </div>
                <?php endif; ?>

                <div class="section-title">Website</div>

                <form method="post" autocomplete="off">
                    <div class="grid">
                        <div class="field">
                            <div class="label">Website URL</div>
                            <input class="input" type="text" name="base_url" value="<?= h((string)$appCfg['base_url']) ?>" required>
                            <div class="help">z. B. http://localhost oder https://bots.example.com</div>
                        </div>
                    </div>

                    <div class="section-title" style="margin-top: 24px;">DB Zugangsdaten</div>

                    <div class="grid grid-3">
                        <div class="field">
                            <div class="label">Host</div>
                            <input class="input" type="text" name="host" value="<?= h((string)$cfg['host']) ?>" required>
                        </div>

                        <div class="field">
                            <div class="label">Port</div>
                            <input class="input" type="text" name="port" value="<?= h((string)$cfg['port']) ?>" required>
                        </div>

                        <div class="field">
                            <div class="label">DB Name</div>
                            <input class="input" type="text" name="dbname" value="<?= h((string)$cfg['dbname']) ?>" required>
                        </div>
                    </div>

                    <div class="grid" style="margin-top: 14px;">
                        <div class="field">
                            <div class="label">DB User</div>
                            <input class="input" type="text" name="user" value="<?= h((string)$cfg['user']) ?>" required>
                        </div>

                        <div class="field">
                            <div class="label">DB Passwort</div>
                            <input class="input" type="password" name="pass" value="<?= h((string)$cfg['pass']) ?>">
                            <div class="help">Passwort wird nur für diesen Install-Schritt in der Session gehalten.</div>
                        </div>
                    </div>

                    <div class="actions">
                        <button class="btn" type="submit">DB testen &amp; Schema importieren →</button>
                        <a class="btn btn-secondary" href="/install/">Zurück</a>

                        <?php if ($success): ?>
                            <a class="btn" href="/install/account.php">Weiter: Admin erstellen →</a>
                        <?php endif; ?>
                    </div>
                </form>

            </div>
        </div>
    </div>
</div>

<div class="watermark">made with &lt;3 by @kljub</div>

</body>
</html>