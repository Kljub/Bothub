// PFAD: /core/installer/src/services/statistic-channel-service.js

const { PermissionFlagsBits } = require('discord.js');
const { dbQuery } = require('../db');

const UPDATE_INTERVAL_MS = 5 * 60 * 1000; // 5 minutes (Discord rate limits channel renames)
const timers = new Map(); // botId → NodeJS.Timeout

const STAT_TYPES = {
    total_members:    (guild) => guild.memberCount,
    human_members:    (guild) => guild.members.cache.filter(m => !m.user.bot).size,
    bot_members:      (guild) => guild.members.cache.filter(m =>  m.user.bot).size,
    online_members:   (guild) => guild.members.cache.filter(m => m.presence?.status !== 'offline' && m.presence?.status != null).size,
    server_channels:  (guild) => guild.channels.cache.size,
    server_roles:     (guild) => guild.roles.cache.size,
    banned_members:   async (guild) => { try { const bans = await guild.bans.fetch(); return bans.size; } catch (_) { return '?'; } },
    server_emojis:    (guild) => guild.emojis.cache.size,
    server_stickers:  (guild) => guild.stickers.cache.size,
    boost_tier:       (guild) => guild.premiumTier,
    scheduled_events: (guild) => guild.scheduledEvents.cache.size,
};

async function getStatValue(guild, statType) {
    const fn = STAT_TYPES[statType];
    if (!fn) return '?';
    try {
        const val = fn(guild);
        return val instanceof Promise ? String(await val) : String(val);
    } catch (_) {
        return '?';
    }
}

async function updateStatChannels(client, botId) {
    try {
        const rows = await dbQuery(
            'SELECT * FROM bot_statistic_channels WHERE bot_id = ? AND is_active = 1',
            [Number(botId)]
        );
        if (!Array.isArray(rows) || rows.length === 0) return;

        for (const row of rows) {
            try {
                const channel = await client.channels.fetch(String(row.channel_id));
                if (!channel) continue;

                const guild = channel.guild;
                if (!guild) continue;

                const value    = await getStatValue(guild, row.stat_type || 'total_members');
                const newName  = String(row.channel_name || 'Members: {value}').replace(/\{value\}/gi, value);

                if (String(row.cached_value) === value) continue; // no change

                await channel.setName(newName);

                // Auto-lock: deny @everyone from joining
                if (Number(row.auto_lock) === 1) {
                    try {
                        const everyoneRole = guild.roles.everyone;
                        await channel.permissionOverwrites.edit(everyoneRole, {
                            Connect: false,
                            ViewChannel: true,
                        });
                    } catch (_) {}
                }

                await dbQuery(
                    'UPDATE bot_statistic_channels SET cached_value = ?, updated_at = NOW() WHERE id = ?',
                    [value, Number(row.id)]
                );
            } catch (e) {
                console.warn(`[StatChannels] Bot ${botId}: update failed for channel ${row.channel_id}:`, e?.message);
            }
        }
    } catch (e) {
        console.error(`[StatChannels] Bot ${botId}: tick error:`, e?.message);
    }
}

function startStatisticChannelService(client, botId) {
    const handle = setInterval(() => updateStatChannels(client, botId), UPDATE_INTERVAL_MS);
    if (handle.unref) handle.unref();
    timers.set(Number(botId), handle);

    // Initial update after 10s
    setTimeout(() => updateStatChannels(client, botId), 10_000);
}

function stopStatisticChannelService(botId) {
    const handle = timers.get(Number(botId));
    if (handle) { clearInterval(handle); timers.delete(Number(botId)); }
}

module.exports = { startStatisticChannelService, stopStatisticChannelService };
