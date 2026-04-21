// PFAD: /core/installer/src/commands/music-shuffle.js

const { SlashCommandBuilder } = require('discord.js');
const { getQueue, isCommandEnabled } = require('../../services/music-service');

module.exports = {
    key: 'music-shuffle',

    data: new SlashCommandBuilder()
        .setName('shuffle')
        .setDescription('Mischt die Queue zufällig.'),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'shuffle')) {
            await interaction.reply({ content: 'Der `/shuffle` Befehl ist deaktiviert.', ephemeral: true });
            return;
        }

        const queue = getQueue(botId, interaction.guildId);
        if (!queue || queue.tracks.length < 2) {
            await interaction.reply({ content: '❌ Nicht genug Songs in der Queue zum Mischen.', ephemeral: true });
            return;
        }

        queue.shuffle();
        await interaction.reply(`🔀 Queue mit **${queue.tracks.length}** Songs gemischt.`);
    },
};
