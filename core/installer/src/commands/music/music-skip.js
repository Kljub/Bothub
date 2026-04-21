// PFAD: /core/installer/src/commands/music-skip.js

const { SlashCommandBuilder } = require('discord.js');
const { getQueue, isCommandEnabled } = require('../../services/music-service');

module.exports = {
    key: 'music-skip',

    data: new SlashCommandBuilder()
        .setName('skip')
        .setDescription('Überspringt den aktuellen Song.'),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'skip')) {
            await interaction.reply({ content: 'Der `/skip` Befehl ist deaktiviert.', ephemeral: true });
            return;
        }

        const queue = getQueue(botId, interaction.guildId);
        if (!queue || queue.tracks.length === 0) {
            await interaction.reply({ content: '❌ Es läuft gerade nichts.', ephemeral: true });
            return;
        }

        const track = queue.getCurrentTrack();
        queue.skip();

        await interaction.reply(`⏭️ Übersprungen: **${track ? track.title : 'Unbekannt'}**`);
    },
};
