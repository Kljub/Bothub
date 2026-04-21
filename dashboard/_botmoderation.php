<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/functions/custom_commands.php';
require_once dirname(__DIR__) . '/functions/db_functions/commands.php';
require_once dirname(__DIR__) . '/functions/module_toggle.php';

$pdo = bh_get_pdo();

/** @var int|null $currentBotId */
$botId = isset($currentBotId) && $currentBotId > 0 ? $currentBotId : (int)($_GET['bot_id'] ?? 0);

if ($botId <= 0) {
?>
<div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl p-5">
    <div class="text-sm text-rose-600 dark:text-rose-400">Bot nicht gefunden.</div>
</div>
<?php
return;
}

$commands = [
    // ── Allgemein ───────────────────────────────────────────────────────────────
    ['key' => 'purge',    'name' => '/purge',    'description' => 'Löscht mehrere Nachrichten vor dem Command.'],
    ['key' => 'warn',     'name' => '/warn',     'description' => 'Verwarnt einen User.'],
    ['key' => 'kick',     'name' => '/kick',     'description' => 'Kickt einen User vom Server.'],
    ['key' => 'automods', 'name' => '/automods', 'description' => 'Zeigt die Statusseite der Automods an.'],
    // ── Gruppen (Subcommands: add / temp / remove / list …) ─────────────────────
    ['key' => 'ban',     'name' => '/ban',     'description' => 'Ban-Befehle: add, temp, remove, list.'],
    ['key' => 'mute',    'name' => '/mute',    'description' => 'Mute-Befehle: add, remove, role, view.'],
    ['key' => 'case',    'name' => '/case',    'description' => 'Case-Befehle: view, remove.'],
    ['key' => 'user',    'name' => '/user',    'description' => 'User-Befehle: history, clear-history, nick.'],
    ['key' => 'channel', 'name' => '/channel', 'description' => 'Channel-Befehle: lock, unlock, slowmode.'],
    ['key' => 'voice',   'name' => '/voice',   'description' => 'Voice-Befehle: deaf, undeaf.'],
];

// ── Fix: ensure existing moderation commands have the right command_type ──
try { bhcmd_fix_type($pdo, $botId, array_column($commands, 'key'), 'moderation'); } catch (Throwable) {}

$flashOk  = null;
$flashErr = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bh_mod_handle_ajax($pdo, $botId);
    try {
        $pdo->beginTransaction();

        foreach ($commands as $cmd) {
            $key     = $cmd['key'];
            $enabled = (($_POST['enabled'][$key] ?? '0') === '1') ? 1 : 0;

            $settingsRaw  = $_POST['settings_json'][$key] ?? null;
            $settingsJson = null;
            if ($settingsRaw !== null) {
                $decoded = json_decode($settingsRaw, true);
                if (is_array($decoded)) {
                    $settingsJson = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                }
            }

            bhcmd_upsert_command($pdo, $botId, $key, 'moderation', $cmd['name'], $cmd['description'], $enabled, $settingsJson);
        }

        $pdo->commit();
        $flashOk = 'Gespeichert.';
        bh_notify_slash_sync($botId);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flashErr = 'Speichern fehlgeschlagen: ' . $e->getMessage();
    }
}

$rows = [];
try { $rows = bhcmd_load_by_type($pdo, $botId, 'moderation'); } catch (Throwable) {}
$enabledCommands  = [];
$settingsCommands = [];
foreach ($rows as $row) {
    $k = (string)$row['command_key'];
    $enabledCommands[$k]  = (int)$row['is_enabled'];
    $raw = $row['settings_json'];
    $settingsCommands[$k] = ($raw !== null) ? (json_decode($raw, true) ?: []) : [];
}

// Build JS data
$jsCmdData = ['botId' => $botId, 'commands' => []];
foreach ($commands as $cmd) {
    $k = $cmd['key'];
    $jsCmdData['commands'][$k] = ['settings' => $settingsCommands[$k] ?? []];
}
$modEnabled = bh_mod_is_enabled($pdo, $botId, 'module:moderation');
?>

<?= bh_mod_render($modEnabled, $botId, 'module:moderation', 'Moderation', 'Alle Moderations-Commands für diesen Bot ein- oder ausschalten.') ?>
<div id="bh-mod-body">
<div class="grid grid-cols-12 gap-6">
    <div class="col-span-full bg-white dark:bg-gray-800 shadow-xs rounded-xl">
        <div class="p-5 border-b border-gray-100 dark:border-gray-700/60">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">
                Moderation Commands
            </h2>
        </div>

        <div class="p-5">
            <?php if ($flashOk !== null): ?>
                <div class="mb-4 rounded-xl border border-emerald-200 dark:border-emerald-700/60 bg-emerald-50 dark:bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-300">
                    <?= htmlspecialchars($flashOk, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if ($flashErr !== null): ?>
                <div class="mb-4 rounded-xl border border-rose-200 dark:border-rose-700/60 bg-rose-50 dark:bg-rose-500/10 px-4 py-3 text-sm text-rose-700 dark:text-rose-300">
                    <?= htmlspecialchars($flashErr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="post" data-autosave>
                <div class="space-y-4">
                    <?php foreach ($commands as $cmd): ?>
                        <?php
                        $key          = (string)$cmd['key'];
                        $enabled      = !empty($enabledCommands[$key]);
                        $settingsData = $settingsCommands[$key] ?? [];
                        $settingsJson = json_encode($settingsData, JSON_UNESCAPED_UNICODE);
                        ?>
                        <div class="command-card p-4">
                            <div class="command-header flex items-center justify-between gap-6">
                                <div>
                                    <div class="text-base font-semibold text-gray-800 dark:text-gray-100">
                                        <?= htmlspecialchars((string)$cmd['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                    </div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                        <?= htmlspecialchars((string)$cmd['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                    </div>
                                </div>

                                <label class="toggle">
                                    <input type="hidden"
                                           name="enabled[<?= htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>]"
                                           value="0">
                                    <input type="checkbox"
                                           name="enabled[<?= htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>]"
                                           value="1"
                                           <?= $enabled ? 'checked' : '' ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>

                            <input type="hidden"
                                   name="settings_json[<?= htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>]"
                                   class="bh-settings-json"
                                   data-command-key="<?= htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                   value="<?= htmlspecialchars($settingsJson, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

                            <div class="command-panel">
                                <div class="bh-perm-panel"
                                     data-command-key="<?= htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="mt-6 flex justify-end">
                    <button type="submit" class="btn bg-violet-500 hover:bg-violet-600 text-white">
                        Save All
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
</div><!-- /bh-mod-body -->

<script>
window.BhCmdData = <?= json_encode($jsCmdData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
</script>
