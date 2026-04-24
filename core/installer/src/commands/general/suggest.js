// /core/installer/src/commands/general/suggest.js
const { SlashCommandBuilder, EmbedBuilder } = require('discord.js');
const { dbQuery } = require('../../db');
const { loadSettings, buildVoteRow } = require('../../services/suggestion-service');

module.exports = {
    key: 'suggest',

    data: new SlashCommandBuilder()
        .setName('suggest')
        .setDescription('Sende einen Vorschlag an den Suggestion-Channel.')
        .addStringOption(o =>
            o.setName('anmerkung')
                .setDescription('Dein Vorschlag oder deine Anmerkung')
                .setRequired(true)
                .setMaxLength(1000)
        ),

    async execute(interaction, botId) {
        const cmdRow = (await dbQuery(
            'SELECT is_enabled FROM commands WHERE bot_id = ? AND command_key = ?',
            [botId, 'suggest']
        ))[0];
        if (cmdRow && !cmdRow.is_enabled) {
            return interaction.reply({ content: '❌ Dieses Modul ist deaktiviert.', ephemeral: true });
        }

        const guild = interaction.guild;
        if (!guild) {
            return interaction.reply({ content: '❌ Dieser Befehl kann nur auf einem Server verwendet werden.', ephemeral: true });
        }

        const settings = await loadSettings(botId, guild.id).catch(() => null);
        if (!settings?.channel_id) {
            return interaction.reply({ content: '❌ Es wurde noch kein Suggestion-Channel konfiguriert.', ephemeral: true });
        }

        const text    = interaction.options.getString('anmerkung', true).trim();
        const author  = interaction.user;
        const style   = settings.button_style ?? 'arrows';

        const embed = new EmbedBuilder()
            .setColor(0x6c63ff)
            .setAuthor({
                name:    author.globalName ?? author.username,
                iconURL: author.displayAvatarURL(),
            })
            .setDescription(text)
            .setFooter({ text: `💡 Vorschlag · #${interaction.channel?.name ?? 'unbekannt'}` })
            .setTimestamp();

        const channel = await guild.channels.fetch(settings.channel_id).catch(() => null);
        if (!channel?.isTextBased()) {
            return interaction.reply({ content: '❌ Der konfigurierte Suggestion-Channel ist nicht erreichbar.', ephemeral: true });
        }

        const row = buildVoteRow(style, 0, 0);
        await channel.send({ embeds: [embed], components: [row] });

        return interaction.reply({ content: '✅ Dein Vorschlag wurde eingereicht!', ephemeral: true });
    },
};
