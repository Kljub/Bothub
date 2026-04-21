// PFAD: /core/installer/src/services/auto-react-service.js

const { dbQuery } = require('../db');

function parseJsonArray(raw) {
    if (!raw) return [];
    try { const a = typeof raw === 'string' ? JSON.parse(raw) : raw; return Array.isArray(a) ? a : []; }
    catch (_) { return []; }
}

async function loadSettings(botId) {
    try {
        const rows = await dbQuery(
            'SELECT * FROM bot_auto_react_settings WHERE bot_id = ? LIMIT 1',
            [Number(botId)]
        );
        if (!Array.isArray(rows) || rows.length === 0) return null;
        return rows[0];
    } catch (_) { return null; }
}

async function handleMessage(message, botId) {
    if (message.author.bot || !message.inGuild()) return;

    const settings = await loadSettings(botId);
    if (!settings || Number(settings.evt_handler) !== 1) return;

    // Channel filter: only react in enabled channels (if set)
    const enabledChannels = parseJsonArray(settings.enabled_channels).map(i => i.id || i);
    if (enabledChannels.length > 0 && !enabledChannels.includes(message.channelId)) return;

    // Ignore embeds: skip if message has no text content (embed-only)
    if (Number(settings.ignore_embeds) === 1) {
        if (!message.content?.trim() && message.embeds.length > 0) return;
    }

    // Allowed roles filter
    const allowedRoles = parseJsonArray(settings.allowed_roles).map(i => i.id || i);
    if (allowedRoles.length > 0) {
        const memberRoles = message.member ? [...message.member.roles.cache.keys()] : [];
        if (!allowedRoles.some(r => memberRoles.includes(r))) return;
    }

    // Word filter
    const checkWords = parseJsonArray(settings.check_words).map(w => String(w).toLowerCase());
    if (checkWords.length > 0) {
        const lower = (message.content || '').toLowerCase();
        if (!checkWords.some(w => lower.includes(w))) return;
    }

    // React with configured emojis
    const emojis = parseJsonArray(settings.reaction_emojis).map(e => e.emoji || e.id || String(e));
    for (const emoji of emojis.slice(0, 10)) {
        try { await message.react(emoji); } catch (_) {}
    }
}

function attachAutoReactEvents(client, botId) {
    client.on('messageCreate', async (message) => {
        try {
            await handleMessage(message, botId);
        } catch (e) {
            console.error(`[AutoReact] Bot ${botId}: error:`, e?.message);
        }
    });
}

module.exports = { attachAutoReactEvents };
