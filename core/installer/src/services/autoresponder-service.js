// PFAD: /core/installer/src/services/autoresponder-service.js

const { EmbedBuilder } = require('discord.js');
const { dbQuery } = require('../db');

function parseJsonArray(raw) {
    if (!raw) return [];
    try { const a = typeof raw === 'string' ? JSON.parse(raw) : raw; return Array.isArray(a) ? a : []; }
    catch (_) { return []; }
}

async function loadAutoresponders(botId) {
    try {
        const rows = await dbQuery(
            'SELECT * FROM bot_autoresponders WHERE bot_id = ? AND is_active = 1',
            [Number(botId)]
        );
        return Array.isArray(rows) ? rows : [];
    } catch (_) { return []; }
}

async function isCooledDown(arId, channelId, cooldownSec) {
    if (cooldownSec <= 0) return false;
    try {
        const rows = await dbQuery(
            'SELECT last_sent_at FROM bot_autoresponder_cooldowns WHERE ar_id = ? AND channel_id = ?',
            [Number(arId), String(channelId)]
        );
        if (!Array.isArray(rows) || rows.length === 0) return false;
        const last = new Date(rows[0].last_sent_at).getTime();
        return (Date.now() - last) < cooldownSec * 1000;
    } catch (_) { return false; }
}

async function updateCooldown(arId, channelId) {
    try {
        await dbQuery(
            `INSERT INTO bot_autoresponder_cooldowns (ar_id, channel_id, last_sent_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE last_sent_at = NOW()`,
            [Number(arId), String(channelId)]
        );
    } catch (_) {}
}

function messageMatches(content, keywords, triggerType) {
    const lower = content.toLowerCase();
    for (const kw of keywords) {
        const k = kw.toLowerCase();
        if (triggerType === 'exact'       && lower === k)           return true;
        if (triggerType === 'starts_with' && lower.startsWith(k))   return true;
        if (triggerType === 'contains'    && lower.includes(k))     return true;
    }
    return false;
}

function resolveVars(text, user) {
    return text
        .replace(/\{user\}/gi,       `<@${user.id}>`)
        .replace(/\{user\.name\}/gi, user.username)
        .replace(/\{user\.id\}/gi,   user.id);
}

async function handleMessage(message, botId) {
    if (message.author.bot || !message.inGuild()) return;

    const content = message.content || '';
    const ars = await loadAutoresponders(botId);

    for (const ar of ars) {
        const keywords = parseJsonArray(ar.keywords);
        if (!keywords.length) continue;

        if (!messageMatches(content, keywords, ar.trigger_type || 'contains')) continue;

        // Channel filter
        const chFilterType = ar.channel_filter_type || 'all_except';
        const filteredCh = parseJsonArray(ar.filtered_channels).map(i => i.id || i);
        if (chFilterType === 'all_except' && filteredCh.includes(message.channelId)) continue;
        if (chFilterType === 'selected'   && filteredCh.length > 0 && !filteredCh.includes(message.channelId)) continue;

        // Role filter
        const roleFilterType = ar.role_filter_type || 'all_except';
        const filteredRoles = parseJsonArray(ar.filtered_roles).map(i => i.id || i);
        if (filteredRoles.length > 0) {
            const memberRoles = message.member ? [...message.member.roles.cache.keys()] : [];
            if (roleFilterType === 'all_except' && filteredRoles.some(r => memberRoles.includes(r))) continue;
            if (roleFilterType === 'selected'   && !filteredRoles.some(r => memberRoles.includes(r))) continue;
        }

        // Cooldown
        const cooldown = Number(ar.channel_cooldown || 0);
        if (await isCooledDown(ar.id, message.channelId, cooldown)) continue;

        // Build reply
        const mention = Number(ar.mention_user) === 1 ? `<@${message.author.id}> ` : '';
        let replyOptions;

        if (Number(ar.is_embed) === 1) {
            const color = String(ar.embed_color || '#ef4444');
            const embed = new EmbedBuilder()
                .setColor(/^#[0-9a-fA-F]{6}$/.test(color) ? color : '#ef4444');

            if (ar.embed_author)    embed.setAuthor({ name: resolveVars(String(ar.embed_author), message.author) });
            if (ar.embed_title)     embed.setTitle(resolveVars(String(ar.embed_title), message.author));
            if (ar.embed_body)      embed.setDescription(resolveVars(String(ar.embed_body), message.author));
            if (ar.embed_thumbnail) embed.setThumbnail(String(ar.embed_thumbnail));
            if (ar.embed_image)     embed.setImage(String(ar.embed_image));
            if (ar.embed_url)       embed.setURL(String(ar.embed_url));

            replyOptions = { content: mention || undefined, embeds: [embed] };
        } else {
            const text = resolveVars(String(ar.plain_text || ''), message.author);
            if (!text && !mention) continue;
            replyOptions = { content: mention + text };
        }

        try {
            await message.reply(replyOptions);
            await updateCooldown(ar.id, message.channelId);
        } catch (e) {
            console.error(`[Autoresponder] Bot ${botId}: reply failed:`, e?.message);
        }

        // Only fire first matching autoresponder
        break;
    }
}

function attachAutoresponderEvents(client, botId) {
    client.on('messageCreate', async (message) => {
        try {
            await handleMessage(message, botId);
        } catch (e) {
            console.error(`[Autoresponder] Bot ${botId}: error:`, e?.message);
        }
    });
}

module.exports = { attachAutoresponderEvents };
