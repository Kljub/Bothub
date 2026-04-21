const { SlashCommandBuilder } = require('discord.js');
const { getQueue, isCommandEnabled } = require('../../services/music-service');

module.exports = {
    key: 'music-queue-remove',

    data: new SlashCommandBuilder()
        .setName('queue-remove')
        .setDescription('Entfernt einen Song aus der Queue.')
        .addIntegerOption((o) =>
            o.setName('position')
                .setDescription('Position des Songs in der Queue (Nummer aus /queue)')
                .setMinValue(1)
                .setRequired(true)
        ),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'queue-remove')) {
            await interaction.reply({ content: 'Der `/queue-remove` Befehl ist deaktiviert.', ephemeral: true });
            return;
        }

        const queue = getQueue(botId, interaction.guildId);

        if (!queue || queue.tracks.length === 0) {
            await interaction.reply({ content: '📭 Die Queue ist leer.', ephemeral: true });
            return;
        }

        const position = interaction.options.getInteger('position', true);
        const idx      = position - 1;

        if (idx < 0 || idx >= queue.tracks.length) {
            await interaction.reply({
                content: `❌ Position **${position}** ist ungültig. Die Queue hat ${queue.tracks.length} Einträge.`,
                ephemeral: true,
            });
            return;
        }

        if (idx === queue.currentIdx) {
            await interaction.reply({
                content: `❌ Der aktuell spielende Song kann nicht entfernt werden. Nutze \`/skip\` stattdessen.`,
                ephemeral: true,
            });
            return;
        }

        const removed = queue.tracks[idx];
        const ok      = queue.removeTrack(position);

        if (!ok) {
            await interaction.reply({ content: '❌ Song konnte nicht entfernt werden.', ephemeral: true });
            return;
        }

        await interaction.reply({
            content: `🗑️ **${removed.title}** (Position ${position}) wurde aus der Queue entfernt.`,
        });
    },
};
