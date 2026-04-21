<?php
declare(strict_types=1);

session_start();

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/functions/admin_guard.php';
require_once $projectRoot . '/functions/html.php';
require_once $projectRoot . '/auth/_db.php';

$adminUser = bh_admin_require_user();
$pageTitle  = 'App Store';
$pdo        = bh_pdo();

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// ── CSRF helper ───────────────────────────────────────────────────────────────
function bh_appstore_csrf_verify(): void
{
    $token = trim((string)(
        $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''
    ));
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf_mismatch']);
        exit;
    }
}

// ── schema_sql safety check ───────────────────────────────────────────────────
function bh_appstore_validate_schema(string $sql): bool
{
    $allowed = '/^\s*(CREATE\s+(TABLE|INDEX|UNIQUE\s+INDEX)|ALTER\s+TABLE|INSERT\s+(INTO|IGNORE)|COMMENT\s+ON)/i';
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt === '') continue;
        if (!preg_match($allowed, $stmt)) {
            return false;
        }
    }
    return true;
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS apps (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        app_key      VARCHAR(64)  NOT NULL,
        name         VARCHAR(128) NOT NULL,
        description  TEXT         NULL,
        version      VARCHAR(32)  NOT NULL DEFAULT '1.0.0',
        category     ENUM('media','moderation','utility','fun','social','custom') NOT NULL DEFAULT 'custom',
        icon_svg     TEXT         NULL,
        author       VARCHAR(128) NOT NULL DEFAULT 'BotHub',
        is_official  TINYINT(1)   NOT NULL DEFAULT 0,
        sidebar_view VARCHAR(128) NOT NULL DEFAULT '',
        schema_sql   LONGTEXT     NULL,
        db_tables    JSON         NULL,
        created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_app_key (app_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS installed_apps (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        app_key      VARCHAR(64)  NOT NULL,
        installed_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        installed_by INT UNSIGNED NOT NULL DEFAULT 0,
        status       ENUM('active','inactive') NOT NULL DEFAULT 'active',
        UNIQUE KEY uq_app_key (app_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS app_bot_settings (
        id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        app_key VARCHAR(64)  NOT NULL,
        bot_id  INT UNSIGNED NOT NULL,
        enabled TINYINT(1)   NOT NULL DEFAULT 1,
        UNIQUE KEY uq_app_bot (app_key, bot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── AJAX: install ─────────────────────────────────────────────────────────────
if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'install') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    bh_appstore_csrf_verify();

    $appKey = trim((string)($_POST['app_key'] ?? ''));
    if ($appKey === '') { echo json_encode(['ok' => false, 'error' => 'missing app_key']); exit; }

    $app = $pdo->prepare('SELECT * FROM apps WHERE app_key = ?')->execute([$appKey])
        ? $pdo->prepare('SELECT * FROM apps WHERE app_key = ?') : null;

    $appStmt = $pdo->prepare('SELECT * FROM apps WHERE app_key = ?');
    $appStmt->execute([$appKey]);
    $app = $appStmt->fetch();

    if (!$app) { echo json_encode(['ok' => false, 'error' => 'app_not_found']); exit; }

    try {
        $sql = trim((string)($app['schema_sql'] ?? ''));
        if ($sql !== '') {
            if (!bh_appstore_validate_schema($sql)) {
                echo json_encode(['ok' => false, 'error' => 'schema_sql enthält unzulässige SQL-Anweisungen. Nur CREATE TABLE, ALTER TABLE und INSERT sind erlaubt.']);
                exit;
            }
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                if ($stmt !== '') { $pdo->exec($stmt); }
            }
        }

        $adminId = (int)($adminUser['id'] ?? 0);
        $pdo->prepare("INSERT INTO installed_apps (app_key, installed_by, status) VALUES (?,?,'active')
                       ON DUPLICATE KEY UPDATE status = 'active', installed_by = VALUES(installed_by)")
            ->execute([$appKey, $adminId]);

        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}


if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'uninstall') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    bh_appstore_csrf_verify();

    $appKey = trim((string)($_POST['app_key'] ?? ''));
    if ($appKey === '') { echo json_encode(['ok' => false, 'error' => 'missing app_key']); exit; }

    $pdo->prepare("UPDATE installed_apps SET status = 'inactive' WHERE app_key = ?")
        ->execute([$appKey]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'upload') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    bh_appstore_csrf_verify();

    $file = $_FILES['app_json'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'Keine Datei hochgeladen oder Upload-Fehler.']);
        exit;
    }

    $content = file_get_contents($file['tmp_name']);
    if ($content === false) { echo json_encode(['ok' => false, 'error' => 'Datei konnte nicht gelesen werden.']); exit; }

    $data = json_decode($content, true);
    if (!is_array($data)) { echo json_encode(['ok' => false, 'error' => 'Ungültiges JSON-Format.']); exit; }

    $required = ['app_key', 'name'];
    foreach ($required as $r) {
        if (empty($data[$r])) {
            echo json_encode(['ok' => false, 'error' => "Pflichtfeld fehlt: {$r}"]);
            exit;
        }
    }

    $appKey = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$data['app_key']));
    if ($appKey === '') { echo json_encode(['ok' => false, 'error' => 'app_key ungültig (nur a-z, 0-9, _)']); exit; }

    $validCategories = ['media', 'moderation', 'utility', 'fun', 'social', 'custom'];
    $category        = in_array($data['category'] ?? '', $validCategories, true) ? $data['category'] : 'custom';
    $dbTables        = is_array($data['db_tables'] ?? null) ? $data['db_tables'] : [];

    try {
        $pdo->prepare("
            INSERT INTO apps (app_key, name, description, version, category, icon_svg, author, is_official, sidebar_view, schema_sql, db_tables)
            VALUES (?,?,?,?,?,?,?,0,?,?,?)
            ON DUPLICATE KEY UPDATE
                name         = VALUES(name),
                description  = VALUES(description),
                version      = VALUES(version),
                category     = VALUES(category),
                icon_svg     = VALUES(icon_svg),
                author       = VALUES(author),
                sidebar_view = VALUES(sidebar_view),
                schema_sql   = VALUES(schema_sql),
                db_tables    = VALUES(db_tables)
        ")->execute([
            $appKey,
            mb_substr((string)($data['name']         ?? ''), 0, 128),
            mb_substr((string)($data['description']  ?? ''), 0, 2000),
            mb_substr((string)($data['version']      ?? '1.0.0'), 0, 32),
            $category,
            mb_substr((string)($data['icon_svg']     ?? ''), 0, 2000),
            mb_substr((string)($data['author']       ?? 'Custom'), 0, 128),
            mb_substr((string)($data['sidebar_view'] ?? ''), 0, 128),
            (string)($data['schema_sql'] ?? ''),
            json_encode($dbTables),
        ]);

        echo json_encode(['ok' => true, 'app_key' => $appKey]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$apps      = $pdo->query('SELECT a.*, i.status AS install_status FROM apps a LEFT JOIN installed_apps i ON a.app_key = i.app_key ORDER BY a.is_official DESC, a.category, a.name ASC')->fetchAll();
$installed = array_column(
    $pdo->query("SELECT app_key FROM installed_apps WHERE status = 'active'")->fetchAll(),
    'app_key'
);
$installed = array_flip($installed);

$categoryLabels = [
    'media'      => 'Media',
    'moderation' => 'Moderation',
    'utility'    => 'Utility',
    'fun'        => 'Fun',
    'social'     => 'Social',
    'custom'     => 'Custom',
];
$categoryColors = [
    'media'      => 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300',
    'moderation' => 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300',
    'utility'    => 'bg-gray-100 text-gray-700 dark:bg-gray-500/20 dark:text-gray-300',
    'fun'        => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-500/20 dark:text-yellow-300',
    'social'     => 'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-300',
    'custom'     => 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-300',
];

ob_start();
?>
<main class="grow">
    <div class="px-4 sm:px-6 lg:px-8 py-8 w-full max-w-9xl mx-auto">

        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-2xl md:text-3xl text-gray-800 dark:text-gray-100 font-bold">App Store</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Installiere offizielle und eigene Apps für deinen Bot.</p>
            </div>
            <button type="button" id="upload-btn"
                class="btn bg-violet-500 hover:bg-violet-600 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
                <svg class="w-4 h-4" viewBox="0 0 16 16" fill="currentColor"><path d="M8 1a.5.5 0 0 1 .5.5v5h5a.5.5 0 0 1 0 1h-5v5a.5.5 0 0 1-1 0v-5h-5a.5.5 0 0 1 0-1h5v-5A.5.5 0 0 1 8 1Z"/></svg>
                App hochladen
            </button>
        </div>

        <!-- Toast -->
        <div id="store-toast" class="hidden mb-6 px-4 py-3 rounded-lg text-sm"></div>

        
        <div id="upload-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl p-6 w-full max-w-lg mx-4">
                <h2 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-2">Custom App hochladen</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                    Lade eine <code>.json</code>-Datei mit der App-Definition hoch.
                    Pflichtfelder: <code>app_key</code>, <code>name</code>.
                    Optionale Felder: <code>description</code>, <code>version</code>, <code>category</code>,
                    <code>author</code>, <code>sidebar_view</code>, <code>schema_sql</code>, <code>db_tables</code> (Array).
                </p>
                <input type="file" id="upload-file" accept=".json" class="block w-full text-sm text-gray-600 dark:text-gray-400 mb-4
                    file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium
                    file:bg-violet-50 dark:file:bg-violet-500/10 file:text-violet-700 dark:file:text-violet-300
                    hover:file:bg-violet-100">
                <div class="flex gap-3 justify-end">
                    <button type="button" id="upload-cancel-btn"
                        class="btn bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 px-4 py-2 rounded-lg text-sm">
                        Abbrechen
                    </button>
                    <button type="button" id="upload-submit-btn"
                        class="btn bg-violet-500 hover:bg-violet-600 text-white px-4 py-2 rounded-lg text-sm font-medium">
                        Hochladen
                    </button>
                </div>
            </div>
        </div>

        
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-6" id="app-grid">
            <?php foreach ($apps as $app):
                $key       = (string)$app['app_key'];
                $isInstalled = isset($installed[$key]);
                $catColor  = $categoryColors[$app['category']] ?? $categoryColors['custom'];
                $catLabel  = $categoryLabels[$app['category']] ?? 'Custom';
                $tables    = is_string($app['db_tables'] ?? null) ? (json_decode((string)$app['db_tables'], true) ?: []) : [];
            ?>
            <div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl p-5 flex flex-col gap-4" data-app-key="<?= h($key) ?>">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-violet-100 dark:bg-violet-500/20 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5 fill-violet-500" viewBox="0 0 16 16">
                                <?= $app['icon_svg'] !== '' ? h($app['icon_svg']) : '<rect x="2" y="2" width="12" height="12" rx="2"/>' ?>
                            </svg>
                        </div>
                        <div>
                            <div class="text-sm font-semibold text-gray-800 dark:text-gray-100">
                                <?= h($app['name']) ?>
                                <?php if ($app['is_official']): ?>
                                <span class="ml-1 text-xs bg-violet-100 dark:bg-violet-500/20 text-violet-600 dark:text-violet-300 px-1.5 py-0.5 rounded font-medium">Official</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-xs text-gray-400">v<?= h($app['version']) ?> · <?= h($app['author']) ?></div>
                        </div>
                    </div>
                    <span class="shrink-0 text-xs font-medium px-2 py-1 rounded-full <?= $catColor ?>">
                        <?= h($catLabel) ?>
                    </span>
                </div>

                <p class="text-sm text-gray-500 dark:text-gray-400 flex-1"><?= h((string)($app['description'] ?? '')) ?></p>

                <?php if (!empty($tables)): ?>
                <div>
                    <div class="text-xs text-gray-400 dark:text-gray-500 mb-1 font-medium">DB-Tabellen</div>
                    <div class="flex flex-wrap gap-1">
                        <?php foreach ($tables as $t): ?>
                        <span class="text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 px-2 py-0.5 rounded font-mono"><?= h((string)$t) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="flex items-center justify-between pt-2 border-t border-gray-100 dark:border-gray-700">
                    <?php if ($isInstalled): ?>
                    <span class="flex items-center gap-1.5 text-xs text-green-600 dark:text-green-400 font-medium">
                        <svg class="w-3.5 h-3.5 fill-current" viewBox="0 0 16 16"><path d="M13.485 1.929 5.947 9.46 2.515 6.029 1.1 7.444l4.847 4.847 8.952-8.952-1.414-1.41Z"/></svg>
                        Installiert
                    </span>
                    <?php if (!$app['is_official']): ?>
                    <button type="button" class="app-uninstall-btn text-xs text-red-500 hover:text-red-700 font-medium" data-key="<?= h($key) ?>">
                        Deinstallieren
                    </button>
                    <?php else: ?>
                    <button type="button" class="app-uninstall-btn text-xs text-gray-400 hover:text-red-500 font-medium" data-key="<?= h($key) ?>">
                        Deaktivieren
                    </button>
                    <?php endif; ?>
                    <?php else: ?>
                    <span class="flex items-center gap-1.5 text-xs text-gray-400 font-medium">
                        <svg class="w-3.5 h-3.5 fill-current" viewBox="0 0 16 16"><circle cx="8" cy="8" r="7" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
                        Nicht installiert
                    </span>
                    <button type="button" class="app-install-btn btn bg-violet-500 hover:bg-violet-600 text-white px-3 py-1 rounded-lg text-xs font-medium" data-key="<?= h($key) ?>">
                        Installieren
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if (empty($apps)): ?>
            <div class="col-span-full text-center text-gray-400 py-16">
                Noch keine Apps registriert. Führe Migration 0022 aus oder lade eine App hoch.
            </div>
            <?php endif; ?>
        </div>

    </div>
</main>

<script>
(function () {
    var url = '/admin/app-store';

    function showToast(msg, ok) {
        var t = document.getElementById('store-toast');
        t.textContent = msg;
        t.className = 'mb-6 px-4 py-3 rounded-lg text-sm ' + (ok
            ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300'
            : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300');
        clearTimeout(t._t);
        t._t = setTimeout(function () { t.className = 'hidden'; }, 4000);
    }

    var csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    async function postAction(body) {
        body.csrf_token = csrfToken;
        var res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams(body)
        });
        return res.json();
    }

    
    document.querySelectorAll('.app-install-btn').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            var key = btn.dataset.key;
            btn.disabled = true;
            btn.textContent = '…';
            var data = await postAction({ _action: 'install', app_key: key });
            if (data.ok) {
                showToast('App installiert.', true);
                setTimeout(function () { location.reload(); }, 800);
            } else {
                showToast('Fehler: ' + (data.error || 'Unbekannt'), false);
                btn.disabled = false;
                btn.textContent = 'Installieren';
            }
        });
    });

    // Uninstall
    document.querySelectorAll('.app-uninstall-btn').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            var key = btn.dataset.key;
            if (!confirm('App "' + key + '" deinstallieren/deaktivieren?')) return;
            var data = await postAction({ _action: 'uninstall', app_key: key });
            if (data.ok) {
                showToast('App deaktiviert.', true);
                setTimeout(function () { location.reload(); }, 800);
            } else {
                showToast('Fehler: ' + (data.error || 'Unbekannt'), false);
            }
        });
    });

    // Upload modal
    var modal = document.getElementById('upload-modal');
    document.getElementById('upload-btn').addEventListener('click', function () {
        modal.classList.remove('hidden');
    });
    document.getElementById('upload-cancel-btn').addEventListener('click', function () {
        modal.classList.add('hidden');
    });
    document.getElementById('upload-submit-btn').addEventListener('click', async function () {
        var file = document.getElementById('upload-file').files[0];
        if (!file) { showToast('Keine Datei ausgewählt.', false); return; }

        var form = new FormData();
        form.append('_action', 'upload');
        form.append('csrf_token', csrfToken);
        form.append('app_json', file);

        var res = await fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: form
        });
        var data = await res.json();
        modal.classList.add('hidden');

        if (data.ok) {
            showToast('App "' + data.app_key + '" hochgeladen. Jetzt installieren.', true);
            setTimeout(function () { location.reload(); }, 800);
        } else {
            showToast('Upload-Fehler: ' + (data.error || 'Unbekannt'), false);
        }
    });
}());
</script>
<?php
$contentHtml = (string)ob_get_clean();
require __DIR__ . '/_layout.php';
