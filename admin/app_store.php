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

// ── Reload all runners ────────────────────────────────────────────────────────
function bh_appstore_reload_all_runners(): void
{
    try {
        $secretPath = dirname(__DIR__) . '/db/config/secret.php';
        if (!is_file($secretPath) || !is_readable($secretPath)) return;

        $secret = require $secretPath;
        $appKey = trim((string)($secret['APP_KEY'] ?? ''));
        if ($appKey === '') return;

        global $pdo;
        $stmt = $pdo->query("SELECT endpoint FROM core_runners WHERE endpoint != '' ORDER BY id ASC");
        if ($stmt === false) return;
        $runners = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!is_array($runners)) return;

        foreach ($runners as $runner) {
            $endpoint = rtrim(trim((string)($runner['endpoint'] ?? '')), '/');
            if ($endpoint === '') continue;

            $ch = curl_init($endpoint . '/reload');
            if ($ch === false) continue;

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => '',
                CURLOPT_TIMEOUT        => 6,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $appKey,
                    'Content-Type: application/json',
                ],
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    } catch (\Throwable) {}
}

// ── ZIP validation ────────────────────────────────────────────────────────────
function bh_appstore_validate_zip(string $tmpPath): array
{
    if (filesize($tmpPath) > 5 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'ZIP zu groß (max. 5 MB).'];
    }

    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'error' => 'ZipArchive-Erweiterung nicht verfügbar.'];
    }

    $zip = new \ZipArchive();
    $res = $zip->open($tmpPath, \ZipArchive::RDONLY);
    if ($res !== true) {
        return ['ok' => false, 'error' => 'ZIP konnte nicht geöffnet werden (Code ' . $res . ').'];
    }

    $count = $zip->count();
    if ($count > 20) {
        $zip->close();
        return ['ok' => false, 'error' => 'ZIP enthält zu viele Dateien (max. 20).'];
    }

    for ($i = 0; $i < $count; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat === false) continue;

        $name = $stat['name'];

        // Reject symlinks (Unix mode 0xA000)
        $unixMode = ($stat['external_attr'] >> 16) & 0xF000;
        if ($unixMode === 0xA000) {
            $zip->close();
            return ['ok' => false, 'error' => "Symlinks sind nicht erlaubt: {$name}"];
        }

        // Reject path traversal
        if (str_contains($name, '..') || str_starts_with($name, '/') || str_starts_with($name, '\\')) {
            $zip->close();
            return ['ok' => false, 'error' => "Ungültiger Dateipfad: {$name}"];
        }
    }

    // Read and parse app.json
    $appJsonRaw = $zip->getFromName('app.json');
    $zip->close();

    if ($appJsonRaw === false) {
        return ['ok' => false, 'error' => 'app.json fehlt im ZIP.'];
    }

    $appJson = json_decode($appJsonRaw, true);
    if (!is_array($appJson)) {
        return ['ok' => false, 'error' => 'app.json ist kein gültiges JSON.'];
    }
    if (empty($appJson['app_key'])) {
        return ['ok' => false, 'error' => 'app.json: Pflichtfeld "app_key" fehlt.'];
    }
    if (empty($appJson['name'])) {
        return ['ok' => false, 'error' => 'app.json: Pflichtfeld "name" fehlt.'];
    }

    $appKey = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$appJson['app_key']));
    if ($appKey === '') {
        return ['ok' => false, 'error' => 'app_key ungültig (nur a-z, 0-9, _).'];
    }
    $appJson['app_key'] = $appKey;

    return ['ok' => true, 'app_json' => $appJson];
}

