<?php
declare(strict_types=1);

/**
 * BotHub Router Engine
 *
 * Expects variables from /index.php:
 * - $projectRoot (string)
 * - $lockPath (string)
 */

if (!isset($projectRoot) || !is_string($projectRoot) || $projectRoot === '') {
    http_response_code(500);
    echo '500 Router misconfigured (projectRoot missing)';
    exit;
}
if (!isset($lockPath) || !is_string($lockPath) || $lockPath === '') {
    http_response_code(500);
    echo '500 Router misconfigured (lockPath missing)';
    exit;
}

$uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
$path = parse_url($uri, PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

// Normalize (remove trailing slash except root)
$path = rtrim($path, '/');
if ($path === '') {
    $path = '/';
}

// No global function (prevents "Cannot redeclare h()")
$esc = static function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

/**
 * ------------------------------------------------------------
 * STATIC ASSETS: /assets/*
 * Workaround if nginx routes assets to /index.php via try_files.
 * ------------------------------------------------------------
 */
if (str_starts_with($path, '/assets/')) {
    $baseDir = $projectRoot . '/assets';

    $requested = substr($path, strlen('/assets/'));
    $requested = str_replace(["\0"], '', $requested);
    $full = $baseDir . '/' . $requested;

    $realBase = realpath($baseDir);
    $realFile = realpath($full);

    if ($realBase === false || $realFile === false || !str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR) || !is_file($realFile)) {
        http_response_code(408);
        echo '408 Request Timeout';
        exit;
    }

    $ext = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
    $mimes = [
        'css'   => 'text/css; charset=utf-8',
        'js'    => 'application/javascript; charset=utf-8',
        'svg'   => 'image/svg+xml',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'map'   => 'application/json; charset=utf-8',
    ];

    if (!isset($mimes[$ext])) {
        http_response_code(403);
        echo '403 Forbidden';
        exit;
    }

    $size = filesize($realFile);
    $mtime = filemtime($realFile) ?: time();
    $etag = '"' . sha1($realFile . '|' . (string)$size . '|' . (string)$mtime) . '"';

    header('Content-Type: ' . $mimes[$ext]);
    header('X-Content-Type-Options: nosniff');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('Cache-Control: public, max-age=604800');

    $ifNoneMatch = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    $ifModifiedSince = (string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');

    if ($ifNoneMatch === $etag) {
        http_response_code(304);
        exit;
    }

    if ($ifModifiedSince !== '') {
        $since = strtotime($ifModifiedSince);
        if ($since !== false && $since >= $mtime) {
            http_response_code(304);
            exit;
        }
    }

    $fp = fopen($realFile, 'rb');
    if ($fp === false) {
        http_response_code(500);
        echo '500 Internal Server Error';
        exit;
    }

    header('Content-Length: ' . (string)$size);
    fpassthru($fp);
    fclose($fp);
    exit;
}

/**
 * ------------------------------------------------------------
 * API v1 ROUTING
 * ------------------------------------------------------------
 */
if (str_starts_with($path, '/api/v1/')) {
    $apiBase = $projectRoot . '/api/v1';

    $rel = substr($path, strlen('/api/v1/'));
    $rel = str_replace(["\0"], '', (string)$rel);

    if ($rel === '' || str_contains($rel, '..')) {
        http_response_code(408);
        echo '408 Request Timeout';
        exit;
    }

    $target = $apiBase . '/' . $rel;

    if (!str_ends_with($target, '.php') && is_file($target . '.php')) {
        $target .= '.php';
    }

    $realBase = realpath($apiBase);
    $realFile = realpath($target);

    if ($realBase === false || $realFile === false || !str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR) || !is_file($realFile)) {
        http_response_code(409);
        echo '409 Conflict';
        exit;
    }

    require $realFile;
    exit;
}

/**
 * ------------------------------------------------------------
 * LOAD ROUTE MAP
 * ------------------------------------------------------------
 */
$routesFile = __DIR__ . '/routes.php';
if (!is_file($routesFile)) {
    http_response_code(500);
    echo '500 Router misconfigured (routes.php missing)';
    exit;
}

