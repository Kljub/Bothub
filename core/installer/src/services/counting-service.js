// /core/installer/src/services/counting-service.js
const { dbQuery } = require('../db');

const DEFAULT_ERROR_WRONG    = '❌ {user}, you counted wrong! The next number is **{next}**.';
const DEFAULT_ERROR_TWICE    = '❌ {user}, you are only allowed to count once in a row!';
const DEFAULT_ERROR_COOLDOWN = '❌ {user}, please wait a moment before counting again!';

// ── In-memory settings cache ──────────────────────────────────────────────────
// key: `${botId}:${guildId}` → settings row or null
const settingsCache = new Map();
const cacheTime     = new Map();
const CACHE_TTL     = 60_000;

// ── Table init ────────────────────────────────────────────────────────────────
async function ensureTables() {
    await dbQuery(`CREATE TABLE IF NOT EXISTS \`bot_counting_settings\` (
        \`bot_id\`             BIGINT UNSIGNED NOT NULL,
        \`guild_id\`           VARCHAR(20) NOT NULL,
        \`channel_id\`         VARCHAR(20) NULL DEFAULT NULL,
        \`mode\`               ENUM('normal','webhook') NOT NULL DEFAULT 'normal',
        \`reactions_enabled\`  TINYINT(1) NOT NULL DEFAULT 1,
        \`reaction_emoji\`     VARCHAR(100) NOT NULL DEFAULT '✅',
        \`allow_multiple\`     TINYINT(1) NOT NULL DEFAULT 0,
        \`cooldown_enabled\`   TINYINT(1) NOT NULL DEFAULT 0,
        \`return_errors\`      TINYINT(1) NOT NULL DEFAULT 1,
        \`error_wrong_msg\`    TEXT NULL DEFAULT NULL,
        \`error_twice_msg\`    TEXT NULL DEFAULT NULL,
        \`error_cooldown_msg\` TEXT NULL DEFAULT NULL,
        UNIQUE KEY \`uq_bot_guild\` (\`bot_id\`, \`guild_id\`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);

    await dbQuery(`CREATE TABLE IF NOT EXISTS \`bot_counting_state\` (
        \`bot_id\`          BIGINT UNSIGNED NOT NULL,
        \`guild_id\`        VARCHAR(20) NOT NULL,
        \`current_count\`   INT NOT NULL DEFAULT 0,
        \`last_user_id\`    VARCHAR(20) NULL DEFAULT NULL,
        \`last_message_id\` VARCHAR(20) NULL DEFAULT NULL,
        \`last_count_at\`   DATETIME NULL DEFAULT NULL,
        UNIQUE KEY \`uq_bot_guild\` (\`bot_id\`, \`guild_id\`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);
}

// ── Settings ──────────────────────────────────────────────────────────────────
async function getSettings(botId, guildId) {
    const cacheKey = `${botId}:${guildId}`;
    const now = Date.now();
    if (settingsCache.has(cacheKey) && now - (cacheTime.get(cacheKey) ?? 0) < CACHE_TTL) {
        return settingsCache.get(cacheKey);
    }

    try {
        const rows = await dbQuery(
            'SELECT * FROM bot_counting_settings WHERE bot_id = ? AND guild_id = ? LIMIT 1',
            [Number(botId), String(guildId)]
        );
        const result = rows[0] || null;
        settingsCache.set(cacheKey, result);
        cacheTime.set(cacheKey, now);
        return result;
    } catch (_) {
        return null;
    }
}

// ── State ─────────────────────────────────────────────────────────────────────
async function getState(botId, guildId) {
    try {
        // Ensure a row exists
        await dbQuery(
            'INSERT IGNORE INTO bot_counting_state (bot_id, guild_id) VALUES (?, ?)',
            [Number(botId), String(guildId)]
        );
        const rows = await dbQuery(
            'SELECT * FROM bot_counting_state WHERE bot_id = ? AND guild_id = ? LIMIT 1',
            [Number(botId), String(guildId)]
        );
        return rows[0] || { current_count: 0, last_user_id: null, last_message_id: null, last_count_at: null };
    } catch (_) {
        return { current_count: 0, last_user_id: null, last_message_id: null, last_count_at: null };
    }
}

// ── Cache invalidation (called by dashboard after save) ───────────────────────
function invalidateSettingsCache(botId, guildId) {
    settingsCache.delete(`${botId}:${guildId}`);
}

// ── Template helper ───────────────────────────────────────────────────────────
function applyVars(template, user, next) {
    return template
        .replace(/\{user\}/g, user)
        .replace(/\{next\}/g, String(next));
}

// ── Main event handler ────────────────────────────────────────────────────────
async function handleMessage(message, botId) {
    if (message.author.bot) return;
    if (!message.guildId)   return;

    const guildId = message.guildId;
    const numericBotId = Number(botId);

    // Load settings
    const settings = await getSettings(numericBotId, guildId);
    if (!settings || !settings.channel_id) return;
    if (message.channelId !== settings.channel_id) return;

    // Parse message as integer
    const trimmed = message.content.trim();
    const parsed  = parseInt(trimmed, 10);
    const isValidInt = !isNaN(parsed) && String(parsed) === trimmed;

    const state    = await getState(numericBotId, guildId);
    const expected = (state.current_count || 0) + 1;
    const userMention = `<@${message.author.id}>`;

    // ── Error helpers ─────────────────────────────────────────────────────────
    const sendError = async (rawTemplate, defaultTemplate) => {
        if (!settings.return_errors) {
            message.delete().catch(() => {});
            return;
        }
        const text = applyVars(rawTemplate || defaultTemplate, userMention, expected);
        try {
            const reply = await message.reply({ content: text });
            // Delete both the error reply and the wrong message after 6 s
            setTimeout(() => {
                reply.delete().catch(() => {});
                message.delete().catch(() => {});
            }, 6000);
        } catch (_) {
            // Reply failed (e.g. missing permissions) — still clean up the message
            message.delete().catch(() => {});
        }
    };

    // ── Check 1: wrong number or not an integer ───────────────────────────────
    if (!isValidInt || parsed !== expected) {
        await sendError(settings.error_wrong_msg, DEFAULT_ERROR_WRONG);
        return;
    }

    // ── Check 2: same user twice in a row ─────────────────────────────────────
    if (!settings.allow_multiple && state.last_user_id && state.last_user_id === message.author.id) {
        await sendError(settings.error_twice_msg, DEFAULT_ERROR_TWICE);
        return;
    }

    // ── Check 3: cooldown (5 seconds) ─────────────────────────────────────────
    if (settings.cooldown_enabled && state.last_count_at) {
        const lastAt  = new Date(state.last_count_at + ' UTC');
        const elapsed = Date.now() - lastAt.getTime();
        if (elapsed < 5000) {
            await sendError(settings.error_cooldown_msg, DEFAULT_ERROR_COOLDOWN);
            return;
        }
    }

    // ── Success: update state ─────────────────────────────────────────────────
    const nowStr = new Date().toISOString().slice(0, 19).replace('T', ' ');
    try {
        await dbQuery(
            `UPDATE bot_counting_state
             SET current_count = ?, last_user_id = ?, last_message_id = ?, last_count_at = ?
             WHERE bot_id = ? AND guild_id = ?`,
            [parsed, message.author.id, message.id, nowStr, numericBotId, guildId]
        );
    } catch (err) {
        console.error(`[Counting] Bot ${numericBotId}: state update failed:`, err.message);
        return;
    }

    // ── Deliver: normal (reaction) or webhook (resend) ────────────────────────
    if (settings.mode === 'webhook') {
        // Fetch webhook for this channel or create one
        try {
            const channel = message.channel;
            let webhook = null;

            const hooks = await channel.fetchWebhooks().catch(() => null);
            if (hooks) {
                webhook = hooks.find(h => h.name === 'BotHub Counting') || null;
            }
            if (!webhook) {
                webhook = await channel.createWebhook({ name: 'BotHub Counting' }).catch(() => null);
            }

            if (webhook) {
                const member = message.member;
                await webhook.send({
                    content:   String(parsed),
                    username:  member?.displayName || message.author.username,
                    avatarURL: message.author.displayAvatarURL({ dynamic: true }),
                });
            }
        } catch (err) {
            console.error(`[Counting] Bot ${numericBotId}: webhook send failed:`, err.message);
        }
        message.delete().catch(() => {});
    } else {
        // Normal mode: react with emoji
        if (settings.reactions_enabled) {
            message.react(settings.reaction_emoji || '✅').catch(() => {});
        }
    }
}

// ── attachCountingEvents ──────────────────────────────────────────────────────
function attachCountingEvents(client, botId) {
    const numericBotId = Number(botId);

    ensureTables().catch(err => {
        console.warn(`[Counting] Bot ${numericBotId}: table init failed:`, err.message);
    });

    client.on('messageCreate', (message) => {
        handleMessage(message, numericBotId).catch(err => {
            console.error(`[Counting] Bot ${numericBotId}: messageCreate error:`, err.message);
        });
    });
}

module.exports = {
    ensureTables,
    getSettings,
    getState,
    attachCountingEvents,
    invalidateSettingsCache,
};
