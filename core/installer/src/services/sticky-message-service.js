// PFAD: /core/installer/src/services/sticky-message-service.js

const { EmbedBuilder } = require('discord.js');
const { dbQuery } = require('../db');

async function loadSettings(botId) {
    try {
        const rows = await dbQuery(
            'SELECT * FROM bot_sticky_settings WHERE bot_id = ? LIMIT 1',
            [Number(botId)]
        );
        if (!Array.isArray(rows) || rows.length === 0) return null;
        return rows[0];
    } catch (_) { return null; }
}

async function loadStickyChannel(botId, channelId) {
    try {
        const rows = await dbQuery(
            'SELECT * FROM bot_sticky_channels WHERE bot_id = ? AND channel_id = ? LIMIT 1',
            [Number(botId), String(channelId)]
        );
        return Array.isArray(rows) && rows.length > 0 ? rows[0] : null;
    } catch (_) { return null; }
}

function buildEmbed(sticky, posterUser = null, showAuthor = false) {
    const color = String(sticky.embed_color || '#f48342');
    const embed = new EmbedBuilder()
        .setColor(/^#[0-9a-fA-F]{6}$/.test(color) ? color : '#f48342');

    if (showAuthor && posterUser) {
        embed.setAuthor({
            name: posterUser.username,
            iconURL: posterUser.displayAvatarURL({ size: 64 }),
        });
    } else if (sticky.embed_author) {
        embed.setAuthor({ name: String(sticky.embed_author) });
    }

    if (sticky.embed_title)     embed.setTitle(String(sticky.embed_title));
    if (sticky.embed_body)      embed.setDescription(String(sticky.embed_body));
    if (sticky.embed_thumbnail) embed.setThumbnail(String(sticky.embed_thumbnail));
    if (sticky.embed_image)     embed.setImage(String(sticky.embed_image));
    if (sticky.embed_url)       embed.setURL(String(sticky.embed_url));
    if (sticky.embed_footer)    embed.setFooter({ text: String(sticky.embed_footer) });

    return embed;
}

async function repostSticky(client, channel, sticky, settings) {
    // Delete previous sticky message
    if (sticky.last_message_id) {
        try {
            const old = await channel.messages.fetch(sticky.last_message_id);
            if (old) await old.delete();
        } catch (_) {}
    }

    // Fetch poster user for show_author
    let posterUser = null;
    if (Number(settings.show_author) === 1 && sticky.posted_by) {
        try { posterUser = await client.users.fetch(sticky.posted_by); } catch (_) {}
    }

    // Send new sticky
    let sent = null;
    try {
        if (Number(sticky.is_embed) === 1) {
            const embed = buildEmbed(sticky, posterUser, Number(settings.show_author) === 1);
            sent = await channel.send({ embeds: [embed] });
        } else {
            const text = String(sticky.plain_text || '').trim();
            if (text) sent = await channel.send(text);
        }
    } catch (e) {
        console.error(`[StickyMsg] Send failed in ${channel.id}:`, e?.message);
        return;
    }

    if (!sent) return;

    // Add reaction if configured
    if (Number(settings.add_reaction) === 1 && settings.reaction_emoji) {
        try { await sent.react(String(settings.reaction_emoji)); } catch (_) {}
    }

    // Update DB
    try {
        await dbQuery(
            `UPDATE bot_sticky_channels
             SET last_message_id = ?, message_count = 0
             WHERE bot_id = ? AND channel_id = ?`,
            [sent.id, Number(sticky.bot_id), String(channel.id)]
        );
    } catch (_) {}
}

async function handleMessage(message, botId) {
    if (message.author.bot || !message.inGuild()) return;

    const settings = await loadSettings(botId);
    if (!settings || Number(settings.evt_handler) !== 1) return;

    const sticky = await loadStickyChannel(botId, message.channelId);
    if (!sticky) return;

    // Increment message count
    const newCount = Number(sticky.message_count || 0) + 1;
    const repostAt = Math.max(1, Number(settings.repost_count || 10));

    await dbQuery(
        'UPDATE bot_sticky_channels SET message_count = ? WHERE bot_id = ? AND channel_id = ?',
        [newCount, Number(botId), String(message.channelId)]
    ).catch(() => {});

    if (newCount >= repostAt) {
        // Reload latest sticky data before reposting (may have been updated)
        const freshSticky = await loadStickyChannel(botId, message.channelId);
        if (freshSticky) {
            await repostSticky(message.client, message.channel, freshSticky, settings);
        }
    }
}

function attachStickyMessageEvents(client, botId) {
    client.on('messageCreate', async (message) => {
        try {
            await handleMessage(message, botId);
        } catch (e) {
            console.error(`[StickyMsg] Bot ${botId}: error:`, e?.message);
        }
    });
}

module.exports = { attachStickyMessageEvents, buildEmbed };
