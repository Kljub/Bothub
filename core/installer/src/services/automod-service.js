// PFAD: /core/installer/src/services/automod-service.js

const { dbQuery } = require('../db');

// ── In-memory spam tracker (per bot) ─────────────────────────────────────────
// Map<botId, Map<userId, number[]>> — timestamps of recent messages
const spamTrackers = new Map();

function getSpamTracker(botId) {
    if (!spamTrackers.has(botId)) spamTrackers.set(botId, new Map());
    return spamTrackers.get(botId);
}

// ── Load settings from DB ─────────────────────────────────────────────────────
async function loadSettings(botId) {
    try {
        const rows = await dbQuery(
            'SELECT * FROM bot_automod_settings WHERE bot_id = ? LIMIT 1',
            [Number(botId)]
        );
        if (!Array.isArray(rows) || rows.length === 0) return null;
        const row = rows[0];
        row.link_channels = parseJsonArray(row.link_channels);
        row.blacklist      = parseJsonArray(row.blacklist);
        return row;
    } catch (_) { return null; }
}

function parseJsonArray(raw) {
    if (!raw) return [];
    try { const a = typeof raw === 'string' ? JSON.parse(raw) : raw; return Array.isArray(a) ? a : []; }
    catch (_) { return []; }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
const INVITE_RE = /discord(?:\.gg|(?:app)?\.com\/invite)\/[a-zA-Z0-9-]+/i;
const URL_RE    = /https?:\/\/[^\s]+/i;

async function tryDelete(message, reason) {
    try { await message.delete(); } catch (_) {}
    return `[Automod] Deleted message (${reason}) from ${message.author.tag} in ${message.guild?.name}`;
}

async function warnUser(message, reason) {
    try {
        await message.channel.send(
            `<@${message.author.id}>, your message was removed: **${reason}**.`
        );
    } catch (_) {}
}

async function kickUser(message) {
    try {
        if (message.member?.kickable) await message.member.kick('Automod: spam');
    } catch (_) {}
}

async function banUser(message) {
    try {
        if (message.member?.bannable) await message.member.ban({ reason: 'Automod: spam', deleteMessageSeconds: 0 });
    } catch (_) {}
}

async function logAction(s, message, action) {
    const logId = String(s.log_channel_id || '').trim();
    if (!logId || !message.guild) return;
    try {
        const ch = await message.guild.channels.fetch(logId).catch(() => null);
        if (!ch || !ch.isTextBased()) return;
        await ch.send(
            `**[Automod]** Action: \`${action}\` | User: ${message.author.tag} (\`${message.author.id}\`) | Channel: <#${message.channelId}>`
        );
    } catch (_) {}
}

// ── Main message handler ──────────────────────────────────────────────────────
async function handleMessage(message, botId) {
    if (message.author.bot || !message.inGuild()) return;

    const s = await loadSettings(botId);
    if (!s) return;

    const content = message.content || '';

    // 1. Anti-Invite
    if (s.anti_invite && INVITE_RE.test(content)) {
        const log = await tryDelete(message, 'invite link');
        console.log(log);
        await logAction(s, message, 'anti_invite');
        return;
    }

    // 2. Anti-Links (link channels are exempt)
    if (s.anti_links && URL_RE.test(content)) {
        const exempt = s.link_channels.map(String).includes(String(message.channelId));
        if (!exempt) {
            const log = await tryDelete(message, 'link');
            console.log(log);
            await logAction(s, message, 'anti_links');
            return;
        }
    }

    // 3. Blacklisted words
    if (s.blacklist.length > 0) {
        const lower = content.toLowerCase();
        const matched = s.blacklist.find(w => lower.includes(String(w).toLowerCase()));
        if (matched) {
            const log = await tryDelete(message, `blacklisted word: ${matched}`);
            console.log(log);
            await logAction(s, message, 'blacklist');
            return;
        }
    }

    // 4. Anti-Spam
    if (s.anti_spam) {
        const tracker = getSpamTracker(botId);
        const now     = Date.now();
        const userId  = message.author.id;
        const windowMs = Number(s.spam_window_s || 5) * 1000;
        const maxMsg   = Number(s.spam_max_msg  || 5);

        const timestamps = (tracker.get(userId) || []).filter(t => now - t < windowMs);
        timestamps.push(now);
        tracker.set(userId, timestamps);

        if (timestamps.length > maxMsg) {
            tracker.set(userId, []); // reset after trigger
            await tryDelete(message, 'spam');
            await logAction(s, message, `anti_spam:${s.spam_action}`);

            const action = s.spam_action || 'delete';
            if (action === 'warn') {
                await warnUser(message, 'spamming');
            } else if (action === 'kick') {
                await warnUser(message, 'spamming');
                await kickUser(message);
            } else if (action === 'ban') {
                await banUser(message);
            }
        }
    }
}

// ── Attach ────────────────────────────────────────────────────────────────────
function attachAutomodEvents(client, botId) {
    client.on('messageCreate', async (message) => {
        try {
            await handleMessage(message, botId);
        } catch (e) {
            console.error(`[Automod] Bot ${botId}: error:`, e?.message);
        }
    });
}

module.exports = { attachAutomodEvents };