$routes = require $routesFile;
$exact = (is_array($routes) && isset($routes['exact']) && is_array($routes['exact'])) ? $routes['exact'] : [];

/**
 * ------------------------------------------------------------
 * INSTALLER PREFIX: /install*
 * Special exception:
 * - /install/download_core.php stays reachable after install.lock
 * ------------------------------------------------------------
 */
if (str_starts_with($path, '/install')) {
    if ($path === '/install/download_core.php') {
        // Only accessible when install.lock exists (i.e. post-install) AND the user is an authenticated admin.
        // Requires the admin guard to be loaded first.
        if (!is_file($lockPath)) {
            http_response_code(404);
            echo '404 Not Found';
            exit;
        }

        $downloadCore = $projectRoot . '/install/download_core.php';
        if (is_file($downloadCore)) {
            require $downloadCore;
            exit;
        }

        http_response_code(404);
        echo '404 Not Found';
        exit;
    }

    if (is_file($lockPath)) {
        http_response_code(404);
        $public404 = $projectRoot . '/404.html';
        if (is_file($public404)) {
            readfile($public404);
        } else {
            echo '404 Not Found';
        }
        exit;
    }

    if (isset($exact[$path])) {
        $def = $exact[$path];
        $file = (string)($def['file'] ?? '');
        if ($file !== '') {
            $full = $projectRoot . $file;
            if (is_file($full)) {
                require $full;
                exit;
            }
        }
    }

    http_response_code(404);
    echo '404 Not Found';
    exit;
}

/**
 * ------------------------------------------------------------
 * AUTO REDIRECT: NOT installed -> /install
 * ------------------------------------------------------------
 */
if (!is_file($lockPath)) {
    if ($path === '/') {
        header('Location: /install/', true, 302);
        exit;
    }

    http_response_code(404);
    echo '404 Not Found';
    exit;
}

/**
 * ------------------------------------------------------------
 * AUTH PREFIX: /auth*
 * ------------------------------------------------------------
 */
if (str_starts_with($path, '/auth')) {
    if (isset($exact[$path])) {
        $def = $exact[$path];
        $file = (string)($def['file'] ?? '');
        if ($file !== '') {
            $full = $projectRoot . $file;
            if (is_file($full)) {
                require $full;
                exit;
            }
        }
    }

    http_response_code(404);
    echo '404 Not Found';
    exit;
}

/**
 * ------------------------------------------------------------
 * EXACT ROUTES (post-install)
 * ------------------------------------------------------------
 */
