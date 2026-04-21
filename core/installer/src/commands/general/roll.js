const { SlashCommandBuilder } = require('discord.js');
const { isCommandEnabled } = require('../../services/economy-service');

module.exports = {
    key: 'roll',

    data: new SlashCommandBuilder()
        .setName('roll')
        .setDescription('Einen Würfel werfen.')
        .addIntegerOption(o =>
            o.setName('sides').setDescription('Anzahl Seiten (Standard: 6)').setRequired(false).setMinValue(2).setMaxValue(1000)
        ),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'roll')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }

        const sides  = interaction.options.getInteger('sides') ?? 6;
        const result = Math.ceil(Math.random() * sides);

        await interaction.reply({
            content: `🎲 Du würfelst einen **W${sides}** und erhältst: **${result}**`,
        });
    },
};
