// PFAD: /core/installer/src/commands/music-nowplaying.js

const { SlashCommandBuilder, EmbedBuilder } = require('discord.js');
const { getQueue, isCommandEnabled } = require('../../services/music-service');

function fmtDuration(sec) {
    if (!sec || sec <= 0) return '?:??';
    const m = Math.floor(sec / 60);
    const s = Math.floor(sec % 60);
    return `${m}:${String(s).padStart(2, '0')}`;
}

module.exports = {
    key: 'music-nowplaying',

    data: new SlashCommandBuilder()
        .setName('nowplaying')
        .setDescription('Zeigt den aktuell spielenden Song.'),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'nowplaying')) {
            await interaction.reply({ content: 'Der `/nowplaying` Befehl ist deaktiviert.', ephemeral: true });
            return;
        }

        const queue = getQueue(botId, interaction.guildId);
        const track = queue ? queue.getCurrentTrack() : null;

        if (!track) {
            await interaction.reply({ content: '❌ Es läuft gerade nichts.', ephemeral: true });
            return;
        }

        const statusIcon = queue.isPaused() ? '⏸️' : '▶️';
        const loopText   = { off: 'Aus', track: 'Song', queue: 'Queue' }[queue.loopMode] || 'Aus';

        const embed = new EmbedBuilder()
            .setColor('#6366f1')
            .setTitle(`${statusIcon} Jetzt spielend`)
            .setDescription(`[${track.title}](${track.url})`)
            .addFields(
                { name: '🎚️ Lautstärke', value: `${queue.volume}%`,       inline: true },
                { name: '🔁 Loop',        value: loopText,                  inline: true },
                { name: '📋 Queue',       value: `${queue.tracks.length} Songs`, inline: true },
            )
            .setFooter({ text: `Angefragt von ${track.requestedBy}` })
            .setTimestamp();

        if (track.thumbnail) embed.setThumbnail(track.thumbnail);

        await interaction.reply({ embeds: [embed] });
    },
};
