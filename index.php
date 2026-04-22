<?php
declare(strict_types=1);

/**
 * BotHub Front Controller (Bootstrap)
 * - nginx: try_files $uri $uri/ /index.php?$query_string;
 * - Routing: /routes/router.php + /routes/routes.php
 */

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// Ensure a CSRF token exists for the lifetime of the session
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$projectRoot = dirname(__FILE__);
$lockPath = $projectRoot . '/db/config/install.lock';

// Define BH_DEV_MODE from app.php so dev-only features (e.g. Project Builder) can be gated
if (!defined('BH_DEV_MODE')) {
    $appCfg = @include $projectRoot . '/db/config/app.php';
    define('BH_DEV_MODE', is_array($appCfg) && !empty($appCfg['dev_mode']));
}

require __DIR__ . '/routes/router.php';