// PFAD: /core/installer/src/services/invite-tracker-service.js

const { dbQuery } = require('../db');

// Per-bot guild invite cache
// inviteCache.get(botId)?.get(guildId)?.get(code) → { uses, inviterId, inviterName }
const inviteCache = new Map(); // botId → Map<guildId, Map<code, {uses, inviterId, inviterName}>>

const DEFAULT_JOIN_MESSAGE = '👋 **{user_name}** joined using invite **{invite_code}** by **{inviter_name}** (total uses: {invite_uses})';

// ── DB helpers ────────────────────────────────────────────────────────────────

async function ensureTables() {
    await dbQuery(`CREATE TABLE IF NOT EXISTS \`bot_invite_tracker_settings\` (
        \`bot_id\`       BIGINT UNSIGNED NOT NULL,
        \`guild_id\`     VARCHAR(20)     NOT NULL DEFAULT '',
        \`enabled\`      TINYINT(1)      NOT NULL DEFAULT 1,
        \`channel_id\`   VARCHAR(20)     NOT NULL DEFAULT '',
        \`join_message\` TEXT            NULL,
        \`updated_at\`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY \`uq_bot_guild\` (\`bot_id\`, \`guild_id\`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);

    await dbQuery(`CREATE TABLE IF NOT EXISTS \`bot_invite_stats\` (
        \`id\`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        \`bot_id\`        BIGINT UNSIGNED NOT NULL,
        \`guild_id\`      VARCHAR(20)     NOT NULL,
        \`inviter_id\`    VARCHAR(20)     NOT NULL,
        \`inviter_name\`  VARCHAR(100)    NOT NULL DEFAULT '',
        \`invite_code\`   VARCHAR(20)     NOT NULL,
        \`uses\`          INT UNSIGNED    NOT NULL DEFAULT 0,
        \`last_used_at\`  DATETIME        NULL,
        PRIMARY KEY (\`id\`),
        UNIQUE KEY \`uq_bot_guild_code\` (\`bot_id\`, \`guild_id\`, \`invite_code\`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);
}

async function loadSettings(botId, guildId) {
    try {
        const rows = await dbQuery(
            'SELECT * FROM bot_invite_tracker_settings WHERE bot_id = ? AND guild_id = ? LIMIT 1',
            [Number(botId), String(guildId)]
        );
        return rows[0] || null;
    } catch (_) {
        return null;
    }
}

// ── Invite cache helpers ──────────────────────────────────────────────────────

function getBotCache(botId) {
    const key = Number(botId);
    if (!inviteCache.has(key)) inviteCache.set(key, new Map());
    return inviteCache.get(key);
}

async function cacheGuildInvites(guild, botId) {
    try {
        const invites = await guild.invites.fetch();
        const guildMap = new Map();
        for (const [code, inv] of invites) {
            guildMap.set(code, {
                uses:        inv.uses ?? 0,
                inviterId:   inv.inviter?.id   ?? null,
                inviterName: inv.inviter?.username ?? 'Unknown',
            });
        }
        getBotCache(botId).set(guild.id, guildMap);
    } catch (_) {
        // Bot may lack MANAGE_GUILD permission — silently skip
    }
}

// ── Variable resolution ───────────────────────────────────────────────────────

function resolveVars(template, member, inviteCode, inviterName, inviteUses) {
    if (typeof template !== 'string') return '';
    const guild = member.guild;
    return template
        .replace(/\{user_name\}/gi,    member.user.username)
        .replace(/\{user\}/gi,         `<@${member.user.id}>`)
        .replace(/\{user_id\}/gi,      member.user.id)
        .replace(/\{server\}/gi,       guild?.name ?? '')
        .replace(/\{member_count\}/gi, String(guild?.memberCount ?? ''))
        .replace(/\{invite_code\}/gi,  inviteCode   ?? 'unknown')
        .replace(/\{inviter_name\}/gi, inviterName  ?? 'Unknown')
        .replace(/\{inviter\}/gi,      inviterName  ?? 'Unknown')
        .replace(/\{invite_uses\}/gi,  String(inviteUses ?? 0));
}

// ── Core join handler ─────────────────────────────────────────────────────────

async function handleMemberAdd(member, botId) {
    const numericBotId = Number(botId);
    const guildId      = member.guild.id;

    // Load settings
    const settings = await loadSettings(numericBotId, guildId);
    if (!settings || !Number(settings.enabled)) return;
    if (!settings.channel_id) return;

    // Fetch new invite list to compare with cache
    let newInvites;
    try {
        newInvites = await member.guild.invites.fetch();
    } catch (_) {
        return; // No permission
    }

    const botGuildCache = getBotCache(numericBotId);
    const oldMap        = botGuildCache.get(guildId) ?? new Map();

    // Find which invite count increased
    let usedCode     = null;
    let usedInvite   = null;
    let usedInviterName = 'Unknown';
    let usedInviterId   = null;
    let usedUses        = 0;

    for (const [code, inv] of newInvites) {
        const oldUses = oldMap.get(code)?.uses ?? 0;
        const newUses = inv.uses ?? 0;
        if (newUses > oldUses) {
            usedCode        = code;
            usedInvite      = inv;
            usedInviterName = inv.inviter?.username ?? 'Unknown';
            usedInviterId   = inv.inviter?.id ?? null;
            usedUses        = newUses;
            break;
        }
    }

    // Update cache with new invite state
    const newMap = new Map();
    for (const [code, inv] of newInvites) {
        newMap.set(code, {
            uses:        inv.uses ?? 0,
            inviterId:   inv.inviter?.id   ?? null,
            inviterName: inv.inviter?.username ?? 'Unknown',
        });
    }
    botGuildCache.set(guildId, newMap);

    // Update stats in DB
    if (usedCode && usedInviterId) {
        const nowStr = new Date().toISOString().slice(0, 19).replace('T', ' ');
        try {
            await dbQuery(
                `INSERT INTO bot_invite_stats (bot_id, guild_id, inviter_id, inviter_name, invite_code, uses, last_used_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     inviter_name = VALUES(inviter_name),
                     uses         = VALUES(uses),
                     last_used_at = VALUES(last_used_at)`,
                [numericBotId, guildId, usedInviterId, usedInviterName, usedCode, usedUses, nowStr]
            );
        } catch (_) {}
    }

    // Send join message
    try {
        const channel = await member.guild.channels.fetch(settings.channel_id).catch(() => null);
        if (!channel || !channel.isTextBased()) return;

        const template = String(settings.join_message || DEFAULT_JOIN_MESSAGE);
        const text     = resolveVars(template, member, usedCode, usedInviterName, usedUses);
        if (text) await channel.send(text);
    } catch (e) {
        console.error(`[InviteTracker] Bot ${numericBotId}: send failed:`, e?.message);
    }
}

// ── Service attach ────────────────────────────────────────────────────────────

function attachInviteTrackerEvents(client, botId) {
    const numericBotId = Number(botId);

    ensureTables().catch(err => {
        console.warn(`[InviteTracker] Bot ${numericBotId}: table init failed:`, err.message);
    });

    // Cache invites when bot is ready
    client.once('ready', async () => {
        for (const guild of client.guilds.cache.values()) {
            await cacheGuildInvites(guild, numericBotId);
        }
    });

    // Cache invites when joining a new guild
    client.on('guildCreate', async (guild) => {
        await cacheGuildInvites(guild, numericBotId);
    });

    // Update cache when invites are created/deleted
    client.on('inviteCreate', async (invite) => {
        try {
            const guildId = invite.guild?.id;
            if (!guildId) return;
            const guildMap = getBotCache(numericBotId).get(guildId) ?? new Map();
            guildMap.set(invite.code, {
                uses:        invite.uses ?? 0,
                inviterId:   invite.inviter?.id   ?? null,
                inviterName: invite.inviter?.username ?? 'Unknown',
            });
            getBotCache(numericBotId).set(guildId, guildMap);
        } catch (_) {}
    });

    client.on('inviteDelete', async (invite) => {
        try {
            const guildId = invite.guild?.id;
            if (!guildId) return;
            getBotCache(numericBotId).get(guildId)?.delete(invite.code);
        } catch (_) {}
    });

    // Track who joined
    client.on('guildMemberAdd', (member) => {
        handleMemberAdd(member, numericBotId).catch(err => {
            console.error(`[InviteTracker] Bot ${numericBotId}: guildMemberAdd error:`, err.message);
        });
    });
}

module.exports = { attachInviteTrackerEvents, ensureTables };
