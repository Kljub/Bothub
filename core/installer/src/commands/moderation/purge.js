// PFAD: /core/installer/src/commands/purge.js

const { SlashCommandBuilder } = require('discord.js');

module.exports = {
    key: 'purge',

    data: new SlashCommandBuilder()
        .setName('purge')
        .setDescription('Löscht Nachrichten vor diesem Command.')
        .addIntegerOption((option) =>
            option
                .setName('size')
                .setDescription('Wie viele Nachrichten gelöscht werden sollen')
                .setRequired(false)
                .setMinValue(1)
                .setMaxValue(100)
        ),

    async execute(interaction) {
        if (!interaction.inGuild() || !interaction.channel) {
            await interaction.reply({
                content: 'Dieser Command kann nur in einem Server-Textkanal genutzt werden.',
                ephemeral: true
            });
            return;
        }

        const rawSize = interaction.options.getInteger('size');
        const deleteCount = Number.isInteger(rawSize) && rawSize > 0 ? rawSize : 1;

        try {
            const deleted = await interaction.channel.bulkDelete(deleteCount, true);
            const deletedCount = deleted ? deleted.size : 0;

            await interaction.reply({
                content: deletedCount === 1
                    ? '✅ 1 Nachricht gelöscht.'
                    : `✅ ${deletedCount} Nachrichten gelöscht.`,
                ephemeral: true
            });
        } catch (error) {
            await interaction.reply({
                content: 'Es konnten keine Nachrichten gelöscht werden.',
                ephemeral: true
            });
        }
    }
};