// PFAD: /core/installer/src/services/leveling-service.js
const { dbQuery } = require('../db');

// ── Formula ────────────────────────────────────────────────────────────────────
// XP needed to advance FROM `level` to `level + 1`
function xpToNextLevel(level, xpPerLevel = 50) {
    return xpPerLevel * (level + 1);
}

// ── Settings ──────────────────────────────────────────────────────────────────
async function getOrCreateSettings(botId) {
    await dbQuery(
        'INSERT IGNORE INTO leveling_settings (bot_id) VALUES (?)',
        [botId]
    );
    const rows = await dbQuery(
        'SELECT * FROM leveling_settings WHERE bot_id = ? LIMIT 1',
        [botId]
    );
    return rows[0] || null;
}

// ── Users ─────────────────────────────────────────────────────────────────────
async function getOrCreateUser(botId, guildId, userId) {
    await dbQuery(
        `INSERT INTO leveling_users (bot_id, guild_id, user_id) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE id = id`,
        [botId, guildId, userId]
    );
    const rows = await dbQuery(
        'SELECT * FROM leveling_users WHERE bot_id = ? AND guild_id = ? AND user_id = ? LIMIT 1',
        [botId, guildId, userId]
    );
    return rows[0] || null;
}

async function getUser(botId, guildId, userId) {
    const rows = await dbQuery(
        'SELECT * FROM leveling_users WHERE bot_id = ? AND guild_id = ? AND user_id = ? LIMIT 1',
        [botId, guildId, userId]
    );
    return rows[0] || null;
}

async function getUserRank(botId, guildId, userId) {
    const rows = await dbQuery(
        `SELECT COUNT(*) + 1 AS user_rank FROM leveling_users
         WHERE bot_id = ? AND guild_id = ?
           AND total_xp > (
               SELECT COALESCE(total_xp, 0) FROM leveling_users
               WHERE bot_id = ? AND guild_id = ? AND user_id = ? LIMIT 1
           )`,
        [botId, guildId, botId, guildId, userId]
    );
    return Number(rows[0]?.user_rank || 1);
}

async function getLeaderboard(botId, guildId, limit = 10, offset = 0) {
    return await dbQuery(
        `SELECT * FROM leveling_users
         WHERE bot_id = ? AND guild_id = ?
         ORDER BY total_xp DESC
         LIMIT ? OFFSET ?`,
        [botId, guildId, limit, offset]
    );
}

async function resetLeaderboard(botId, guildId) {
    await dbQuery(
        'DELETE FROM leveling_users WHERE bot_id = ? AND guild_id = ?',
        [botId, guildId]
    );
}

async function clearUserData(botId, guildId, userId) {
    await dbQuery(
        'DELETE FROM leveling_users WHERE bot_id = ? AND guild_id = ? AND user_id = ?',
        [botId, guildId, userId]
    );
}

// ── Boosters ──────────────────────────────────────────────────────────────────
async function getBoosters(botId) {
    return await dbQuery(
        'SELECT * FROM leveling_boosters WHERE bot_id = ?',
        [botId]
    );
}

function calculateBoostMultiplier(member, channelId, boosters, settings) {
    if (!boosters || boosters.length === 0) return 1;

    const memberRoleIds = member?.roles?.cache ? [...member.roles.cache.keys()] : [];
    const applicable = [];

    for (const b of boosters) {
        const applies =
            (b.booster_type === 'role'    && memberRoleIds.includes(b.target_id)) ||
            (b.booster_type === 'channel' && b.target_id === channelId);

        if (applies) {
            let pct = Number(b.percentage);
            if (settings.randomize_boosts) pct += (Math.random() * 2 - 1);
            applicable.push(pct);
        }
    }

    if (applicable.length === 0) return 1;
    const totalPct = settings.sum_boosts
        ? applicable.reduce((a, c) => a + c, 0)
        : Math.max(...applicable);
    return 1 + totalPct / 100;
}

