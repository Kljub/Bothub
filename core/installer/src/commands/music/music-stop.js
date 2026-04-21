// PFAD: /core/installer/src/commands/music-stop.js

const { SlashCommandBuilder } = require('discord.js');
const { getQueue, deleteQueue, isCommandEnabled } = require('../../services/music-service');

module.exports = {
    key: 'music-stop',

    data: new SlashCommandBuilder()
        .setName('stop')
        .setDescription('Stoppt die Musik, leert die Queue und trennt den Bot vom Voice Channel.'),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'stop')) {
            await interaction.reply({ content: 'Der `/stop` Befehl ist deaktiviert.', ephemeral: true });
            return;
        }

        const queue = getQueue(botId, interaction.guildId);
        if (!queue) {
            await interaction.reply({ content: '❌ Es läuft gerade nichts.', ephemeral: true });
            return;
        }

        deleteQueue(botId, interaction.guildId);
        await interaction.reply('⏹️ Musik gestoppt und Queue geleert.');
    },
};
