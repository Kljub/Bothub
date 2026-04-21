<?php
declare(strict_types=1);

session_start();

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/functions/admin_guard.php';
require_once $projectRoot . '/functions/bot_token.php';

bh_admin_require_user();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function bh_guild_emojis_send(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $pdo = bh_pdo();
    $stmt = $pdo->query(
        'SELECT id, display_name, bot_token_encrypted, bot_token_enc_meta
         FROM bot_instances
         WHERE is_active = 1
         ORDER BY id ASC'
    );
    $bots = $stmt->fetchAll();

    if (!is_array($bots)) {
        bh_guild_emojis_send(200, ['guilds' => []]);
    }

    $allGuilds = [];

    foreach ($bots as $bot) {
        $tokenResult = bh_bot_token_resolve($bot);
        if (!$tokenResult['ok'] || $tokenResult['token'] === null) {
            continue;
        }

        $token = $tokenResult['token'];
        $botId = (int)($bot['id'] ?? 0);
        $botName = trim((string)($bot['display_name'] ?? 'Bot #' . $botId));
        $guildsResponse = bh_discord_api_get('users/@me/guilds?limit=200', $token);
        if (!is_array($guildsResponse)) {
            continue;
        }

        foreach ($guildsResponse as $guild) {
            $guildId   = trim((string)($guild['id'] ?? ''));
            $guildName = trim((string)($guild['name'] ?? ''));

            if ($guildId === '') {
                continue;
            }
            $emojisResponse = bh_discord_api_get('guilds/' . $guildId . '/emojis', $token);
            if (!is_array($emojisResponse)) {
                continue;
            }

            $emojis = [];
            foreach ($emojisResponse as $emoji) {
                $emojiId   = trim((string)($emoji['id'] ?? ''));
                $emojiName = trim((string)($emoji['name'] ?? ''));
                $animated  = !empty($emoji['animated']);

                if ($emojiId === '' || $emojiName === '') {
                    continue;
                }

                $emojis[] = [
                    'id'       => $emojiId,
                    'name'     => $emojiName,
                    'animated' => $animated,
                    'value'    => ($animated ? '<a:' : '<:') . $emojiName . ':' . $emojiId . '>',
                    'url'      => 'https://cdn.discordapp.com/emojis/' . $emojiId . ($animated ? '.gif' : '.webp') . '?size=32',
                ];
            }

            if (count($emojis) === 0) {
                continue;
            }

            $allGuilds[] = [
                'guild_id'   => $guildId,
                'guild_name' => $guildName,
                'bot_id'     => $botId,
                'bot_name'   => $botName,
                'emojis'     => $emojis,
            ];
        }
    }

    bh_guild_emojis_send(200, ['guilds' => $allGuilds]);

} catch (Throwable $e) {
    bh_guild_emojis_send(500, ['error' => $e->getMessage()]);
}

/**
 * Perform a Discord REST API GET request.
 *
 * @return array<mixed>|null
 */
function bh_discord_api_get(string $endpoint, string $botToken): ?array
{
    $url = 'https://discord.com/api/v10/' . ltrim($endpoint, '/');

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => implode("\r\n", [
                'Authorization: Bot ' . $botToken,
                'User-Agent: BotHub/1.0 (PHP)',
                'Content-Type: application/json',
            ]),
            'timeout'         => 8,
            'ignore_errors'   => true,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $responseBody = @file_get_contents($url, false, $ctx);

    if ($responseBody === false) {
        return null;
    }

    $decoded = json_decode($responseBody, true);

    return is_array($decoded) ? $decoded : null;
}
