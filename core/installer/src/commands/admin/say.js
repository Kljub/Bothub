// PFAD: /core/installer/src/commands/say.js

const { SlashCommandBuilder } = require('discord.js');

module.exports = {
    key: 'say',

    data: new SlashCommandBuilder()
        .setName('say')
        .setDescription('Lässt den Bot eine Nachricht senden.')
        .addStringOption(option =>
            option
                .setName('message')
                .setDescription('Die Nachricht die gesendet werden soll')
                .setRequired(true)
        ),

    async execute(interaction) {
        const message = interaction.options.getString('message', true);

        try {
            if (interaction.channel) {
                await interaction.channel.send(message);
            }

            await interaction.reply({
                content: 'Message sent.',
                ephemeral: true
            });
        } catch (error) {
            await interaction.reply({
                content: 'Failed to send message.',
                ephemeral: true
            });
        }
    }
};