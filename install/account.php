<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/db.php';

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/functions/html.php';

$lockPath = $projectRoot . '/db/config/install.lock';

if (is_file($lockPath)) {
    http_response_code(404);
    echo '404 Not Found';
    exit;
}

$cfg = $_SESSION['installer_db'] ?? null;
if (!is_array($cfg)) {
    http_response_code(400);
    echo 'DB-Konfiguration fehlt. Bitte zuerst /install/install.php ausführen.';
    exit;
}

// Secret muss nach dem Schema-Import existieren
$secretPath = installer_secret_path($projectRoot);
if (!is_file($secretPath)) {
    http_response_code(500);
    echo 'Secret fehlt (' . h($secretPath) . '). Bitte erneut /install/install.php ausführen.';
    exit;
}

$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $email === '' || $password === '') {
        $errors[] = 'Bitte alle Felder ausfüllen.';
    } elseif (strlen($password) < 10) {
        $errors[] = 'Passwort zu kurz (mind. 10 Zeichen).';
    } else {
        try {
            $pdo = installer_pdo($cfg);

            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
            $stmt->execute([$username, $email]);
            $exists = $stmt->fetch();

            if ($exists) {
                $errors[] = 'Username oder Email existiert bereits.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                if ($hash === false) {
                    throw new RuntimeException('password_hash() fehlgeschlagen.');
                }

                $stmt = $pdo->prepare("
                    INSERT INTO users (username, email, password_hash, role, is_active, created_at, updated_at)
                    VALUES (?, ?, ?, 'admin', 1, NOW(), NOW())
                ");
                $stmt->execute([$username, $email, $hash]);

                $success = 'Admin Account erstellt.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Fehler beim Admin-Insert: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>BotHub Installer – Admin</title>
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
                    Installer • Step 3/3
                </div>

                <h1>Admin Account erstellen</h1>
                <p class="sub">
                    Erstelle den ersten Admin für das Dashboard.
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
                        <span class="mini">Jetzt Finish ausführen (Lock-Datei schreiben).</span>
                    </div>
                <?php endif; ?>

                <div class="section-title">Admin Daten</div>

                <form method="post" autocomplete="off">
                    <div class="grid">
                        <div class="field">
                            <div class="label">Admin Username</div>
                            <input class="input" type="text" name="username" required>
                        </div>

                        <div class="field">
                            <div class="label">Admin Email</div>
                            <input class="input" type="email" name="email" required>
                        </div>
                    </div>

                    <div class="field" style="margin-top: 14px;">
                        <div class="label">Admin Passwort</div>
                        <input class="input" type="password" name="password" required placeholder="mind. 10 Zeichen">
                        <div class="help">Bitte ein starkes Passwort wählen.</div>
                    </div>

                    <div class="actions">
                        <button class="btn" type="submit">Admin erstellen →</button>
                        <a class="btn btn-secondary" href="/install/install.php">Zurück</a>

                        <?php if ($success): ?>
                            <a class="btn" href="/install/finish.php">Finish →</a>
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