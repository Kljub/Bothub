// PFAD: /core/installer/src/commands/music-queue.js

const { SlashCommandBuilder, EmbedBuilder } = require('discord.js');
const { getQueue, isCommandEnabled } = require('../../services/music-service');

function fmtDuration(sec) {
    if (!sec || sec <= 0) return '?:??';
    const m = Math.floor(sec / 60);
    const s = Math.floor(sec % 60);
    return `${m}:${String(s).padStart(2, '0')}`;
}

module.exports = {
    key: 'music-queue',

    data: new SlashCommandBuilder()
        .setName('queue')
        .setDescription('Zeigt die aktuelle Musik-Queue.')
        .addIntegerOption((o) =>
            o.setName('page')
                .setDescription('Seite der Queue (Standard: 1)')
                .setMinValue(1)
        ),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'queue')) {
            await interaction.reply({ content: 'Der `/queue` Befehl ist deaktiviert.', ephemeral: true });
            return;
        }

        const queue = getQueue(botId, interaction.guildId);
        if (!queue || queue.tracks.length === 0) {
            await interaction.reply({ content: '📭 Die Queue ist leer.', ephemeral: true });
            return;
        }

        const page     = Math.max(1, interaction.options.getInteger('page') || 1);
        const perPage  = 10;
        const total    = queue.tracks.length;
        const pages    = Math.ceil(total / perPage);
        const safePage = Math.min(page, pages);
        const start    = (safePage - 1) * perPage;
        const slice    = queue.tracks.slice(start, start + perPage);

        const loopIcons = { off: '▶️', track: '🔂', queue: '🔁' };
        const loopText  = loopIcons[queue.loopMode] || '▶️';

        const lines = slice.map((t, i) => {
            const idx      = start + i;
            const prefix   = idx === queue.currentIdx ? '**▶ ' : `${idx + 1}. `;
            const suffix   = idx === queue.currentIdx ? '** ← jetzt' : '';
            const dur      = fmtDuration(t.duration);
            return `${prefix}[${t.title}](${t.url}) \`${dur}\`${suffix}`;
        });

        const embed = new EmbedBuilder()
            .setColor('#6366f1')
            .setTitle(`🎶 Queue — ${total} Song${total !== 1 ? 's' : ''}`)
            .setDescription(lines.join('\n') || 'Leer')
            .setFooter({ text: `Seite ${safePage}/${pages} • Loop: ${queue.loopMode} ${loopText} • Lautstärke: ${queue.volume}%` });

        await interaction.reply({ embeds: [embed] });
    },
};
