<?php
declare(strict_types=1);

require_once __DIR__.'/../../discord/discord_api.php';

$guildId=$_GET['guild_id'] ?? '';

if($guildId===''){
http_response_code(400);
exit;
}

$roles=discord_get_roles($guildId);

echo json_encode($roles);