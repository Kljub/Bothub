<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/functions/html.php';
require_once $projectRoot . '/functions/bot_token.php';
require_once $projectRoot . '/discord/discord_api.php';

$serverMessages = [];
$serverErrors   = [];

// Handle leave-guild POST
if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && isset($currentBotId)
    && $currentBotId > 0
    && isset($userId)
    && $userId > 0
) {
    $postServerAction = trim((string)($_POST['server_action'] ?? ''));

    if ($postServerAction === 'leave_guild') {
        $leaveGuildId = trim((string)($_POST['guild_id'] ?? ''));

        if ($leaveGuildId === '' || !preg_match('/^\d+$/', $leaveGuildId)) {
            $serverErrors[] = 'Ungültige Guild ID.';
        } else {
            try {
                $pdo = bh_get_pdo();

                // Verify this guild belongs to the current bot AND the bot belongs to the current user
                $stmt = $pdo->prepare(
                    'SELECT bg.id, bg.guild_name
                     FROM bot_guilds bg
                     INNER JOIN bot_instances bi ON bi.id = bg.bot_id
                     WHERE bg.bot_id = :bot_id
                       AND bg.guild_id = :guild_id
                       AND bi.owner_user_id = :user_id
                     LIMIT 1'
                );
                $stmt->execute([
                    ':bot_id'   => $currentBotId,
                    ':guild_id' => $leaveGuildId,
                    ':user_id'  => $userId,
                ]);
                $guildRow = $stmt->fetch();

                if (!is_array($guildRow)) {
                    $serverErrors[] = 'Server nicht gefunden oder keine Berechtigung.';
                } else {
                    // Get bot token
                    $botStmt = $pdo->prepare(
                        'SELECT bot_token_encrypted, bot_token_enc_meta FROM bot_instances WHERE id = :id LIMIT 1'
                    );
                    $botStmt->execute([':id' => $currentBotId]);
                    $botRow = $botStmt->fetch();

                    if (!is_array($botRow)) {
                        $serverErrors[] = 'Bot nicht gefunden.';
                    } else {
                        $tokenResult = bh_bot_token_resolve($botRow);
                        if (!$tokenResult['ok'] || $tokenResult['token'] === null) {
                            $serverErrors[] = 'Bot Token konnte nicht entschlüsselt werden.';
                        } else {
                            $result = discord_api_request_bot(
                                $tokenResult['token'],
                                'DELETE',
                                '/users/@me/guilds/' . $leaveGuildId
                            );

                            if ($result['ok'] || $result['http'] === 204) {
                                // Remove from DB
                                $del = $pdo->prepare(
                                    'DELETE FROM bot_guilds WHERE bot_id = :bot_id AND guild_id = :guild_id'
                                );
                                $del->execute([
                                    ':bot_id'   => $currentBotId,
                                    ':guild_id' => $leaveGuildId,
                                ]);

                                $guildName = trim((string)($guildRow['guild_name'] ?? $leaveGuildId));
                                $serverMessages[] = 'Bot hat "' . $guildName . '" verlassen.';
                            } else {
                                $errDetail = is_string($result['error'] ?? null) ? $result['error'] : ('HTTP ' . $result['http']);
                                $serverErrors[] = 'Discord API Fehler: ' . $errDetail;
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                $serverErrors[] = 'Fehler: ' . $e->getMessage();
            }
        }
    }
}

// Load guilds
$guilds = [];

if (isset($currentBotId) && $currentBotId > 0) {
    try {
        $pdo   = bh_get_pdo();
        $stmt  = $pdo->prepare(
            'SELECT guild_id, guild_name, is_owner, icon_hash, added_at
             FROM bot_guilds
             WHERE bot_id = :bot_id
             ORDER BY guild_name ASC'
        );
        $stmt->execute([':bot_id' => $currentBotId]);
        $guilds = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        $serverErrors[] = 'Fehler beim Laden der Server: ' . $e->getMessage();
    }
}
?>
<div class="grid grid-cols-12 gap-6">
    <div class="col-span-full bg-white dark:bg-gray-800 shadow-xs rounded-xl">
        <div class="p-5 border-b border-gray-100 dark:border-gray-700/60">
            <div class="text-xs font-semibold uppercase tracking-wider text-violet-500">Settings</div>
            <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mt-2">Servers</h2>
            <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                Server auf denen dein Bot aktiv ist. Du kannst den Bot über den Leave-Button von einem Server entfernen.
            </div>
        </div>

        <div class="p-5">
            <?php foreach ($serverMessages as $msg): ?>
                <div class="mb-4 rounded-xl border border-emerald-200 dark:border-emerald-700/60 bg-emerald-50 dark:bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-300">
                    <?= h($msg) ?>
                </div>
            <?php endforeach; ?>

            <?php foreach ($serverErrors as $err): ?>
                <div class="mb-4 rounded-xl border border-rose-200 dark:border-rose-700/60 bg-rose-50 dark:bg-rose-500/10 px-4 py-3 text-sm text-rose-700 dark:text-rose-300">
                    <?= h($err) ?>
                </div>
            <?php endforeach; ?>

            <?php if (count($guilds) === 0): ?>
                <div class="rounded-lg border border-gray-200 dark:border-gray-700/60 bg-gray-50 dark:bg-gray-900/30 p-4">
                    <div class="text-sm font-medium text-gray-800 dark:text-gray-100">Keine Server gefunden</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400 mt-2">
                        Der Bot ist auf keinen Servern oder die Server wurden noch nicht synchronisiert.
                    </div>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="table-auto w-full dark:text-gray-300">
                        <thead class="text-xs uppercase text-gray-400 dark:text-gray-500 bg-gray-50 dark:bg-gray-700/50 rounded-xs">
                            <tr>
                                <th class="p-2 whitespace-nowrap text-left">Server</th>
                                <th class="p-2 whitespace-nowrap text-left">Guild ID</th>
                                <th class="p-2 whitespace-nowrap text-left">Rolle</th>
                                <th class="p-2 whitespace-nowrap text-left">Hinzugefügt</th>
                                <th class="p-2 whitespace-nowrap text-left"></th>
                            </tr>
                        </thead>
                        <tbody class="text-sm divide-y divide-gray-100 dark:divide-gray-700/60">
                            <?php foreach ($guilds as $guild): ?>
                                <?php
                                $guildId   = (string)($guild['guild_id'] ?? '');
                                $guildName = trim((string)($guild['guild_name'] ?? ''));
                                $isOwner   = (int)($guild['is_owner'] ?? 0) === 1;
                                $iconHash  = trim((string)($guild['icon_hash'] ?? ''));
                                $addedAt   = trim((string)($guild['added_at'] ?? ''));
                                $iconUrl   = $iconHash !== '' && $guildId !== ''
                                    ? 'https://cdn.discordapp.com/icons/' . $guildId . '/' . $iconHash . '.webp?size=32'
                                    : null;
                                ?>
                                <tr>
                                    <td class="p-2">
                                        <div class="flex items-center gap-3">
                                            <?php if ($iconUrl !== null): ?>
                                                <img src="<?= h($iconUrl) ?>" alt="" class="w-8 h-8 rounded-full flex-shrink-0">
                                            <?php else: ?>
                                                <div class="w-8 h-8 rounded-full bg-violet-500/20 flex items-center justify-center text-violet-400 text-xs font-bold flex-shrink-0">
                                                    <?= h(mb_strtoupper(mb_substr($guildName !== '' ? $guildName : '?', 0, 1))) ?>
                                                </div>
                                            <?php endif; ?>
                                            <span class="font-medium text-gray-800 dark:text-gray-100">
                                                <?= h($guildName !== '' ? $guildName : ('Guild #' . $guildId)) ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="p-2 whitespace-nowrap text-gray-500 dark:text-gray-400 font-mono text-xs">
                                        <?= h($guildId) ?>
                                    </td>
                                    <td class="p-2 whitespace-nowrap">
                                        <?php if ($isOwner): ?>
                                            <span class="text-amber-600 dark:text-amber-400 text-xs">Eigentümer</span>
                                        <?php else: ?>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">Mitglied</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-2 whitespace-nowrap text-gray-500 dark:text-gray-400 text-xs">
                                        <?= h($addedAt !== '' ? $addedAt : '—') ?>
                                    </td>
                                    <td class="p-2 whitespace-nowrap text-right">
                                        <form method="post" onsubmit="return confirm('Bot wirklich von &quot;<?= h($guildName !== '' ? $guildName : $guildId) ?>&quot; entfernen?')">
                                            <input type="hidden" name="server_action" value="leave_guild">
                                            <input type="hidden" name="guild_id" value="<?= h($guildId) ?>">
                                            <button type="submit" class="btn bg-rose-500 hover:bg-rose-600 text-white text-xs py-1 px-3">
                                                Leave
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    <?= count($guilds) ?> Server gefunden.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