// ── XP Gain ───────────────────────────────────────────────────────────────────
async function addMessageXp(botId, guildId, userId, member, channelId, settings) {
    const now = new Date();
    const user = await getOrCreateUser(botId, guildId, userId);

    // Cooldown check
    if (user.last_message_at) {
        const elapsed = (now - new Date(user.last_message_at)) / 1000;
        if (elapsed < Number(settings.msg_cooldown)) {
            return { gained: false };
        }
    }

    // Base XP (random between min and max)
    const min = Number(settings.msg_xp_min);
    const max = Number(settings.msg_xp_max);
    const baseXp = Math.floor(Math.random() * (max - min + 1)) + min;

    // Booster multiplier
    const boosters = await getBoosters(botId);
    const multiplier = calculateBoostMultiplier(member, channelId, boosters, settings);
    const xpGained = Math.round(baseXp * multiplier);

    return await _applyXp(botId, guildId, user, xpGained, settings, now);
}

async function addVoiceXp(botId, guildId, userId, minutes, settings) {
    if (!settings.voice_xp_enabled) return null;
    const xpGained = Math.round(minutes * Number(settings.voice_xp_per_minute || 5));
    if (xpGained <= 0) return null;

    const user = await getOrCreateUser(botId, guildId, userId);
    return await _applyXp(botId, guildId, user, xpGained, settings, null);
}

async function _applyXp(botId, guildId, user, xpGained, settings, timestamp) {
    let xp       = Number(user.xp) + xpGained;
    let level    = Number(user.level);
    let totalXp  = Number(user.total_xp) + xpGained;
    const oldLevel   = level;
    const xpPerLevel = Number(settings.xp_per_level) || 50;
    const maxLevel   = Number(settings.max_level) || 0;

    // Level-up loop
    while (xp >= xpToNextLevel(level, xpPerLevel) && (maxLevel === 0 || level < maxLevel)) {
        xp -= xpToNextLevel(level, xpPerLevel);
        level++;
    }
    if (maxLevel > 0 && level >= maxLevel) { level = maxLevel; xp = 0; }

    const nowStr = timestamp
        ? timestamp.toISOString().slice(0, 19).replace('T', ' ')
        : null;

    await dbQuery(
        `UPDATE leveling_users
         SET xp = ?, level = ?, total_xp = ?, last_message_at = COALESCE(?, last_message_at)
         WHERE bot_id = ? AND guild_id = ? AND user_id = ?`,
        [xp, level, totalXp, nowStr, botId, guildId, user.user_id]
    );

    return {
        gained:    true,
        xpGained,
        leveledUp: level > oldLevel,
        oldLevel,
        newLevel:  level,
        xp,
        totalXp,
    };
}

async function editXp(botId, guildId, userId, amount, mode = 'add') {
    const user = await getOrCreateUser(botId, guildId, userId);
    let totalXp = Number(user.total_xp);

    if (mode === 'set')    totalXp = Math.max(0, amount);
    else if (mode === 'add')    totalXp = Math.max(0, totalXp + amount);
    else if (mode === 'remove') totalXp = Math.max(0, totalXp - amount);

    const settings = await getOrCreateSettings(botId);
    const xpPerLevel = Number(settings.xp_per_level) || 50;
    const maxLevel   = Number(settings.max_level) || 0;

    let level = 0, remaining = totalXp;
    while (remaining >= xpToNextLevel(level, xpPerLevel) && (maxLevel === 0 || level < maxLevel)) {
        remaining -= xpToNextLevel(level, xpPerLevel);
        level++;
    }
    if (maxLevel > 0 && level >= maxLevel) { level = maxLevel; remaining = 0; }

    await dbQuery(
        `UPDATE leveling_users SET xp = ?, level = ?, total_xp = ?
         WHERE bot_id = ? AND guild_id = ? AND user_id = ?`,
        [remaining, level, totalXp, botId, guildId, userId]
    );
    return { level, xp: remaining, total_xp: totalXp };
}

// ── Voice sessions ────────────────────────────────────────────────────────────
async function startVoiceSession(botId, guildId, userId, channelId) {
    await dbQuery(
        `INSERT INTO leveling_voice_sessions (bot_id, guild_id, user_id, channel_id)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE channel_id = VALUES(channel_id), joined_at = NOW()`,
        [botId, guildId, userId, channelId]
    );
}

