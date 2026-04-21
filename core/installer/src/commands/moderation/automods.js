// PFAD: /core/installer/src/commands/automods.js
const { SlashCommandBuilder } = require('discord.js');

module.exports = {
    key: 'automods',

    data: new SlashCommandBuilder()
        .setName('automods')
        .setDescription('Shows automod status'),

    async execute(interaction) {
        await interaction.reply({
            content: 'Automods status page placeholder.',
            ephemeral: true
        });
    }
};
