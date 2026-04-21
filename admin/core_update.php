<?php
declare(strict_types=1);

ob_start();

session_start();

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/functions/admin_guard.php';
require_once $projectRoot . '/functions/html.php';
require_once $projectRoot . '/functions/custom_commands.php';

$adminUser = bh_admin_require_user();

// ── AJAX: core self-update ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'core_update') {
    ob_end_clean(); // discard any accidental output (warnings, notices)
    header('Content-Type: application/json');
    try {
        $version = trim((string)($_POST['version'] ?? ''));
        $results = bh_core_update_runners($version);
        echo json_encode(['results' => $results]);
    } catch (Throwable $e) {
        echo json_encode(['results' => [['ok' => false, 'message' => $e->getMessage()]]]);
    }
    exit;
}
$pageTitle = 'Core Update';

$appCfgPath = $projectRoot . '/db/config/app.php';
$secretCfgPath = $projectRoot . '/db/config/secret.php';
$coreTemplateDir = $projectRoot . '/core/installer';

$baseUrl = '';
$appKeyPresent = false;
$errors = [];

if (!is_dir($coreTemplateDir)) {
    $errors[] = 'Core-Template fehlt: ' . $coreTemplateDir;
}

if (!is_file($appCfgPath) || !is_readable($appCfgPath)) {
    $errors[] = 'App-Config fehlt: ' . $appCfgPath;
} else {
    $appCfg = require $appCfgPath;
    if (!is_array($appCfg)) {
        $errors[] = 'App-Config ist ungültig.';
    } else {
        $baseUrl = trim((string)($appCfg['base_url'] ?? ''));
    }
}

if (!is_file($secretCfgPath) || !is_readable($secretCfgPath)) {
    $errors[] = 'Secret-Datei fehlt: ' . $secretCfgPath;
} else {
    $secretCfg = require $secretCfgPath;
    if (!is_array($secretCfg)) {
        $errors[] = 'Secret-Datei ist ungültig.';
    } else {
        $appKeyPresent = trim((string)($secretCfg['APP_KEY'] ?? '')) !== '';
        if (!$appKeyPresent) {
            $errors[] = 'APP_KEY fehlt in secret.php';
        }
    }
}

