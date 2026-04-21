<?php
declare(strict_types=1);

session_start();

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/functions/admin_guard.php';
require_once $projectRoot . '/functions/html.php';

$adminUser = bh_admin_require_user();
$pageTitle = 'Danger Zone';

$error   = null;
$deleted = false;

$configFiles = [
    $projectRoot . '/db/config/app.php',
    $projectRoot . '/db/config/install.lock',
    $projectRoot . '/db/config/secret.php',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $confirm1 = (string)($_POST['confirm_delete'] ?? '');
    $confirm2 = (string)($_POST['confirm_type']   ?? '');

    if ($confirm1 !== '1' || $confirm2 !== 'DELETE') {
        $error = 'Bitte beide Bestätigungen ausfüllen.';
    } else {
        $allGone = true;
        foreach ($configFiles as $file) {
            if (file_exists($file)) {
                if (!@unlink($file)) {
                    $allGone = false;
                    $error = 'Konnte Datei nicht löschen: ' . basename($file);
                    break;
                }
            }
        }
        if ($allGone && $error === null) {
            // Session destroy so no stale admin session remains
            session_destroy();
            header('Location: /install', true, 302);
            exit;
        }
    }
}

ob_start();
?>
<main class="grow">
    <div class="px-4 sm:px-6 lg:px-8 py-8 w-full max-w-4xl mx-auto">

        <div class="mb-8">
            <h1 class="text-2xl md:text-3xl text-gray-800 dark:text-gray-100 font-bold">
                Danger Zone
            </h1>
            <div class="text-sm text-gray-500 dark:text-gray-400 mt-2">
                Irreversible Aktionen — bitte mit Bedacht verwenden.
            </div>
        </div>

        <?php if ($error !== null): ?>
            <div class="mb-6 rounded-xl border border-rose-200 dark:border-rose-700/60 bg-rose-50 dark:bg-rose-500/10 px-4 py-3 text-sm text-rose-700 dark:text-rose-300">
                <?= h($error) ?>
            </div>
        <?php endif; ?>

        <!-- Delete Instance -->
        <div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl border border-rose-200 dark:border-rose-700/40">
            <div class="p-5 border-b border-rose-100 dark:border-rose-700/40 flex items-center gap-3">
                <div class="w-9 h-9 rounded-lg bg-rose-100 dark:bg-rose-500/20 flex items-center justify-center shrink-0">
                    <svg class="fill-current text-rose-500" width="16" height="16" viewBox="0 0 16 16">
                        <path d="M6 2h4a2 2 0 0 1 2 2v1h2v1h-1l-.857 8H3.857L3 6H2V5h2V4a2 2 0 0 1 2-2Zm1 2v1h2V4H7ZM4.143 6l.714 7h6.286l.714-7H4.143ZM6 7h1v5H6V7Zm3 0h1v5H9V7Z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Installation löschen</h2>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                        Löscht alle Konfigurationsdateien und setzt BotHub auf den Installationszustand zurück.
                    </div>
                </div>
            </div>

            <div class="p-5">
                <!-- What gets deleted -->
                <div class="rounded-lg bg-rose-50 dark:bg-rose-500/10 border border-rose-200 dark:border-rose-700/40 p-4 mb-5">
                    <div class="text-xs font-semibold text-rose-700 dark:text-rose-300 mb-2">Folgende Dateien werden unwiderruflich gelöscht:</div>
                    <ul class="space-y-1">
                        <?php foreach ($configFiles as $file): ?>
                            <li class="flex items-center gap-2 text-xs font-mono text-rose-600 dark:text-rose-400">
                                <span class="w-4 text-center">
                                    <?= file_exists($file) ? '✓' : '–' ?>
                                </span>
                                db/config/<?= h(basename($file)) ?>
                                <?php if (!file_exists($file)): ?>
                                    <span class="text-gray-400 dark:text-gray-500 font-sans">(nicht vorhanden)</span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="mt-3 text-xs text-rose-600 dark:text-rose-400">
                        Anschließend wirst du auf <strong>/install</strong> weitergeleitet. Die Datenbank selbst wird <strong>nicht</strong> gelöscht.
                    </div>
                </div>

                <!-- Confirmation form -->
                <form method="post" id="danger-form" onsubmit="return bhDangerConfirm()">
                    <div class="space-y-4">
                        <label class="flex items-start gap-3 cursor-pointer group">
                            <input
                                type="checkbox"
                                name="confirm_delete"
                                value="1"
                                id="confirm_delete"
                                class="mt-0.5 rounded border-gray-300 dark:border-gray-600 text-rose-500 focus:ring-rose-500"
                            >
                            <span class="text-sm text-gray-700 dark:text-gray-300">
                                Ich verstehe, dass diese Aktion nicht rückgängig gemacht werden kann und BotHub neu installiert werden muss.
                            </span>
                        </label>

                        <div>
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5" for="confirm_type">
                                Zur Bestätigung <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded text-rose-600 dark:text-rose-400">DELETE</code> eingeben:
                            </label>
                            <input
                                type="text"
                                name="confirm_type"
                                id="confirm_type"
                                placeholder="DELETE"
                                autocomplete="off"
                                class="form-input w-48 bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-700 text-gray-900 dark:text-gray-100 font-mono"
                            >
                        </div>
                    </div>

                    <div class="mt-5 pt-5 border-t border-gray-100 dark:border-gray-700/60">
                        <button
                            type="submit"
                            id="danger-btn"
                            class="inline-flex items-center gap-2 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-sm font-semibold px-4 py-2 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <svg class="fill-current" width="14" height="14" viewBox="0 0 16 16">
                                <path d="M6 2h4a2 2 0 0 1 2 2v1h2v1h-1l-.857 8H3.857L3 6H2V5h2V4a2 2 0 0 1 2-2Zm1 2v1h2V4H7ZM4.143 6l.714 7h6.286l.714-7H4.143ZM6 7h1v5H6V7Zm3 0h1v5H9V7Z"/>
                            </svg>
                            Installation löschen
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>
</main>
<script>
function bhDangerConfirm() {
    const cb    = document.getElementById('confirm_delete');
    const input = document.getElementById('confirm_type');
    if (!cb.checked) {
        alert('Bitte das Kontrollkästchen aktivieren.');
        return false;
    }
    if (input.value.trim() !== 'DELETE') {
        alert('Bitte exakt "DELETE" eingeben.');
        input.focus();
        return false;
    }
    return confirm('LETZTE WARNUNG: Alle Konfigurationsdateien werden gelöscht und du wirst zur Installation weitergeleitet. Fortfahren?');
}
</script>
<?php
$contentHtml = (string)ob_get_clean();
require __DIR__ . '/_layout.php';
