// PFAD: /core/installer/src/commands/music-pause.js

const { SlashCommandBuilder } = require('discord.js');
const { getQueue, isCommandEnabled } = require('../../services/music-service');

module.exports = {
    key: 'music-pause',

    data: new SlashCommandBuilder()
        .setName('pause')
        .setDescription('Pausiert die Wiedergabe.'),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'pause')) {
            await interaction.reply({ content: 'Der `/pause` Befehl ist deaktiviert.', ephemeral: true });
            return;
        }

        const queue = getQueue(botId, interaction.guildId);
        if (!queue || !queue.isPlaying()) {
            await interaction.reply({ content: '❌ Es läuft gerade nichts.', ephemeral: true });
            return;
        }

        queue.pause();
        await interaction.reply('⏸️ Pausiert.');
    },
};
