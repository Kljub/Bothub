// PFAD: /core/installer/src/services/temp-voice-service.js
'use strict';

const { ChannelType, PermissionsBitField } = require('discord.js');
const { dbQuery } = require('../db');

// ── In-memory settings cache ──────────────────────────────────────────────────
// key: `${botId}:${guildId}` → settings row or null
const settingsCache = new Map();

// ── Active temp channels ──────────────────────────────────────────────────────
// key: `${botId}:${channelId}` → { guildId, ownerId, channelNum }
const activeChannels = new Map();

// ── DB helpers ────────────────────────────────────────────────────────────────

async function ensureTables() {
    await dbQuery(`
        CREATE TABLE IF NOT EXISTS \`bot_temp_voice_settings\` (
            \`id\`                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            \`bot_id\`             INT UNSIGNED NOT NULL,
            \`guild_id\`           VARCHAR(20)  NOT NULL DEFAULT '',
            \`trigger_channel_id\` VARCHAR(20)  NOT NULL DEFAULT '',
            \`category_id\`        VARCHAR(20)  NOT NULL DEFAULT '',
            \`channel_name\`       VARCHAR(100) NOT NULL DEFAULT 'Temp #{n}',
            \`user_limit\`         TINYINT UNSIGNED NOT NULL DEFAULT 0,
            \`bitrate\`            INT UNSIGNED NOT NULL DEFAULT 64000,
            \`is_enabled\`         TINYINT(1)   NOT NULL DEFAULT 1,
            \`created_at\`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            \`updated_at\`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY \`uq_bot_guild\` (\`bot_id\`, \`guild_id\`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    `);

    await dbQuery(`
        CREATE TABLE IF NOT EXISTS \`bot_temp_voice_channels\` (
            \`id\`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            \`bot_id\`       INT UNSIGNED NOT NULL,
            \`guild_id\`     VARCHAR(20)  NOT NULL,
            \`channel_id\`   VARCHAR(20)  NOT NULL,
            \`owner_id\`     VARCHAR(20)  NOT NULL DEFAULT '',
            \`channel_num\`  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            \`created_at\`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY \`uq_channel\` (\`bot_id\`, \`guild_id\`, \`channel_id\`),
            KEY \`idx_bot_guild\` (\`bot_id\`, \`guild_id\`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    `);
}

async function getSettings(botId, guildId) {
    const key = `${botId}:${guildId}`;
    if (settingsCache.has(key)) return settingsCache.get(key);

    try {
        const rows = await dbQuery(
            'SELECT * FROM bot_temp_voice_settings WHERE bot_id = ? AND guild_id = ? AND is_enabled = 1 LIMIT 1',
            [Number(botId), String(guildId)]
        );
        const result = (Array.isArray(rows) && rows[0]) ? rows[0] : null;
        settingsCache.set(key, result);
        // Invalidate cache after 60s so dashboard changes apply without restart
        setTimeout(() => settingsCache.delete(key), 60_000);
        return result;
    } catch (_) {
        return null;
    }
}

async function getNextChannelNum(botId, guildId) {
    try {
        const rows = await dbQuery(
            'SELECT channel_num FROM bot_temp_voice_channels WHERE bot_id = ? AND guild_id = ? ORDER BY channel_num ASC',
            [Number(botId), String(guildId)]
        );
        const used = Array.isArray(rows) ? rows.map((r) => Number(r.channel_num)) : [];
        for (let i = 1; i <= 999; i++) {
            if (!used.includes(i)) return i;
        }
        return used.length + 1;
    } catch (_) {
        return 1;
    }
}

async function registerChannel(botId, guildId, channelId, ownerId, channelNum) {
    try {
        await dbQuery(
            `INSERT INTO bot_temp_voice_channels (bot_id, guild_id, channel_id, owner_id, channel_num)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE owner_id = VALUES(owner_id), channel_num = VALUES(channel_num)`,
            [Number(botId), String(guildId), String(channelId), String(ownerId), Number(channelNum)]
        );
    } catch (e) {
        console.warn(`[TempVoice] registerChannel error:`, e.message);
    }
}

async function unregisterChannel(botId, guildId, channelId) {
    try {
        await dbQuery(
            'DELETE FROM bot_temp_voice_channels WHERE bot_id = ? AND guild_id = ? AND channel_id = ?',
            [Number(botId), String(guildId), String(channelId)]
        );
    } catch (e) {
        console.warn(`[TempVoice] unregisterChannel error:`, e.message);
    }
}

async function isTrackedChannel(botId, guildId, channelId) {
    // Check in-memory first
    if (activeChannels.has(`${botId}:${channelId}`)) return true;
    // Fall back to DB (e.g. after bot restart)
    try {
        const rows = await dbQuery(
            'SELECT id FROM bot_temp_voice_channels WHERE bot_id = ? AND guild_id = ? AND channel_id = ? LIMIT 1',
            [Number(botId), String(guildId), String(channelId)]
        );
        return Array.isArray(rows) && rows.length > 0;
    } catch (_) {
        return false;
    }
}

