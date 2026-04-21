// PFAD: /core/installer/src/services/welcomer-service.js

const { EmbedBuilder, AuditLogEvent } = require('discord.js');
const { dbQuery } = require('../db');

async function loadWelcomerSettings(botId) {
    try {
        const rows = await dbQuery(
            'SELECT * FROM bot_welcomer_settings WHERE bot_id = ? LIMIT 1',
            [Number(botId)]
        );
        if (!Array.isArray(rows) || rows.length === 0) return null;
        return rows[0];
    } catch (_) {
        return null;
    }
}

function resolveVars(text, user, guild) {
    if (typeof text !== 'string') return '';
    return text
        .replace(/\{user_name\}|\{user\.name\}/gi, user.username)
        .replace(/\{user\}/gi,                     user.tag ?? user.username)
        .replace(/\{user\.id\}|\{user_id\}/gi,     user.id)
        .replace(/\{server\}|\{server\.name\}/gi,  guild ? guild.name : '')
        .replace(/\{server\.id\}|\{server_id\}/gi, guild ? guild.id   : '')
        .replace(/\{member_count\}/gi,             guild ? String(guild.memberCount) : '');
}

function buildWelcomeEmbed(settings, member) {
    const embed = new EmbedBuilder();

    const color = String(settings.wc_title_color || '#6366f1').trim();
    embed.setColor(/^#[0-9a-fA-F]{6}$/.test(color) ? color : '#6366f1');

    const title = resolveVars(String(settings.wc_title || '{user_name}'), member.user, member.guild);
    if (title) embed.setTitle(title);

    const desc = resolveVars(String(settings.wc_desc || 'Welcome to {server}'), member.user, member.guild);
    if (desc) embed.setDescription(desc);

    const avatarUrl = member.user.displayAvatarURL({ size: 256 });
    if (avatarUrl) embed.setThumbnail(avatarUrl);

    if (member.guild) embed.setFooter({ text: member.guild.name });
    embed.setTimestamp();

    return embed;
}

/* ── guildMemberAdd ── */
async function handleMemberAdd(member, botId) {
    const settings = await loadWelcomerSettings(botId);
    if (!settings) return;

    const guild = member.guild;

    // ── Welcome Card ──────────────────────────────────────────────
    const cardTpl = String(settings.welcome_card_tpl || '').trim();
    if (cardTpl !== '') {
        const embed     = buildWelcomeEmbed(settings, member);
        const reactions = String(settings.wc_reactions || '')
            .split(',')
            .map((s) => s.trim())
            .filter(Boolean)
            .slice(0, 5);

        if (cardTpl === 'channel' || cardTpl === 'both') {
            const channelId = String(settings.wc_channel || '').trim();
            if (channelId) {
                try {
                    const ch = await guild.channels.fetch(channelId).catch(() => null);
                    if (ch && ch.isTextBased()) {
                        const msg = await ch.send({ embeds: [embed] });
                        for (const emoji of reactions) {
                            await msg.react(emoji).catch(() => {});
                        }
                    }
                } catch (err) {
                    console.warn(`[welcomer-service] Bot ${botId} card→channel failed:`, err.message);
                }
            }
        }

        if (cardTpl === 'dm' || cardTpl === 'both') {
            try {
                await member.user.send({ embeds: [embed] }).catch(() => {});
            } catch (_) {}
        }
    }

    // ── Message on Join ──────────────────────────────────────────
    if (Number(settings.msg_join) === 1) {
        const channelId = String(settings.msg_join_channel || '').trim();
        const content   = resolveVars(String(settings.msg_join_content || 'Welcome, {user_name}!'), member.user, guild);
        if (channelId && content) {
            try {
                const ch = await guild.channels.fetch(channelId).catch(() => null);
                if (ch && ch.isTextBased()) await ch.send(content);
            } catch (err) {
                console.warn(`[welcomer-service] Bot ${botId} msg_join failed:`, err.message);
            }
        }
    }

    // ── DM on Join ───────────────────────────────────────────────
    if (Number(settings.dm_join) === 1) {
        const content = resolveVars(String(settings.dm_join_content || 'Welcome, {user_name}!'), member.user, guild);
        if (content) {
            try {
                await member.user.send(content).catch(() => {});
            } catch (_) {}
        }
    }

    // ── Add Role on Join ─────────────────────────────────────────
    if (Number(settings.role_join) === 1) {
        let roles = [];
        try {
            const raw = settings.role_join_roles;
            if (raw) {
                const parsed = JSON.parse(String(raw));
                if (Array.isArray(parsed)) roles = parsed;
            }
        } catch (_) {}

        for (const roleId of roles) {
            await member.roles.add(String(roleId)).catch(() => {});
        }
    }
}

/* ── guildMemberRemove ── */
async function handleMemberRemove(member, botId) {
    const settings = await loadWelcomerSettings(botId);
    if (!settings) return;

    const guild = member.guild;

    // Check audit log for kick (within last 5 seconds)
    let wasKicked = false;
    try {
        const auditLogs = await guild.fetchAuditLogs({
            type:  AuditLogEvent.MemberKick,
            limit: 5,
        }).catch(() => null);

        if (auditLogs) {
            wasKicked = auditLogs.entries.some(
                (e) => e.target && e.target.id === member.user.id && Date.now() - e.createdTimestamp < 5000
            );
        }
    } catch (_) {}

    if (wasKicked) {
        if (Number(settings.msg_kick) === 1) {
            const channelId = String(settings.msg_kick_channel || '').trim();
            const content   = resolveVars(String(settings.msg_kick_content || '{user_name} was kicked.'), member.user, guild);
            if (channelId && content) {
                try {
                    const ch = await guild.channels.fetch(channelId).catch(() => null);
                    if (ch && ch.isTextBased()) await ch.send(content);
                } catch (_) {}
            }
        }
        return;
    }

    // ── Message on Leave ─────────────────────────────────────────
    if (Number(settings.msg_leave) === 1) {
        const channelId = String(settings.msg_leave_channel || '').trim();
        const content   = resolveVars(String(settings.msg_leave_content || 'Goodbye, {user_name}!'), member.user, guild);
        if (channelId && content) {
            try {
                const ch = await guild.channels.fetch(channelId).catch(() => null);
                if (ch && ch.isTextBased()) await ch.send(content);
            } catch (_) {}
        }
    }
}

/* ── guildBanAdd ── */
async function handleBanAdd(ban, botId) {
    const settings = await loadWelcomerSettings(botId);
    if (!settings || Number(settings.msg_ban) !== 1) return;

    const guild     = ban.guild;
    const channelId = String(settings.msg_ban_channel || '').trim();
    const content   = resolveVars(String(settings.msg_ban_content || '{user_name} was banned.'), ban.user, guild);

    if (channelId && content) {
        try {
            const ch = await guild.channels.fetch(channelId).catch(() => null);
            if (ch && ch.isTextBased()) await ch.send(content);
        } catch (_) {}
    }
}

/* ── attach all handlers to a client ── */
function attachWelcomerEvents(client, botId) {
    client.on('guildMemberAdd', async (member) => {
        try {
            await handleMemberAdd(member, botId);
        } catch (err) {
            console.error(`[welcomer-service] Bot ${botId} guildMemberAdd error:`,
                err instanceof Error ? err.message : String(err));
        }
    });

    client.on('guildMemberRemove', async (member) => {
        try {
            await handleMemberRemove(member, botId);
        } catch (err) {
            console.error(`[welcomer-service] Bot ${botId} guildMemberRemove error:`,
                err instanceof Error ? err.message : String(err));
        }
    });

    client.on('guildBanAdd', async (ban) => {
        try {
            await handleBanAdd(ban, botId);
        } catch (err) {
            console.error(`[welcomer-service] Bot ${botId} guildBanAdd error:`,
                err instanceof Error ? err.message : String(err));
        }
    });

    console.log(`[welcomer-service] Bot ${botId}: event handlers attached.`);
}

module.exports = { attachWelcomerEvents };
