// PFAD: /core/installer/src/commands/avatar.js

const { SlashCommandBuilder } = require('discord.js');

module.exports = {
    key: 'avatar',

    data: new SlashCommandBuilder()
        .setName('avatar')
        .setDescription('Shows the avatar of a user')
        .addUserOption(option =>
            option
                .setName('user')
                .setDescription('User')
                .setRequired(false)
        ),

    async execute(interaction) {

        const user = interaction.options.getUser('user') || interaction.user;

        const avatar = user.displayAvatarURL({
            size: 1024,
            dynamic: true
        });

        await interaction.reply({
            content: avatar,
            ephemeral: false
        });

    }
};