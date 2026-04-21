// PFAD: /core/installer/src/commands/kick.js

const { SlashCommandBuilder } = require('discord.js');

module.exports = {
    key: 'kick',

    data: new SlashCommandBuilder()
        .setName('kick')
        .setDescription('Kick a user')
        .addUserOption(option =>
            option
                .setName('user')
                .setDescription('User to kick')
                .setRequired(true)
        )
        .addStringOption(option =>
            option
                .setName('reason')
                .setDescription('Reason')
                .setRequired(false)
        ),

    async execute(interaction) {

        const user = interaction.options.getUser('user');
        const reason = interaction.options.getString('reason') || 'No reason';

        const member = await interaction.guild.members.fetch(user.id).catch(() => null);

        if (!member) {
            await interaction.reply({
                content: 'User not found.',
                ephemeral: true
            });
            return;
        }

        try {

            await member.kick(reason);

            await interaction.reply({
                content: `User ${user.tag} has been kicked.`,
                ephemeral: true
            });

        } catch {

            await interaction.reply({
                content: 'Failed to kick user.',
                ephemeral: true
            });

        }

    }
};