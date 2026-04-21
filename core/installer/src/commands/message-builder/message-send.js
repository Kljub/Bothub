const { SlashCommandBuilder, EmbedBuilder, ChannelType } = require('discord.js');
const { dbQuery } = require('../../db');

async function isCommandEnabled(botId, key) {
    const rows = await dbQuery(
        'SELECT is_enabled FROM commands WHERE bot_id = ? AND command_key = ? LIMIT 1',
        [Number(botId), key]
    );
    if (!rows || rows.length === 0) return true;
    return Number(rows[0].is_enabled) === 1;
}

/**
 * Replace template variables with Discord context values.
 */
function resolveVars(text, interaction) {
    if (!text) return text;
    const user    = interaction.member?.user || interaction.user;
    const guild   = interaction.guild;
    const channel = interaction.channel;
    const now     = new Date();

    return String(text)
        .replaceAll('{user}',               user?.username     || '')
        .replaceAll('{user.mention}',        user ? `<@${user.id}>` : '')
        .replaceAll('{user.id}',             user?.id           || '')
        .replaceAll('{user.name}',           user?.username     || '')
        .replaceAll('{user.tag}',            user?.tag          || user?.username || '')
        .replaceAll('{guild.name}',          guild?.name        || '')
        .replaceAll('{guild.id}',            guild?.id          || '')
        .replaceAll('{guild.memberCount}',   String(guild?.memberCount ?? ''))
        .replaceAll('{channel}',             channel ? `<#${channel.id}>` : '')
        .replaceAll('{channel.name}',        channel?.name      || '')
        .replaceAll('{date}',                now.toLocaleDateString('de-DE'))
        .replaceAll('{time}',                now.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }));
}

module.exports = {
    key: 'message-send',

    data: new SlashCommandBuilder()
        .setName('message-send')
        .setDescription('Sendet ein gespeichertes Message-Template in einen Kanal.')
        .addStringOption(o =>
            o.setName('template')
                .setDescription('Name des Templates')
                .setRequired(true)
                .setAutocomplete(true)
        )
        .addChannelOption(o =>
            o.setName('channel')
                .setDescription('Ziel-Kanal (leer = aktueller Kanal)')
                .addChannelTypes(ChannelType.GuildText, ChannelType.GuildAnnouncement)
                .setRequired(false)
        ),

    async autocomplete(interaction, botId) {
        try {
            const focused = String(interaction.options.getFocused() || '').toLowerCase();
            const rows = await dbQuery(
                'SELECT id, name, tag FROM bot_message_templates WHERE bot_id = ? ORDER BY name ASC LIMIT 100',
                [Number(botId)]
            );
            const choices = (rows || [])
                .filter(r => String(r.name || '').toLowerCase().includes(focused))
                .slice(0, 25)
                .map(r => ({
                    name: r.tag ? `${r.name} [${r.tag}]` : String(r.name),
                    value: String(r.name),
                }));
            await interaction.respond(choices);
        } catch (_) {
            try { await interaction.respond([]); } catch (_2) {}
        }
    },

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'message-send')) {
            await interaction.reply({ content: 'Der `/message-send` Befehl ist deaktiviert.', ephemeral: true });
            return;
        }

        const templateName = interaction.options.getString('template', true).trim();
        const targetChannel = interaction.options.getChannel('channel') || interaction.channel;

        if (!targetChannel) {
            await interaction.reply({ content: '❌ Kein Kanal gefunden.', ephemeral: true });
            return;
        }

        const rows = await dbQuery(
            'SELECT * FROM bot_message_templates WHERE bot_id = ? AND name = ? LIMIT 1',
            [Number(botId), templateName]
        );

        if (!rows || rows.length === 0) {
            await interaction.reply({ content: `❌ Template **${templateName}** nicht gefunden.`, ephemeral: true });
            return;
        }

        const tpl = rows[0];

        await interaction.deferReply({ ephemeral: true });

        try {
            if (parseInt(tpl.is_embed)) {
                const color = /^#[0-9a-fA-F]{6}$/.test(tpl.embed_color || '')
                    ? parseInt(tpl.embed_color.replace('#', ''), 16)
                    : 0x5865F2;

                const embed = new EmbedBuilder().setColor(color);

                const author = resolveVars(tpl.embed_author, interaction);
                const title  = resolveVars(tpl.embed_title,  interaction);
                const body   = resolveVars(tpl.embed_body,   interaction);
                const thumb  = tpl.embed_thumbnail || null;
                const image  = tpl.embed_image     || null;
                const url    = tpl.embed_url        || null;

                if (author) embed.setAuthor({ name: author });
                if (title)  embed.setTitle(title);
                if (body)   embed.setDescription(body);
                if (thumb)  embed.setThumbnail(thumb);
                if (image)  embed.setImage(image);
                if (url)    embed.setURL(url);

                await targetChannel.send({ embeds: [embed] });
            } else {
                const text = resolveVars(tpl.plain_text, interaction);
                if (!text) {
                    await interaction.editReply('❌ Template hat keinen Text.');
                    return;
                }
                await targetChannel.send(text);
            }

            await interaction.editReply(`✅ Template **${tpl.name}** wurde in <#${targetChannel.id}> gesendet.`);
        } catch (err) {
            await interaction.editReply(`❌ Fehler: ${err.message}`);
        }
    },
};