// ── Bootstrap tables ──────────────────────────────────────────────────────────
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
$pdo->exec("
    CREATE TABLE IF NOT EXISTS community_app_files (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        app_key       VARCHAR(64)  NOT NULL,
        file_type     ENUM('dashboard','command','service') NOT NULL,
        source_name   VARCHAR(255) NOT NULL,
        dest_path     VARCHAR(512) NOT NULL,
        installed_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_app_key` (`app_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Bootstrap official apps (idempotent) ──────────────────────────────────────
$pdo->exec("INSERT IGNORE INTO apps
    (app_key, name, description, version, category, icon_svg, author, is_official, sidebar_view, schema_sql, db_tables)
VALUES
    ('arcenciel', 'Arc en Ciel',
     'KI-gestützte Bildgenerierung mit Stable Diffusion via Arc en Ciel API. Unterstützt /imagine, /img2img und /autotag.',
     '1.0.0', 'utility',
     '<path d=\"M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1ZM2.4 8.8a5.6 5.6 0 1 1 11.2 0 5.6 5.6 0 0 1-11.2 0Zm5.6-2.4a.8.8 0 0 0-.8.8v1.6H5.6a.8.8 0 0 0 0 1.6h1.6v1.6a.8.8 0 0 0 1.6 0V10.4h1.6a.8.8 0 0 0 0-1.6H8.8V7.2a.8.8 0 0 0-.8-.8Z\"/>',
     'BotHub', 1, 'arcenciel', NULL, '[\"bot_arcenciel_settings\"]')
");

// ── Template ZIP download  GET ?_action=template ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['_action'] ?? '') === 'template') {
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        exit('ZipArchive-Erweiterung nicht verfügbar.');
    }

    $tmpZip = tempnam(sys_get_temp_dir(), 'bh_app_tpl_') . '.zip';
    $zip    = new \ZipArchive();
    $zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

    $zip->addFromString('app.json', json_encode([
        'app_key'     => 'my_app',
        'name'        => 'My App',
        'description' => 'Eine kurze Beschreibung deiner App.',
        'version'     => '1.0.0',
        'category'    => 'custom',
        'author'      => 'Dein Name',
        'icon_svg'    => '<rect x="2" y="2" width="12" height="12" rx="2"/>',
        'sidebar_view'=> 'my_app',
        'db_tables'   => ['my_app_settings'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $zip->addFromString('schema.sql', <<<SQL
CREATE TABLE IF NOT EXISTS my_app_settings (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bot_id     INT UNSIGNED NOT NULL,
    enabled    TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bot (bot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

    $zip->addFromString('commands/example-command.js', <<<'JS'
// commands/example-command.js
const { SlashCommandBuilder } = require('discord.js');

module.exports = {
    data: new SlashCommandBuilder()
        .setName('my-app-hello')
        .setDescription('Sagt Hallo von My App'),

    async execute(interaction, botId) {
        await interaction.reply({ content: 'Hallo von My App!', ephemeral: true });
    },
};
JS);

    $zip->addFromString('services/example-service.js', <<<'JS'
// services/example-service.js

module.exports = {
    id: 'my_app',

    onReady(client, botId) {
        // Wird aufgerufen, sobald der Discord-Client bereit ist.
        // Hier können Event-Listener registriert werden:
        // client.on('messageCreate', msg => { ... });
    },

    async onInteraction(interaction, botId) {
        // Wird für alle Button- und Modal-Interaktionen aufgerufen.
        // Gibt true zurück, wenn diese Interaktion behandelt wurde.
        const id = interaction.customId || '';
        if (!id.startsWith('my_app_')) return false;

        await interaction.reply({ content: 'My App Button geklickt!', ephemeral: true });
        return true;
    },

    onStop(botId) {
        // Wird aufgerufen, wenn der Bot gestoppt wird.
        // Timer / Intervalle hier bereinigen.
    },
};
JS);

    $zip->addFromString('dashboard.php', <<<'PHP'
<?php
// dashboard/_app_my_app.php
// Dieses File wird automatisch in /dashboard/ installiert und im Sidebar verlinkt.

declare(strict_types=1);
if (!defined('BH_DASHBOARD')) { http_response_code(403); exit; }

$pdo = bh_pdo();
$botId = (int)($currentBotId ?? 0);

// Auto-Migrate
$pdo->exec("CREATE TABLE IF NOT EXISTS my_app_settings (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bot_id     INT UNSIGNED NOT NULL,
    enabled    TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bot (bot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// AJAX-Handler
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json; charset=utf-8');
    while (ob_get_level() > 0) ob_end_clean();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save') {
        $enabled = isset($_POST['enabled']) ? 1 : 0;
        $pdo->prepare("INSERT INTO my_app_settings (bot_id, enabled) VALUES (?,?)
                       ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)")
            ->execute([$botId, $enabled]);
        echo json_encode(['ok' => true]);
    } else {
        $row = $pdo->prepare("SELECT * FROM my_app_settings WHERE bot_id = ? LIMIT 1");
        $row->execute([$botId]);
        echo json_encode(['ok' => true, 'data' => $row->fetch(\PDO::FETCH_ASSOC) ?: []]);
    }
    exit;
}

$settings = $pdo->prepare("SELECT * FROM my_app_settings WHERE bot_id = ? LIMIT 1");
$settings->execute([$botId]);
$s = $settings->fetch(\PDO::FETCH_ASSOC) ?: ['enabled' => 0];
?>
<div class="p-6">
    <h2 class="text-xl font-bold mb-4">My App Einstellungen</h2>
    <label class="flex items-center gap-3">
        <input type="checkbox" id="ma-enabled" <?= $s['enabled'] ? 'checked' : '' ?>>
        <span>App aktiviert</span>
    </label>
    <button id="ma-save" class="mt-4 btn bg-violet-500 text-white px-4 py-2 rounded-lg text-sm">Speichern</button>
</div>
<script>
document.getElementById('ma-save').addEventListener('click', async function() {
    var form = new FormData();
    form.append('_action', 'save');
    if (document.getElementById('ma-enabled').checked) form.append('enabled', '1');
    var res = await fetch(location.href, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: form });
    var data = await res.json();
    alert(data.ok ? 'Gespeichert!' : 'Fehler: ' + (data.error || '?'));
});
</script>
PHP);

    $zip->addFromString('README.md', <<<'MD'
# BotHub Community App – Entwickler-Template

## ZIP-Format

```
{app_key}-{version}.zip
├── app.json          PFLICHT – Metadaten
├── dashboard.php     OPTIONAL → wird nach /dashboard/_app_{key}.php kopiert
├── schema.sql        OPTIONAL – wird beim Install ausgeführt (nur CREATE/ALTER/INSERT)
├── commands/
│   └── *.js          → nach core/installer/src/community-apps/commands/
└── services/
    └── *.js          → nach core/installer/src/community-apps/services/
```

## app.json Felder

| Feld          | Pflicht | Beschreibung                          |
|---------------|---------|---------------------------------------|
| app_key       | ✓       | Eindeutiger Key (a-z, 0-9, _)         |
| name          | ✓       | Anzeigename                           |
| description   |         | Kurzbeschreibung                      |
| version       |         | Versionsnummer (Standard: 1.0.0)      |
| category      |         | media/moderation/utility/fun/social/custom |
| author        |         | Entwicklername                        |
| icon_svg      |         | SVG-Pfad-Daten (16×16 viewBox)        |
| sidebar_view  |         | Dateiname für Dashboard-Seite (ohne _app_ prefix) |
| db_tables     |         | Array von Tabellennahmen              |

## Service-API

```js
module.exports = {
    id: 'my_app',
    onReady(client, botId) { },
    async onInteraction(interaction, botId) { return false; },
    onStop(botId) { },
};
```

## Sicherheitsregeln

- ZIP max. 5 MB, max. 20 Dateien
- Keine Symlinks, kein Path Traversal (`..`)
- schema.sql: nur CREATE TABLE/INDEX, ALTER TABLE, INSERT INTO/IGNORE
MD);

    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="community-app-template.zip"');
    header('Content-Length: ' . filesize($tmpZip));
    readfile($tmpZip);
    @unlink($tmpZip);
    exit;
}

// ── AJAX: install ─────────────────────────────────────────────────────────────
if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'install') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    bh_appstore_csrf_verify();

    $appKey = trim((string)($_POST['app_key'] ?? ''));
    if ($appKey === '') { echo json_encode(['ok' => false, 'error' => 'missing app_key']); exit; }

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
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: uninstall ───────────────────────────────────────────────────────────
if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'uninstall') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    bh_appstore_csrf_verify();

    $appKey = trim((string)($_POST['app_key'] ?? ''));
    if ($appKey === '') { echo json_encode(['ok' => false, 'error' => 'missing app_key']); exit; }

    // Check if official
    $appStmt = $pdo->prepare('SELECT is_official FROM apps WHERE app_key = ?');
    $appStmt->execute([$appKey]);
    $app = $appStmt->fetch(\PDO::FETCH_ASSOC);
    $isOfficial = $app && (int)$app['is_official'] === 1;

    try {
        $pdo->prepare("UPDATE installed_apps SET status = 'inactive' WHERE app_key = ?")
            ->execute([$appKey]);

        if (!$isOfficial) {
            // Delete all community files
            $fileStmt = $pdo->prepare('SELECT dest_path FROM community_app_files WHERE app_key = ?');
            $fileStmt->execute([$appKey]);
            foreach ($fileStmt->fetchAll(\PDO::FETCH_ASSOC) as $f) {
                @unlink((string)($f['dest_path'] ?? ''));
            }
            $pdo->prepare('DELETE FROM community_app_files WHERE app_key = ?')->execute([$appKey]);
            $pdo->prepare('DELETE FROM apps WHERE app_key = ? AND is_official = 0')->execute([$appKey]);

            bh_appstore_reload_all_runners();
        }

        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: upload ZIP ──────────────────────────────────────────────────────────
if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'upload_zip') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    bh_appstore_csrf_verify();

    $file = $_FILES['app_zip'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'Keine Datei hochgeladen oder Upload-Fehler.']);
        exit;
    }

    $validation = bh_appstore_validate_zip((string)($file['tmp_name'] ?? ''));
    if (!$validation['ok']) {
        echo json_encode(['ok' => false, 'error' => $validation['error']]);
        exit;
    }

    $appJson    = $validation['app_json'];
    $appKey     = $appJson['app_key'];
    $coreAppsCmd  = $projectRoot . '/core/installer/src/community-apps/commands';
    $coreAppsSvc  = $projectRoot . '/core/installer/src/community-apps/services';
    $dashboardDir = $projectRoot . '/dashboard';

    $validCategories = ['media', 'moderation', 'utility', 'fun', 'social', 'custom'];
    $category        = in_array($appJson['category'] ?? '', $validCategories, true) ? $appJson['category'] : 'custom';
    $dbTables        = is_array($appJson['db_tables'] ?? null) ? $appJson['db_tables'] : [];

    // Collect files to place from the ZIP
    $zip = new \ZipArchive();
    $zip->open((string)($file['tmp_name'] ?? ''), \ZipArchive::RDONLY);

    $filesToPlace = []; // [['src'=>name, 'type'=>..., 'dest'=>absPath, 'content'=>...], ...]
    $schemaSql    = '';

    $cmdPattern = '/^commands\/([a-z0-9_\-]+\.js)$/i';
    $svcPattern = '/^services\/([a-z0-9_\-]+\.js)$/i';

    for ($i = 0; $i < $zip->count(); $i++) {
        $stat = $zip->statIndex($i);
        if ($stat === false) continue;
        $name = $stat['name'];

        if ($name === 'dashboard.php') {
            $content = $zip->getFromName($name);
            if ($content !== false) {
                $filesToPlace[] = [
                    'src'     => $name,
                    'type'    => 'dashboard',
                    'dest'    => $dashboardDir . '/_app_' . $appKey . '.php',
                    'content' => $content,
                ];
            }
        } elseif ($name === 'schema.sql') {
            $schemaSql = (string)($zip->getFromName($name) ?: '');
        } elseif (preg_match($cmdPattern, $name, $m)) {
            $content = $zip->getFromName($name);
            if ($content !== false) {
                $filesToPlace[] = [
                    'src'     => $name,
                    'type'    => 'command',
                    'dest'    => $coreAppsCmd . '/' . $m[1],
                    'content' => $content,
                ];
            }
        } elseif (preg_match($svcPattern, $name, $m)) {
            $content = $zip->getFromName($name);
            if ($content !== false) {
                $filesToPlace[] = [
                    'src'     => $name,
                    'type'    => 'service',
                    'dest'    => $coreAppsSvc . '/' . $m[1],
                    'content' => $content,
                ];
            }
        }
    }
    $zip->close();

    // Validate schema SQL if present
    if ($schemaSql !== '' && !bh_appstore_validate_schema($schemaSql)) {
        echo json_encode(['ok' => false, 'error' => 'schema.sql enthält unzulässige SQL-Anweisungen.']);
        exit;
    }

    // Ensure target directories exist
    @mkdir($coreAppsCmd, 0755, true);
    @mkdir($coreAppsSvc, 0755, true);

    $pdo->beginTransaction();
    $writtenFiles = [];
    try {
        $adminId = (int)($adminUser['id'] ?? 0);

        // Upsert app record
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
            mb_substr((string)($appJson['name']         ?? ''), 0, 128),
            mb_substr((string)($appJson['description']  ?? ''), 0, 2000),
            mb_substr((string)($appJson['version']      ?? '1.0.0'), 0, 32),
            $category,
            mb_substr((string)($appJson['icon_svg']     ?? ''), 0, 2000),
            mb_substr((string)($appJson['author']       ?? 'Community'), 0, 128),
            mb_substr((string)($appJson['sidebar_view'] ?? ''), 0, 128),
            $schemaSql ?: null,
            json_encode($dbTables),
        ]);

        // Execute schema SQL
        if ($schemaSql !== '') {
            foreach (array_filter(array_map('trim', explode(';', $schemaSql))) as $sqlStmt) {
                if ($sqlStmt !== '') $pdo->exec($sqlStmt);
            }
        }

        // Mark as installed
        $pdo->prepare("INSERT INTO installed_apps (app_key, installed_by, status) VALUES (?,?,'active')
                       ON DUPLICATE KEY UPDATE status = 'active', installed_by = VALUES(installed_by)")
            ->execute([$appKey, $adminId]);

        // Remove old community files for this app
        $oldFileStmt = $pdo->prepare('SELECT dest_path FROM community_app_files WHERE app_key = ?');
        $oldFileStmt->execute([$appKey]);
        foreach ($oldFileStmt->fetchAll(\PDO::FETCH_ASSOC) as $f) {
            @unlink((string)($f['dest_path'] ?? ''));
        }
        $pdo->prepare('DELETE FROM community_app_files WHERE app_key = ?')->execute([$appKey]);

        // Write new files and record them
        $insertFile = $pdo->prepare(
            'INSERT INTO community_app_files (app_key, file_type, source_name, dest_path) VALUES (?,?,?,?)'
        );
        foreach ($filesToPlace as $f) {
            if (file_put_contents($f['dest'], $f['content']) === false) {
                throw new \RuntimeException("Datei konnte nicht geschrieben werden: {$f['dest']}");
            }
            $writtenFiles[] = $f['dest'];
            $insertFile->execute([$appKey, $f['type'], $f['src'], $f['dest']]);
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        foreach ($writtenFiles as $wf) { @unlink($wf); }
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }

    bh_appstore_reload_all_runners();

    echo json_encode(['ok' => true, 'app_key' => $appKey, 'files_placed' => count($filesToPlace)]);
    exit;
}

// ── AJAX: legacy JSON upload (kept for backwards compat) ──────────────────────
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
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Page data ─────────────────────────────────────────────────────────────────
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
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Installiere offizielle und Community-Apps für deinen Bot.</p>
            </div>
            <div class="flex items-center gap-3">
                <a href="?_action=template"
                   class="btn bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
                    <svg class="w-4 h-4" viewBox="0 0 16 16" fill="currentColor"><path d="M4.5 3a.5.5 0 0 0 0 1h7a.5.5 0 0 0 0-1h-7ZM2 7.5A.5.5 0 0 1 2.5 7h11a.5.5 0 0 1 0 1h-11a.5.5 0 0 1-.5-.5ZM2.5 11a.5.5 0 0 0 0 1h7a.5.5 0 0 0 0-1h-7Z"/></svg>
                    Developer-Template
                </a>
                <button type="button" id="upload-btn"
                    class="btn bg-violet-500 hover:bg-violet-600 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
                    <svg class="w-4 h-4" viewBox="0 0 16 16" fill="currentColor"><path d="M8 1a.5.5 0 0 1 .5.5v5h5a.5.5 0 0 1 0 1h-5v5a.5.5 0 0 1-1 0v-5h-5a.5.5 0 0 1 0-1h5v-5A.5.5 0 0 1 8 1Z"/></svg>
                    Community App installieren
                </button>
            </div>
        </div>

        <!-- Toast -->
        <div id="store-toast" class="hidden mb-6 px-4 py-3 rounded-lg text-sm"></div>

        <!-- Upload Modal -->
        <div id="upload-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl p-6 w-full max-w-lg mx-4">
                <h2 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-2">Community App installieren</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                    Lade ein <code>.zip</code>-Paket hoch (max. 5 MB, max. 20 Dateien).
                    Das ZIP muss eine <code>app.json</code> mit <code>app_key</code> und <code>name</code> enthalten.
                    Lade das <a href="?_action=template" class="text-violet-500 hover:underline">Developer-Template</a> herunter, um loszulegen.
                </p>
                <input type="file" id="upload-file" accept=".zip" class="block w-full text-sm text-gray-600 dark:text-gray-400 mb-4
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
                        Installieren
                    </button>
                </div>
            </div>
        </div>

        <!-- App grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-6" id="app-grid">
            <?php foreach ($apps as $app):
                $key        = (string)$app['app_key'];
                $isInstalled = isset($installed[$key]);
                $isOfficial  = (int)($app['is_official'] ?? 0) === 1;
                $catColor    = $categoryColors[$app['category']] ?? $categoryColors['custom'];
                $catLabel    = $categoryLabels[$app['category']] ?? 'Custom';
                $tables      = is_string($app['db_tables'] ?? null) ? (json_decode((string)$app['db_tables'], true) ?: []) : [];
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
                                <?php if ($isOfficial): ?>
                                <span class="ml-1 text-xs bg-violet-100 dark:bg-violet-500/20 text-violet-600 dark:text-violet-300 px-1.5 py-0.5 rounded font-medium">Official</span>
                                <?php else: ?>
                                <span class="ml-1 text-xs bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-300 px-1.5 py-0.5 rounded font-medium">Community</span>
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
                    <?php if (!$isOfficial): ?>
                    <button type="button" class="app-uninstall-btn text-xs text-red-500 hover:text-red-700 font-medium" data-key="<?= h($key) ?>" data-label="Löschen">
                        Löschen
                    </button>
                    <?php else: ?>
                    <button type="button" class="app-uninstall-btn text-xs text-gray-400 hover:text-red-500 font-medium" data-key="<?= h($key) ?>" data-label="Deaktivieren">
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
        t._t = setTimeout(function () { t.className = 'hidden'; }, 5000);
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

    // Install
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

    // Uninstall / Delete
    document.querySelectorAll('.app-uninstall-btn').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            var key   = btn.dataset.key;
            var label = btn.dataset.label || 'Deinstallieren';
            if (!confirm('App "' + key + '" ' + label + '?')) return;
            var data = await postAction({ _action: 'uninstall', app_key: key });
            if (data.ok) {
                showToast('App ' + label.toLowerCase() + '.', true);
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

        var submitBtn = this;
        submitBtn.disabled = true;
        submitBtn.textContent = 'Installiere…';

        var form = new FormData();
        form.append('_action', 'upload_zip');
        form.append('csrf_token', csrfToken);
        form.append('app_zip', file);

        try {
            var res  = await fetch(url, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: form
            });
            var data = await res.json();
            modal.classList.add('hidden');

            if (data.ok) {
                showToast('App "' + data.app_key + '" installiert (' + (data.files_placed || 0) + ' Dateien).', true);
                setTimeout(function () { location.reload(); }, 800);
            } else {
                showToast('Fehler: ' + (data.error || 'Unbekannt'), false);
            }
        } catch (e) {
            showToast('Netzwerkfehler beim Upload.', false);
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Installieren';
        }
    });
}());
</script>
<?php
$contentHtml = (string)ob_get_clean();
require __DIR__ . '/_layout.php';
