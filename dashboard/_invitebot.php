<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/functions/html.php';

$botId = isset($_GET['bot_id']) && is_numeric($_GET['bot_id'])
    ? (int)$_GET['bot_id']
    : 0;

$clientId = '';
$dbError = null;

if ($botId > 0) {
    try {
        $pdo = bh_get_pdo();

        $stmt = $pdo->prepare("
            SELECT discord_app_id, discord_bot_user_id
            FROM bot_instances
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $botId,
        ]);

        $row = $stmt->fetch();

        if (is_array($row)) {
            $discordAppId = trim((string)($row['discord_app_id'] ?? ''));
            $discordBotUserId = trim((string)($row['discord_bot_user_id'] ?? ''));

            if ($discordAppId !== '') {
                $clientId = $discordAppId;
            } elseif ($discordBotUserId !== '') {
                $clientId = $discordBotUserId;
            }
        }
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

$inviteUrl = '';
if ($clientId !== '') {
    $inviteUrl = 'https://discord.com/oauth2/authorize'
        . '?client_id=' . rawurlencode($clientId)
        . '&scope=applications.commands%20bot'
        . '&permissions=8';
}
?>

<div class="grid grid-cols-12 gap-6">
    <div class="col-span-full bg-white dark:bg-gray-800 shadow-xs rounded-xl">
        <div class="p-5 border-b border-gray-100 dark:border-gray-700/60">
            <div class="text-xs font-semibold uppercase tracking-wider text-violet-500">
                Settings
            </div>

            <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mt-2">
                Invite
            </h2>

            <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                Lade den aktuell ausgewählten Bot auf einen Discord Server ein.
            </div>
        </div>

        <div class="p-5">
            <?php if ($dbError !== null): ?>
                <div class="rounded-lg border border-red-300/40 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                    DB Fehler: <?= h($dbError) ?>
                </div>
            <?php elseif ($inviteUrl === ''): ?>
                <div class="rounded-lg border border-yellow-300/40 bg-yellow-500/10 px-4 py-3 text-sm text-yellow-200">
                    Für diesen Bot wurde noch keine Invite-ID gefunden.
                </div>

                <div class="text-xs text-gray-500 dark:text-gray-400 mt-3">
                    Erwartet wird <code>discord_app_id</code>, alternativ wird auf <code>discord_bot_user_id</code> zurückgefallen.
                </div>
            <?php else: ?>
                <div class="flex flex-col items-start gap-4">
                    <a
                        href="<?= h($inviteUrl) ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="btn bg-violet-500 hover:bg-violet-600 text-white"
                    >
                        Bot einladen
                    </a>

                    <div class="text-xs text-gray-500 dark:text-gray-400 break-all">
                        <?= h($inviteUrl) ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>