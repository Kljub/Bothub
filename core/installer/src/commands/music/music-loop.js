// PFAD: /core/installer/src/commands/music-loop.js

const { SlashCommandBuilder } = require('discord.js');
const { getQueue, isCommandEnabled } = require('../../services/music-service');

module.exports = {
    key: 'music-loop',

    data: new SlashCommandBuilder()
        .setName('loop')
        .setDescription('Stellt den Loop-Modus ein.')
        .addStringOption((o) =>
            o.setName('mode')
                .setDescription('Loop-Modus')
                .setRequired(true)
                .addChoices(
                    { name: 'Aus',   value: 'off' },
                    { name: 'Song',  value: 'track' },
                    { name: 'Queue', value: 'queue' },
                )
        ),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'loop')) {
            await interaction.reply({ content: 'Der `/loop` Befehl ist deaktiviert.', ephemeral: true });
            return;
        }

        const queue = getQueue(botId, interaction.guildId);
        if (!queue) {
            await interaction.reply({ content: '❌ Es läuft gerade nichts.', ephemeral: true });
            return;
        }

        const mode = interaction.options.getString('mode', true);
        queue.setLoop(mode);

        const icons = { off: '▶️ Aus', track: '🔂 Song', queue: '🔁 Queue' };
        await interaction.reply(`Loop: **${icons[mode] || mode}**`);
    },
};