async function endVoiceSession(botId, guildId, userId) {
    const rows = await dbQuery(
        'SELECT * FROM leveling_voice_sessions WHERE bot_id = ? AND guild_id = ? AND user_id = ? LIMIT 1',
        [botId, guildId, userId]
    );
    if (!rows[0]) return 0;

    const minutes = Math.max(0, (Date.now() - new Date(rows[0].joined_at).getTime()) / 60000);
    await dbQuery(
        'DELETE FROM leveling_voice_sessions WHERE bot_id = ? AND guild_id = ? AND user_id = ?',
        [botId, guildId, userId]
    );
    return minutes;
}

// ── Helpers ───────────────────────────────────────────────────────────────────
async function isCommandEnabled(botId, commandKey) {
    const rows = await dbQuery(
        'SELECT is_enabled FROM commands WHERE bot_id = ? AND command_key = ? LIMIT 1',
        [botId, commandKey]
    );
    // If no row exists, treat as enabled by default
    if (!rows[0]) return true;
    return Number(rows[0].is_enabled) === 1;
}

function makeXpBar(xp, xpNeeded, length = 20) {
    const filled = Math.min(length, Math.round(xpNeeded > 0 ? (xp / xpNeeded) * length : 0));
    return '█'.repeat(filled) + '░'.repeat(length - filled);
}

// ── Discord Event Attachment ───────────────────────────────────────────────────
function attachLevelingEvents(client, botId) {
    // Message XP
    client.on('messageCreate', async (message) => {
        try {
            if (message.author.bot || !message.guildId) return;

            const settings = await getOrCreateSettings(botId);
            if (!settings) return;

            const result = await addMessageXp(
                botId,
                message.guildId,
                message.author.id,
                message.member,
                message.channelId,
                settings
            );

            if (!result?.gained || !result?.leveledUp) return;

            // Send level-up message
            const mode = settings.levelup_message;
            if (mode === 'disabled') return;

            const embed = {
                color: parseInt((settings.embed_color || '#f45142').replace('#', ''), 16),
                description: `🎉 <@${message.author.id}> ist auf **Level ${result.newLevel}** aufgestiegen!`,
            };

            if (mode === 'current_channel') {
                await message.channel.send({ embeds: [embed] }).catch(() => {});
            } else if (mode === 'dm') {
                await message.author.send({ embeds: [embed] }).catch(() => {});
            }
        } catch (err) {
            console.error(`[Leveling] messageCreate Fehler (bot ${botId}):`, err.message);
        }
    });

    // Voice XP
    client.on('voiceStateUpdate', async (oldState, newState) => {
        try {
            if (!oldState.guild || newState.member?.user?.bot) return;
            const guildId = oldState.guild.id || newState.guild?.id;
            const userId  = oldState.member?.id || newState.member?.id;
            if (!guildId || !userId) return;

            const settings = await getOrCreateSettings(botId);
            if (!settings?.voice_xp_enabled) return;

            // User left or moved: end session
            if (oldState.channelId && oldState.channelId !== newState.channelId) {
                const minutes = await endVoiceSession(botId, guildId, userId);
                if (minutes >= 1) {
                    await addVoiceXp(botId, guildId, userId, minutes, settings);
                }
            }

            // User joined or moved: start new session
            if (newState.channelId) {
                await startVoiceSession(botId, guildId, userId, newState.channelId);
            }
        } catch (err) {
            console.error(`[Leveling] voiceStateUpdate Fehler (bot ${botId}):`, err.message);
        }
    });

    // Clear on leave
    client.on('guildMemberRemove', async (member) => {
        try {
            const settings = await getOrCreateSettings(botId);
            if (!settings?.clear_on_leave) return;
            await clearUserData(botId, member.guild.id, member.id);
        } catch (err) {
            console.error(`[Leveling] guildMemberRemove Fehler (bot ${botId}):`, err.message);
        }
    });
}

module.exports = {
    xpToNextLevel, makeXpBar,
    getOrCreateSettings,
    getOrCreateUser, getUser, getUserRank, getLeaderboard,
    resetLeaderboard, clearUserData, editXp,
    getBoosters, calculateBoostMultiplier,
    addMessageXp, addVoiceXp,
    startVoiceSession, endVoiceSession,
    isCommandEnabled,
    attachLevelingEvents,
};
