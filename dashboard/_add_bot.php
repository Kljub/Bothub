<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/functions/html.php';
require_once $projectRoot . '/functions/bot_token.php';
require_once $projectRoot . '/functions/custom_commands.php';
require_once __DIR__ . '/../discord/discord_api.php';

$userId = isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])
    ? (int)$_SESSION['user_id']
    : 0;

$errors = [];
$success = null;

if ($userId <= 0) {
    $errors[] = 'Ungültige Session.';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $userId > 0) {
    $csrfPost = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrfPost)) {
        $errors[] = 'Ungültige CSRF-Token. Bitte Seite neu laden.';
    }

    $token = trim((string)($_POST['bot_token'] ?? ''));

    if ($token === '') {
        $errors[] = 'Discord Token fehlt.';
    }

    if (!$errors) {
        $discordMe = discord_api_get_me($token);

        if (!$discordMe['ok']) {
            $errors[] = 'Discord Token ungültig oder API nicht erreichbar: ' . (string)($discordMe['error'] ?? 'Unbekannter Fehler');
        } else {
            $data = $discordMe['data'] ?? null;

            if (!is_array($data)) {
                $errors[] = 'Discord API Antwort ungültig.';
            } else {
                $discordUserIdRaw = trim((string)($data['id'] ?? ''));
                $username = trim((string)($data['username'] ?? ''));
                $globalName = trim((string)($data['global_name'] ?? ''));

                if ($discordUserIdRaw === '' || $username === '') {
                    $errors[] = 'Discord API lieferte keine gültigen Bot-Daten.';
                } else {
                    $displayName = $globalName !== '' ? $globalName : $username;

                    try {
                        $pdo = bh_get_pdo();

                        $check = $pdo->prepare('SELECT id FROM bot_instances WHERE owner_user_id = ? AND discord_bot_user_id = ? LIMIT 1');
                        $check->execute([$userId, $discordUserIdRaw]);
                        $existing = $check->fetch();

                        if ($existing) {
                            $errors[] = 'Dieser Bot wurde bereits hinzugefügt.';
                        } else {
                            $stmt = $pdo->prepare("
                                INSERT INTO bot_instances
                                (
                                    owner_user_id,
                                    display_name,
                                    discord_app_id,
                                    discord_bot_user_id,
                                    bot_token_encrypted,
                                    bot_token_enc_meta,
                                    desired_state,
                                    runtime_status,
                                    is_active
                                )
                                VALUES
                                (
                                    ?,
                                    ?,
                                    NULL,
                                    ?,
                                    ?,
                                    ?,
                                    'stopped',
                                    'unknown',
                                    1
                                )
                            ");

                            $tokenEnc = bh_bot_token_encrypt($token);

                            $stmt->execute([
                                $userId,
                                $displayName,
                                $discordUserIdRaw,
                                $tokenEnc['encrypted'],
                                $tokenEnc['meta'],
                            ]);

                            $newBotId = (int)$pdo->lastInsertId();

                            // Auto-assign to the runner with the fewest bots
                            try {
                                $assignStmt = $pdo->query("
                                    SELECT cr.runner_name, COUNT(bi.id) AS bot_count
                                    FROM core_runners cr
                                    LEFT JOIN bot_instances bi
                                        ON bi.assigned_runner_name = cr.runner_name
                                       AND bi.is_active = 1
                                    GROUP BY cr.runner_name
                                    ORDER BY bot_count ASC, cr.id ASC
                                    LIMIT 1
                                ");
                                $leastLoaded = $assignStmt ? $assignStmt->fetch() : null;
                                if (is_array($leastLoaded) && isset($leastLoaded['runner_name']) && $leastLoaded['runner_name'] !== '') {
                                    $assignUpdate = $pdo->prepare("UPDATE bot_instances SET assigned_runner_name = ? WHERE id = ?");
                                    $assignUpdate->execute([$leastLoaded['runner_name'], $newBotId]);
                                }
                            } catch (Throwable) {}

                            // Notify running core runner to pick up the new bot
                            try {
                                bh_notify_bot_reload($newBotId);
                            } catch (Throwable) {}

                            // Check if any core runner is online for the notice
                            $runnerOnline = false;
                            try {
                                $runnerCheck = $pdo->query("SELECT id FROM core_runners WHERE endpoint != '' LIMIT 1");
                                $runnerOnline = (bool)$runnerCheck->fetch();
                            } catch (Throwable) {}

                            $success = $runnerOnline
                                ? 'Bot wurde hinzugefügt.'
                                : 'Bot wurde hinzugefügt. Kein Core-Runner aktiv — starte den Core, damit der Bot online gehen kann.';
                        }
                    } catch (Throwable $e) {
                        error_log('[BotHub] add_bot DB error: ' . $e->getMessage());
                        $errors[] = 'Ein interner Fehler ist aufgetreten. Bitte versuche es erneut.';
                    }
                }
            }
        }
    }
}
?>

<div class="px-4 sm:px-6 lg:px-8 py-8 w-full max-w-3xl mx-auto">

    <div class="mb-8">
        <h1 class="text-2xl md:text-3xl text-gray-800 dark:text-gray-100 font-bold">
            Bot hinzufügen
        </h1>
        <div class="text-sm text-gray-500 dark:text-gray-400 mt-2">
            Es wird nur der Discord Bot Token benötigt. Name und Bot-ID werden automatisch über die Discord API geladen.
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="mb-6 bg-red-500/20 border border-red-500 text-red-200 px-4 py-3 rounded-lg">
            <?php foreach ($errors as $e): ?>
                <div><?= h((string)$e) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($success !== null): ?>
        <div class="mb-6 bg-green-500/20 border border-green-500 text-green-200 px-4 py-3 rounded-lg">
            <?= h($success) ?>
        </div>
    <?php endif; ?>

    <div class="bg-white dark:bg-gray-800 shadow-xs rounded-xl border border-gray-200 dark:border-gray-700/60">
        <div class="p-5 border-b border-gray-200 dark:border-gray-700/60">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Discord Bot verbinden</h2>
        </div>

        <div class="p-5">
            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                <div class="mb-5">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Discord Bot Token
                    </label>
                    <input
                        type="password"
                        name="bot_token"
                        class="form-input w-full bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-700 text-gray-900 dark:text-gray-100"
                        required
                    >
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                        Der Token wird geprüft und die Bot-Infos werden automatisch von Discord übernommen.
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <button
                        class="btn bg-violet-500 hover:bg-violet-600 text-white"
                        type="submit"
                    >
                        Bot hinzufügen
                    </button>

                    <a
                        class="btn border-gray-200 dark:border-gray-700/60 hover:border-gray-300 dark:hover:border-gray-600 text-gray-600 dark:text-gray-300"
                        href="/dashboard"
                    >
                        Abbrechen
                    </a>
                </div>
            </form>
        </div>
    </div>

</div>