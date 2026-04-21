// PFAD: /core/installer/src/commands/sticky-post.js
const { SlashCommandBuilder, PermissionFlagsBits } = require('discord.js');
const { dbQuery } = require('../../db');
const { buildEmbed } = require('../../services/sticky-message-service');

async function getStickySettings(botId) {
    try {
        const rows = await dbQuery(
            'SELECT * FROM bot_sticky_settings WHERE bot_id = ? LIMIT 1',
            [Number(botId)]
        );
        return Array.isArray(rows) && rows.length > 0 ? rows[0] : null;
    } catch (_) { return null; }
}

module.exports = {
    key: 'sticky-post',

    data: new SlashCommandBuilder()
        .setName('sticky-post')
        .setDescription('Post a sticky message to a channel.')
        .addChannelOption(o =>
            o.setName('channel').setDescription('The channel to post the sticky in').setRequired(true)
        )
        .addStringOption(o =>
            o.setName('title').setDescription('Override the embed title').setRequired(false)
        )
        .addStringOption(o =>
            o.setName('message').setDescription('Override the embed body / plain text').setRequired(false)
        ),

    async execute(interaction, botId) {
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Only usable in a server.', ephemeral: true });
        }

        const settings = await getStickySettings(botId);

        // Check manager role
        if (settings && settings.manager_role_id) {
            const memberRoles = interaction.member?.roles?.cache
                ? [...interaction.member.roles.cache.keys()]
                : [];
            const isAdmin = interaction.memberPermissions?.has(PermissionFlagsBits.ManageGuild);
            if (!memberRoles.includes(String(settings.manager_role_id)) && !isAdmin) {
                return interaction.reply({ content: '❌ You do not have permission to post sticky messages.', ephemeral: true });
            }
        }

        const channel = interaction.options.getChannel('channel', true);
        if (!channel.isTextBased()) {
            return interaction.reply({ content: '❌ Please select a text channel.', ephemeral: true });
        }

        const titleOverride   = interaction.options.getString('title')   || null;
        const messageOverride = interaction.options.getString('message') || null;

        // Build sticky data from settings + overrides
        const sticky = {
            bot_id:         botId,
            is_embed:       settings?.is_embed ?? 1,
            plain_text:     messageOverride ?? settings?.plain_text ?? '',
            embed_author:   settings?.embed_author   ?? '',
            embed_thumbnail:settings?.embed_thumbnail ?? '',
            embed_title:    titleOverride   ?? settings?.embed_title   ?? 'Sticky Messages',
            embed_body:     messageOverride ?? settings?.embed_body    ?? '',
            embed_image:    settings?.embed_image    ?? '',
            embed_color:    settings?.embed_color    ?? '#f48342',
            embed_url:      settings?.embed_url      ?? '',
            embed_footer:   settings?.embed_footer   ?? 'Sticky messages module',
        };

        await interaction.deferReply({ ephemeral: true });

        let sent = null;
        try {
            if (Number(sticky.is_embed) === 1) {
                const showAuthor = Number(settings?.show_author ?? 1) === 1;
                const embed = buildEmbed(sticky, showAuthor ? interaction.user : null, showAuthor);
                sent = await channel.send({ embeds: [embed] });
            } else {
                const text = String(sticky.plain_text || '').trim();
                if (!text) {
                    return interaction.editReply({ content: '❌ No message content configured.' });
                }
                sent = await channel.send(text);
            }
        } catch (e) {
            return interaction.editReply({ content: `❌ Failed to send: ${e.message}` });
        }

        // Add reaction if configured
        if (sent && Number(settings?.add_reaction) === 1 && settings?.reaction_emoji) {
            try { await sent.react(String(settings.reaction_emoji)); } catch (_) {}
        }

        // Upsert sticky channel record
        try {
            await dbQuery(
                `INSERT INTO bot_sticky_channels
                  (bot_id, channel_id, last_message_id, message_count, posted_by,
                   is_embed, plain_text, embed_author, embed_thumbnail, embed_title,
                   embed_body, embed_image, embed_color, embed_url, embed_footer)
                VALUES (?,?,?,0,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                  last_message_id = VALUES(last_message_id),
                  message_count   = 0,
                  posted_by       = VALUES(posted_by),
                  is_embed        = VALUES(is_embed),
                  plain_text      = VALUES(plain_text),
                  embed_author    = VALUES(embed_author),
                  embed_thumbnail = VALUES(embed_thumbnail),
                  embed_title     = VALUES(embed_title),
                  embed_body      = VALUES(embed_body),
                  embed_image     = VALUES(embed_image),
                  embed_color     = VALUES(embed_color),
                  embed_url       = VALUES(embed_url),
                  embed_footer    = VALUES(embed_footer)`,
                [
                    Number(botId), channel.id, sent.id, interaction.user.id,
                    Number(sticky.is_embed), sticky.plain_text || null,
                    sticky.embed_author, sticky.embed_thumbnail, sticky.embed_title,
                    sticky.embed_body || null, sticky.embed_image,
                    sticky.embed_color, sticky.embed_url, sticky.embed_footer,
                ]
            );
        } catch (e) {
            console.error('[StickyPost] DB error:', e?.message);
        }

        return interaction.editReply({ content: `✅ Sticky message posted in <#${channel.id}>!` });
    },
};
