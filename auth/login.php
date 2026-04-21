<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/_db.php';

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

$email = trim((string)($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($email === '' || $password === '') {
    $_SESSION['flash'] = [
        'type' => 'bad',
        'scope' => 'login',
        'title' => 'Login fehlgeschlagen',
        'msg' => 'Bitte E-Mail und Passwort eingeben.',
    ];
    redirect('/?auth=login');
}

try {
    $pdo = bh_pdo();

    $stmt = $pdo->prepare('SELECT id, email, username, password_hash, role FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $u = $stmt->fetch();

    if (!$u || !isset($u['password_hash']) || !password_verify($password, (string)$u['password_hash'])) {
        $_SESSION['flash'] = [
            'type' => 'bad',
            'scope' => 'login',
            'title' => 'Login fehlgeschlagen',
            'msg' => 'E-Mail oder Passwort ist falsch.',
        ];
        redirect('/?auth=login');
    }

    session_regenerate_id(true);

    $_SESSION['user_id'] = (int)$u['id'];
    $_SESSION['user_email'] = (string)$u['email'];
    $_SESSION['user_name'] = (string)($u['username'] ?? '');
    $_SESSION['user_role'] = (string)($u['role'] ?? 'user');

    $_SESSION['flash'] = [
        'type' => 'ok',
        'scope' => 'login',
        'title' => 'Willkommen',
        'msg' => 'Du bist jetzt eingeloggt.',
    ];
    redirect('/');
} catch (Throwable $e) {
    error_log('[BotHub] login error: ' . $e->getMessage());
    $_SESSION['flash'] = [
        'type' => 'bad',
        'scope' => 'login',
        'title' => 'Serverfehler',
        'msg' => 'Ein interner Fehler ist aufgetreten. Bitte versuche es später erneut.',
    ];
    redirect('/?auth=login');
}