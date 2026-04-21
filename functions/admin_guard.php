<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth/_db.php';

function bh_admin_require_user(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
        header('Location: /?auth=login', true, 302);
        exit;
    }

    $userId = (int)$_SESSION['user_id'];

    $pdo = bh_pdo();
    $stmt = $pdo->prepare('SELECT id, username, email, role, is_active FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();

    if (!is_array($row) || (int)($row['is_active'] ?? 0) !== 1) {
        header('Location: /auth/logout', true, 302);
        exit;
    }

    if ((string)($row['role'] ?? '') !== 'admin') {
        header('Location: /dashboard', true, 302);
        exit;
    }

    return [
        'id' => (int)($row['id'] ?? 0),
        'username' => trim((string)($row['username'] ?? '')),
        'email' => trim((string)($row['email'] ?? '')),
        'role' => trim((string)($row['role'] ?? '')),
    ];
}