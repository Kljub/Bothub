// PFAD: /core/installer/src/commands/level.js
const { SlashCommandBuilder } = require('discord.js');

module.exports = {
    key: 'level',

    data: new SlashCommandBuilder()
        .setName('level')
        .setDescription('Shows your level'),

    async execute(interaction) {
        await interaction.reply({
            content: 'Your level is 1. (placeholder)',
            ephemeral: true
        });
    }
};