ob_start();
?>
<main class="grow">
    <div class="px-4 sm:px-6 lg:px-8 py-8 w-full max-w-7xl mx-auto">
        <div class="mb-8">
            <h1 class="text-2xl md:text-3xl text-gray-800 dark:text-gray-100 font-bold">
                Core Update
            </h1>
            <div class="text-sm text-gray-500 dark:text-gray-400 mt-2">
                Erzeugt eine neue <code>core.zip</code> mit dem bestehenden APP_KEY und der aktuellen Website URL.
            </div>
        </div>

        <?php foreach ($errors as $error): ?>
            <div class="mb-4 rounded-xl border border-rose-200 dark:border-rose-700/60 bg-rose-50 dark:bg-rose-500/10 px-4 py-3 text-sm text-rose-700 dark:text-rose-300">
                <?= h($error) ?>
            </div>
        <?php endforeach; ?>

        <?php if (count($errors) === 0): ?>
        <!-- Core Self-Update -->
        <div class="mb-6 bg-white dark:bg-gray-800 shadow-xs rounded-xl">
            <div class="p-5 border-b border-gray-100 dark:border-gray-700/60">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Core neu installieren</h2>
            </div>
            <div class="p-5">
                <div class="text-sm text-gray-500 dark:text-gray-400 mb-5">
                    Führt <code>npm install --production</code> im laufenden Core-Runner aus und startet ihn danach automatisch neu (PM2 / systemd übernimmt den Neustart).
                    Der Core ist für ca. 5–15 Sekunden nicht erreichbar.
                </div>
                <div class="flex items-center gap-3 mb-4">
                    <input
                        type="text"
                        id="inputCoreVersion"
                        placeholder="Version (z.B. 1.2.3) — optional"
                        class="form-input w-52 text-sm"
                    >
                </div>
                <button
                    id="btnCoreUpdate"
                    type="button"
                    class="btn bg-violet-500 hover:bg-violet-600 text-white"
                >
                    Core neu installieren
                </button>
                <div id="coreUpdateStatus" class="mt-4 hidden text-sm"></div>
            </div>
        </div>
        <script>
        document.getElementById('btnCoreUpdate').addEventListener('click', function () {
            const btn    = this;
            const status = document.getElementById('coreUpdateStatus');

            btn.disabled    = true;
            btn.textContent = 'Wird gestartet…';
            status.className = 'mt-4 text-sm text-gray-500 dark:text-gray-400';
            status.textContent = 'Anfrage wird gesendet…';
            status.classList.remove('hidden');

            const version = document.getElementById('inputCoreVersion').value.trim();
            const fd = new FormData();
            fd.append('action', 'core_update');
            if (version !== '') fd.append('version', version);

            fetch(window.location.pathname, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    const results = data.results || [];
                    let html = '<ul class="space-y-1">';
                    results.forEach(r => {
                        const icon  = r.ok ? '✅' : '❌';
                        const ep    = r.endpoint ? ` <span class="text-gray-400">(${r.endpoint})</span>` : '';
                        html += `<li>${icon} ${r.message}${ep}</li>`;
                    });
                    html += '</ul>';
                    const allOk = results.length > 0 && results.every(r => r.ok);
                    status.className = 'mt-4 text-sm ' + (allOk
                        ? 'text-emerald-600 dark:text-emerald-400'
                        : 'text-rose-600 dark:text-rose-400');
                    status.innerHTML = html;
                    btn.disabled    = false;
                    btn.textContent = 'Core neu installieren';
                })
                .catch(err => {
                    status.className  = 'mt-4 text-sm text-rose-600 dark:text-rose-400';
                    status.textContent = 'Netzwerkfehler: ' + err.message;
                    btn.disabled    = false;
                    btn.textContent = 'Core neu installieren';
                });
        });
        </script>
        <?php endif; ?>

        <div class="grid grid-cols-12 gap-6">
            <div class="col-span-full xl:col-span-4 bg-white dark:bg-gray-800 shadow-xs rounded-xl">
                <div class="p-5 border-b border-gray-100 dark:border-gray-700/60">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Aktuelle Config</h2>
                </div>

                <div class="p-5 space-y-4">
                    <div>
                        <div class="text-xs uppercase tracking-wider text-gray-400 dark:text-gray-500">Base URL</div>
                        <div class="mt-1 text-sm text-gray-800 dark:text-gray-100 break-all">
                            <?= h($baseUrl !== '' ? $baseUrl : 'Nicht gesetzt') ?>
                        </div>
                    </div>

                    <div>
                        <div class="text-xs uppercase tracking-wider text-gray-400 dark:text-gray-500">APP_KEY</div>
                        <div class="mt-1 text-sm <?= $appKeyPresent ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' ?>">
                            <?= $appKeyPresent ? 'Vorhanden' : 'Fehlt' ?>
                        </div>
                    </div>

                    <div>
                        <div class="text-xs uppercase tracking-wider text-gray-400 dark:text-gray-500">Core Template</div>
                        <div class="mt-1 text-sm text-gray-800 dark:text-gray-100 break-all">
                            <?= h($coreTemplateDir) ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-span-full xl:col-span-8 bg-white dark:bg-gray-800 shadow-xs rounded-xl">
                <div class="p-5 border-b border-gray-100 dark:border-gray-700/60">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Neue Core.zip erzeugen</h2>
                </div>

                <div class="p-5">
                    <div class="text-sm text-gray-500 dark:text-gray-400 mb-5">
                        Diese ZIP nutzt automatisch den bestehenden APP_KEY aus <code>/db/config/secret.php</code> und die aktuelle <code>base_url</code> aus <code>/db/config/app.php</code>.
                    </div>

                    <?php if (count($errors) === 0): ?>
                        <a
                            href="/admin/download-core"
                            class="btn bg-violet-500 hover:bg-violet-600 text-white"
                        >
                            Core.zip herunterladen
                        </a>
                    <?php else: ?>
                        <div class="text-sm text-rose-600 dark:text-rose-400">
                            Core.zip kann aktuell nicht erzeugt werden, bis die Fehler oben behoben sind.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>
<?php
$contentHtml = (string)ob_get_clean();

require __DIR__ . '/_layout.php';