// ── Name template resolver ────────────────────────────────────────────────────

function resolveChannelName(template, num, member) {
    return template
        .replace(/\{n\}/gi, String(num))
        .replace(/\{num\}/gi, String(num))
        .replace(/\{user\}/gi, member.displayName || member.user.username)
        .replace(/\{username\}/gi, member.user.username);
}

// ── Core logic ────────────────────────────────────────────────────────────────

async function handleVoiceStateUpdate(oldState, newState, botId) {
    const guild = newState.guild || oldState.guild;
    if (!guild) return;

    const settings = await getSettings(botId, guild.id);
    if (!settings || !settings.trigger_channel_id) return;

    const triggerId = String(settings.trigger_channel_id);

    // ── User joins the trigger channel → create a new temp channel ────────────
    if (newState.channelId === triggerId) {
        const member = newState.member;
        if (!member) return;

        try {
            const channelNum = await getNextChannelNum(botId, guild.id);
            const name       = resolveChannelName(
                String(settings.channel_name || 'Temp #{n}'),
                channelNum,
                member
            );

            const options = {
                name,
                type:      ChannelType.GuildVoice,
                userLimit: Number(settings.user_limit) || 0,
                bitrate:   Math.min(Number(settings.bitrate) || 64000, guild.maximumBitrate || 96000),
            };

            const categoryId = String(settings.category_id || '').trim();
            if (categoryId !== '') {
                options.parent = categoryId;
            }

            const newChannel = await guild.channels.create(options);

            // Move the member into the new channel
            await member.voice.setChannel(newChannel);

            // Track it
            activeChannels.set(`${botId}:${newChannel.id}`, {
                guildId:    guild.id,
                ownerId:    member.id,
                channelNum,
            });
            await registerChannel(botId, guild.id, newChannel.id, member.id, channelNum);

            console.log(`[TempVoice] Bot ${botId}: Created "${name}" (${newChannel.id}) for ${member.user.tag}`);
        } catch (e) {
            console.error(`[TempVoice] Bot ${botId}: Failed to create temp channel:`, e.message);
        }
        return;
    }

    // ── User leaves a temp channel → delete it if empty ───────────────────────
    const leftChannelId = oldState.channelId;
    if (!leftChannelId || leftChannelId === triggerId) return;

    const wasTracked = await isTrackedChannel(botId, guild.id, leftChannelId);
    if (!wasTracked) return;

    const leftChannel = guild.channels.cache.get(leftChannelId);
    if (!leftChannel) {
        // Already gone — clean up DB
        activeChannels.delete(`${botId}:${leftChannelId}`);
        await unregisterChannel(botId, guild.id, leftChannelId);
        return;
    }

    const memberCount = leftChannel.members ? leftChannel.members.size : 0;
    if (memberCount === 0) {
        try {
            await leftChannel.delete('Temp voice channel empty');
            activeChannels.delete(`${botId}:${leftChannelId}`);
            await unregisterChannel(botId, guild.id, leftChannelId);
            console.log(`[TempVoice] Bot ${botId}: Deleted empty temp channel "${leftChannel.name}" (${leftChannelId})`);
        } catch (e) {
            console.warn(`[TempVoice] Bot ${botId}: Could not delete channel ${leftChannelId}:`, e.message);
            // Still untrack so we don't retry forever
            activeChannels.delete(`${botId}:${leftChannelId}`);
            await unregisterChannel(botId, guild.id, leftChannelId);
        }
    }
}

// ── Attach ────────────────────────────────────────────────────────────────────

function attachTempVoiceEvents(client, botId) {
    ensureTables().catch((e) =>
        console.warn(`[TempVoice] ensureTables failed for bot ${botId}:`, e.message)
    );

    client.on('voiceStateUpdate', async (oldState, newState) => {
        try {
            await handleVoiceStateUpdate(oldState, newState, botId);
        } catch (e) {
            console.error(`[TempVoice] voiceStateUpdate error (bot ${botId}):`, e.message);
        }
    });

}

// Clears cached settings for a specific bot (or all bots if botId is omitted).
// Call this whenever the dashboard saves new settings so the next event picks
// up fresh config without waiting for the 60-second TTL.
function clearSettingsCache(botId) {
    if (botId == null) {
        settingsCache.clear();
        return;
    }
    const prefix = `${botId}:`;
    for (const key of settingsCache.keys()) {
        if (key.startsWith(prefix)) settingsCache.delete(key);
    }
}

module.exports = { attachTempVoiceEvents, clearSettingsCache };
