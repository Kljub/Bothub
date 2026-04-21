<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/_db.php';

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

// Check if registration is allowed
try {
    $pdo = bh_pdo();
    $stmt = $pdo->prepare('SELECT setting_value FROM admin_settings WHERE setting_key = :k LIMIT 1');
    $stmt->execute([':k' => 'allow_registration']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $allowReg = ($row === false || ($row['setting_value'] ?? '1') === '1');
} catch (Throwable) {
    $allowReg = true; // default allow if table doesn't exist yet
}

if (!$allowReg) {
    $_SESSION['flash'] = [
        'type'  => 'bad',
        'scope' => 'register',
        'title' => 'Registrierung deaktiviert',
        'msg'   => 'Die Registrierung ist derzeit deaktiviert.',
    ];
    redirect('/?auth=register');
}

$username = trim((string)($_POST['username'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$password2 = (string)($_POST['password2'] ?? '');

if ($username === '' || $email === '' || $password === '' || $password2 === '') {
    $_SESSION['flash'] = [
        'type' => 'bad',
        'scope' => 'register',
        'title' => 'Registrierung fehlgeschlagen',
        'msg' => 'Bitte alle Felder ausfüllen.',
    ];
    redirect('/?auth=register');
}

if ($password !== $password2) {
    $_SESSION['flash'] = [
        'type' => 'bad',
        'scope' => 'register',
        'title' => 'Registrierung fehlgeschlagen',
        'msg' => 'Passwörter stimmen nicht überein.',
    ];
    redirect('/?auth=register');
}

if (strlen($password) < 8) {
    $_SESSION['flash'] = [
        'type' => 'bad',
        'scope' => 'register',
        'title' => 'Registrierung fehlgeschlagen',
        'msg' => 'Passwort muss mindestens 8 Zeichen haben.',
    ];
    redirect('/?auth=register');
}

try {
    $pdo = bh_pdo();

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        $_SESSION['flash'] = [
            'type' => 'bad',
            'scope' => 'register',
            'title' => 'Registrierung fehlgeschlagen',
            'msg' => 'Diese E-Mail ist bereits registriert.',
        ];
        redirect('/?auth=register');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'user')");
    $stmt->execute([$username, $email, $hash]);

    $newId = (int)$pdo->lastInsertId();

    session_regenerate_id(true);
    $_SESSION['user_id'] = $newId;
    $_SESSION['user_email'] = $email;
    $_SESSION['user_name'] = $username;
    $_SESSION['user_role'] = 'user';

    $_SESSION['flash'] = [
        'type' => 'ok',
        'scope' => 'register',
        'title' => 'Account erstellt',
        'msg' => 'Du bist jetzt eingeloggt.',
    ];
    redirect('/');
} catch (Throwable $e) {
    error_log('[BotHub] register error: ' . $e->getMessage());
    $_SESSION['flash'] = [
        'type' => 'bad',
        'scope' => 'register',
        'title' => 'Serverfehler',
        'msg' => 'Ein interner Fehler ist aufgetreten. Bitte versuche es später erneut.',
    ];
    redirect('/?auth=register');
}