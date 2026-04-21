// PFAD: /core/installer/src/commands/music-volume.js

const { SlashCommandBuilder } = require('discord.js');
const { getQueue, isCommandEnabled } = require('../../services/music-service');

module.exports = {
    key: 'music-volume',

    data: new SlashCommandBuilder()
        .setName('volume')
        .setDescription('Ändert die Lautstärke (0–100).')
        .addIntegerOption((o) =>
            o.setName('level')
                .setDescription('Lautstärke in Prozent (0–100)')
                .setMinValue(0)
                .setMaxValue(100)
                .setRequired(true)
        ),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'volume')) {
            await interaction.reply({ content: 'Der `/volume` Befehl ist deaktiviert.', ephemeral: true });
            return;
        }

        const queue = getQueue(botId, interaction.guildId);
        if (!queue) {
            await interaction.reply({ content: '❌ Es läuft gerade nichts.', ephemeral: true });
            return;
        }

        const level = interaction.options.getInteger('level', true);
        queue.setVolume(level);

        const bar = '█'.repeat(Math.round(level / 10)) + '░'.repeat(10 - Math.round(level / 10));
        await interaction.reply(`🔊 Lautstärke: \`${bar}\` **${level}%**`);
    },
};
