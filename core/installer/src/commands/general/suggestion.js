// PFAD: /core/installer/src/commands/suggestion.js
const { SlashCommandBuilder } = require('discord.js');

module.exports = {
    key: 'suggestion',

    data: new SlashCommandBuilder()
        .setName('suggestion')
        .setDescription('Send a suggestion')
        .addStringOption(opt =>
            opt.setName('text')
                .setDescription('Suggestion text')
                .setRequired(true)
        ),

    async execute(interaction) {
        const text = interaction.options.getString('text', true);

        await interaction.reply({
            content: `💡 Suggestion received: ${text}`,
            ephemeral: true
        });
    }
};
