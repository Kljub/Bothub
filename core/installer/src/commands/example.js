// PFAD: /core/installer/src/commands/example.js

const { SlashCommandBuilder } = require('discord.js');

module.exports = {
    data: new SlashCommandBuilder()
        .setName('example')
        .setDescription('Example command'),

    async execute(interaction) {
        await interaction.reply({
            content: 'Example command executed',
            ephemeral: true
        });
    }
};