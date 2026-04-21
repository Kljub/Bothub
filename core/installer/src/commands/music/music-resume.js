// PFAD: /core/installer/src/commands/music-resume.js

const { SlashCommandBuilder } = require('discord.js');
const { getQueue, isCommandEnabled } = require('../../services/music-service');

module.exports = {
    key: 'music-resume',

    data: new SlashCommandBuilder()
        .setName('resume')
        .setDescription('Setzt die pausierte Wiedergabe fort.'),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'resume')) {
            await interaction.reply({ content: 'Der `/resume` Befehl ist deaktiviert.', ephemeral: true });
            return;
        }

        const queue = getQueue(botId, interaction.guildId);
        if (!queue || !queue.isPaused()) {
            await interaction.reply({ content: '❌ Kein pausierter Song gefunden.', ephemeral: true });
            return;
        }

        queue.resume();
        await interaction.reply('▶️ Fortgesetzt.');
    },
};