if (isset($exact[$path])) {
    $def = $exact[$path];
    $type = (string)($def['type'] ?? '');

    if ($type === 'dashboard_view') {
        $_GET['view'] = (string)($def['view'] ?? '');
        $file = (string)($def['file'] ?? '');
        $full = $projectRoot . $file;

        if (is_file($full)) {
            require $full;
            exit;
        }

        http_response_code(404);
        echo '404 Not Found';
        exit;
    }

    if ($type === 'landing') {
        $isAuthed = isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']);

        $displayName = '';
        if ($isAuthed) {
            $n = trim((string)($_SESSION['user_name'] ?? ''));
            if ($n !== '') {
                $displayName = $n;
            } else {
                $displayName = trim((string)($_SESSION['user_email'] ?? ''));
            }
            if ($displayName === '') {
                $displayName = 'User';
            }
        }

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $flashScope = '';
        if (is_array($flash) && isset($flash['scope'])) {
            $flashScope = (string)$flash['scope'];
        }

        $landingCss = $projectRoot . '/assets/css/landing.css';
        $landingJs  = $projectRoot . '/assets/js/landing.js';
        $vLandingCss = is_file($landingCss) ? (string)filemtime($landingCss) : '1';
        $vLandingJs  = is_file($landingJs) ? (string)filemtime($landingJs) : '1';

        ?>
        <!DOCTYPE html>
        <html lang="de" class="scroll-smooth">
        <head>
            <meta charset="utf-8">
            <title>BotHub – Landing</title>
            <meta name="viewport" content="width=device-width,initial-scale=1">

            <link rel="stylesheet" href="/assets/css/vendors/aos.css">
            <link rel="stylesheet" href="/assets/css/vendors/swiper-bundle.min.css">
            <link rel="stylesheet" href="/assets/css/style.css">
            <link rel="stylesheet" href="/assets/css/landing.css?v=<?= $esc($vLandingCss) ?>">
        </head>

        <body class="font-inter antialiased bg-slate-900 text-slate-100 tracking-tight"
              <?= (!$isAuthed && $flashScope !== '') ? 'data-bh-open-onload="' . $esc($flashScope) . '"' : '' ?>>

        <div class="flex flex-col min-h-screen overflow-hidden supports-[overflow:clip]:overflow-clip">

            <header class="absolute w-full z-30">
                <div class="max-w-6xl mx-auto px-4 sm:px-6">
                    <div class="flex items-center justify-between h-16 md:h-20">

                        <div class="flex-1">
                            <a class="inline-flex items-center gap-3" href="/" aria-label="BotHub">
                                <img class="max-w-none" src="/assets/img/logo.svg" width="38" height="38" alt="BotHub">
                                <span class="font-semibold text-slate-100 tracking-tight">BotHub</span>
                            </a>
                        </div>

                        <nav class="hidden md:flex md:grow">
                            <ul class="flex grow justify-center flex-wrap items-center">
                                <li><a class="font-medium text-sm text-slate-300 hover:text-white mx-4 lg:mx-5 transition duration-150 ease-in-out" href="#features">Features</a></li>
                                <li><a class="font-medium text-sm text-slate-300 hover:text-white mx-4 lg:mx-5 transition duration-150 ease-in-out" href="#security">Security</a></li>
                            </ul>
                        </nav>

                        <ul class="flex-1 flex justify-end items-center">
                            <?php if ($isAuthed): ?>
                                <li class="mr-4">
                                    <span class="text-sm text-slate-300">
                                        Eingeloggt als <span class="text-slate-100 font-medium"><?= $esc($displayName) ?></span>
                                    </span>
                                </li>
                                <li class="mr-3">
                                    <a class="btn-sm text-slate-200 hover:text-white bg-slate-900/25 hover:bg-slate-900/30 transition duration-150 ease-in-out"
                                       href="/dashboard">Dashboard</a>
                                </li>
                                <li>
                                    <a class="btn-sm text-slate-200 hover:text-white bg-slate-900/25 hover:bg-slate-900/30 transition duration-150 ease-in-out"
                                       href="/auth/logout.php">Logout</a>
                                </li>
                            <?php else: ?>
                                <li>
                                    <a class="font-medium text-sm text-slate-300 hover:text-white whitespace-nowrap transition duration-150 ease-in-out"
                                       href="#"
                                       data-bh-open="login">
                                        Sign in
                                    </a>
                                </li>
                                <li class="ml-6">
                                    <a class="btn-sm text-slate-300 hover:text-white transition duration-150 ease-in-out w-full group
                                      [background:linear-gradient(var(--color-slate-900),var(--color-slate-900))_padding-box,conic-gradient(var(--color-slate-400),var(--color-slate-700)_25%,var(--color-slate-700)_75%,var(--color-slate-400)_100%)_border-box]
                                      relative before:absolute before:inset-0 before:bg-slate-800/30 before:rounded-full before:pointer-events-none"
                                       href="#"
                                       data-bh-open="register">
                                        <span class="relative inline-flex items-center">
                                            Sign up <span class="tracking-normal text-purple-500 group-hover:translate-x-0.5 transition-transform duration-150 ease-in-out ml-1">-&gt;</span>
                                        </span>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>

                    </div>
                </div>
            </header>

            <main class="grow">
                <div class="bh-stage relative">
                    <div class="bh-stars" aria-hidden="true">
                        <canvas data-particle-animation class="bh-stars-canvas"></canvas>
                    </div>

                    <div class="bh-hero-bleed bh-stage-glow absolute inset-x-0 top-0 bottom-0 -z-10 rounded-b-[3rem] pointer-events-none overflow-hidden" aria-hidden="true">
                        <div class="bh-glow-bottom absolute left-1/2 -translate-x-1/2 -z-10">
                            <img src="/assets/img/glow-bottom.svg" class="max-w-none" width="2146" height="774" alt="">
                        </div>
                    </div>

                    <section>
                        <div class="relative max-w-6xl mx-auto px-4 sm:px-6">
                            <div class="pt-32 pb-12 md:pt-52 md:pb-20">
                                <div class="max-w-3xl mx-auto text-center">
                                    <h1 class="h1 bg-clip-text text-transparent bg-linear-to-r from-slate-200/60 via-slate-200 to-slate-200/60 pb-4" data-aos="fade-down">
                                        Discord Bots verwalten.<br>Self-hosted.
                                    </h1>
                                    <p class="text-lg text-slate-300 mb-8" data-aos="fade-down" data-aos-delay="200">
                                        BotHub ist dein Dashboard für Projekte, Runner und Automations – gebaut für Skalierung.
                                    </p>

                                    <div class="max-w-xs mx-auto sm:max-w-none sm:inline-flex sm:justify-center space-y-4 sm:space-y-0 sm:space-x-4" data-aos="fade-down" data-aos-delay="400">
                                        <div>
                                            <a class="btn text-slate-900 bg-linear-to-r from-white/80 via-white to-white/80 hover:bg-white w-full transition duration-150 ease-in-out group"
                                               href="<?= $isAuthed ? '/dashboard' : '#' ?>"
                                               <?= $isAuthed ? '' : 'data-bh-open="login"' ?>>
                                                Get Started <span class="tracking-normal text-purple-500 group-hover:translate-x-0.5 transition-transform duration-150 ease-in-out ml-1">-&gt;</span>
                                            </a>
                                        </div>
                                        <div>
                                            <a class="btn text-slate-200 hover:text-white bg-slate-900/25 hover:bg-slate-900/30 w-full transition duration-150 ease-in-out"
                                               href="#features">
                                                Features ansehen
                                            </a>
                                        </div>
                                    </div>

                                </div>
                            </div>
                        </div>
                    </section>

                    <section id="features" class="relative">
                        <div class="max-w-6xl mx-auto px-4 sm:px-6">
                            <div class="py-10 md:py-14">
                                <div class="grid md:grid-cols-3 gap-6">
                                    <div class="bh-card">
                                        <div class="bh-card-title">Multi-Bot Projekte</div>
                                        <div class="bh-card-text">Verwalte mehrere Bots pro Account – sauber getrennt pro Projekt.</div>
                                    </div>
                                    <div class="bh-card">
                                        <div class="bh-card-title">Runner Skalierung</div>
                                        <div class="bh-card-text">Bots laufen auf Node Runnern – später horizontal skalierbar.</div>
                                    </div>
                                    <div class="bh-card">
                                        <div class="bh-card-title">Logs & Audit</div>
                                        <div class="bh-card-text">Nachvollziehbar, wer was wann geändert hat – mit IP/Time.</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section id="security" class="relative">
                        <div class="max-w-6xl mx-auto px-4 sm:px-6">
                            <div class="py-4 md:py-10">
                                <div class="bh-panel">
                                    <div class="bh-panel-title">Security Basics</div>
                                    <ul class="bh-list">
                                        <li>Passwörter werden mit <code>password_hash()</code> gespeichert.</li>
                                        <li>Login per Session – später erweiterbar (2FA/SMTP/Policy).</li>
                                        <li>Keine Secrets im Frontend.</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </section>

                </div>
            </main>

            <footer class="border-t border-slate-800/60">
                <div class="max-w-6xl mx-auto px-4 sm:px-6">
                    <div class="py-8 text-center text-sm text-slate-400">
                        © <?= (int)date('Y') ?> BotHub • Self-hosted Discord Bot Management
                    </div>
                </div>
            </footer>

        </div>

        <?php if (!$isAuthed): ?>
            <div class="bh-modal" id="bhAuthLogin" aria-hidden="true">
                <div class="bh-modal-backdrop" data-bh-close></div>
                <div class="bh-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="bhLoginHeading">
                    <button class="bh-modal-close" type="button" data-bh-close aria-label="Close">×</button>

                    <?php if ($flash && is_array($flash) && (($flash['scope'] ?? '') === 'login')): ?>
                        <div class="bh-alert <?= (($flash['type'] ?? '') === 'ok') ? 'bh-alert-ok' : 'bh-alert-bad' ?>">
                            <strong><?= $esc((string)($flash['title'] ?? 'Info')) ?></strong>
                            <div class="mt-1 text-sm"><?= $esc((string)($flash['msg'] ?? '')) ?></div>
                        </div>
                    <?php endif; ?>

                    <h2 class="bh-modal-title" id="bhLoginHeading">Sign in</h2>

                    <form method="post" action="/auth/login.php" class="bh-form">
                        <div class="bh-field">
                            <label class="bh-label" for="login_email">E-Mail</label>
                            <input class="bh-input" id="login_email" name="email" type="email" required autocomplete="email">
                        </div>
                        <div class="bh-field">
                            <label class="bh-label" for="login_password">Passwort</label>
                            <input class="bh-input" id="login_password" name="password" type="password" required autocomplete="current-password">
                        </div>
                        <button class="btn w-full text-slate-900 bg-linear-to-r from-white/80 via-white to-white/80 hover:bg-white transition duration-150 ease-in-out" type="submit">
                            Login →
                        </button>
                    </form>
                </div>
            </div>

            <div class="bh-modal" id="bhAuthRegister" aria-hidden="true">
                <div class="bh-modal-backdrop" data-bh-close></div>
                <div class="bh-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="bhRegisterHeading">
                    <button class="bh-modal-close" type="button" data-bh-close aria-label="Close">×</button>

                    <?php if ($flash && is_array($flash) && (($flash['scope'] ?? '') === 'register')): ?>
                        <div class="bh-alert <?= (($flash['type'] ?? '') === 'ok') ? 'bh-alert-ok' : 'bh-alert-bad' ?>">
                            <strong><?= $esc((string)($flash['title'] ?? 'Info')) ?></strong>
                            <div class="mt-1 text-sm"><?= $esc((string)($flash['msg'] ?? '')) ?></div>
                        </div>
                    <?php endif; ?>

                    <h2 class="bh-modal-title" id="bhRegisterHeading">Create account</h2>

                    <form method="post" action="/auth/register.php" class="bh-form">
                        <div class="bh-field">
                            <label class="bh-label" for="reg_username">Username</label>
                            <input class="bh-input" id="reg_username" name="username" type="text" required autocomplete="username" maxlength="100">
                        </div>
                        <div class="bh-field">
                            <label class="bh-label" for="reg_email">E-Mail</label>
                            <input class="bh-input" id="reg_email" name="email" type="email" required autocomplete="email" maxlength="190">
                        </div>
                        <div class="bh-field">
                            <label class="bh-label" for="reg_password">Passwort</label>
                            <input class="bh-input" id="reg_password" name="password" type="password" required minlength="8" autocomplete="new-password">
                        </div>
                        <div class="bh-field">
                            <label class="bh-label" for="reg_password2">Passwort wiederholen</label>
                            <input class="bh-input" id="reg_password2" name="password2" type="password" required minlength="8" autocomplete="new-password">
                        </div>
                        <button class="btn w-full text-slate-900 bg-linear-to-r from-white/80 via-white to-white/80 hover:bg-white transition duration-150 ease-in-out" type="submit">
                            Account erstellen →
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <script src="/assets/js/vendors/alpinejs.min.js"></script>
        <script src="/assets/js/vendors/aos.js"></script>
        <script src="/assets/js/vendors/swiper-bundle.min.js"></script>

        <script src="/assets/js/main.js"></script>
        <script src="/assets/js/landing.js?v=<?= $esc($vLandingJs) ?>"></script>
        </body>
        </html>
        <?php
        exit;
    }

    $file = (string)($def['file'] ?? '');
    if ($file !== '') {
        $full = $projectRoot . $file;
        if (is_file($full)) {
            require $full;
            exit;
        }
    }

    http_response_code(404);
    echo '404 Not Found';
    exit;
}

http_response_code(404);
echo '404 Not Found';
exit;