// PFAD: /core/installer/src/commands/user-info.js

const { SlashCommandBuilder } = require('discord.js');

module.exports = {
    key: 'user-info',

    data: new SlashCommandBuilder()
        .setName('user-info')
        .setDescription('Shows information about a user')
        .addUserOption(option =>
            option
                .setName('user')
                .setDescription('User')
                .setRequired(false)
        ),

    async execute(interaction) {

        const user = interaction.options.getUser('user') || interaction.user;

        const member = interaction.guild
            ? await interaction.guild.members.fetch(user.id).catch(() => null)
            : null;

        let message =
`User: ${user.tag}
ID: ${user.id}
Account Created: ${user.createdAt.toDateString()}`;

        if (member) {
            message += `\nJoined Server: ${member.joinedAt ? member.joinedAt.toDateString() : 'Unknown'}`;
        }

        await interaction.reply({
            content: message,
            ephemeral: true
        });

    }
};