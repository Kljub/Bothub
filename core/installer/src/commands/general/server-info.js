// PFAD: /core/installer/src/commands/server-info.js
const { SlashCommandBuilder } = require('discord.js');

module.exports = {
    key: 'server-info',

    data: new SlashCommandBuilder()
        .setName('server-info')
        .setDescription('Shows information about the server'),

    async execute(interaction) {

        const guild = interaction.guild;

        await interaction.reply({
            content:
`Server: ${guild.name}
Members: ${guild.memberCount}
Created: ${guild.createdAt.toDateString()}`,
            ephemeral: true
        });

    }
};
