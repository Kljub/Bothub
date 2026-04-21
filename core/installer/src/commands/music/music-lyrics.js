// PFAD: /core/installer/src/commands/music-lyrics.js

const { SlashCommandBuilder, EmbedBuilder } = require('discord.js');
const { getQueue, isCommandEnabled } = require('../../services/music-service');

async function fetchLyrics(title) {
    try {
        // LrcLib API – free, no key needed
        const clean = title
            .replace(/\(.*?\)/g, '')
            .replace(/\[.*?\]/g, '')
            .replace(/official.*|lyric.*|hd|mv|music video/gi, '')
            .trim();

        const url = `https://lrclib.net/api/search?q=${encodeURIComponent(clean)}&limit=1`;
        const res = await fetch(url, { signal: AbortSignal.timeout(5000) });
        if (!res.ok) return null;

        const data = await res.json();
        if (!Array.isArray(data) || data.length === 0) return null;

        const entry = data[0];
        return entry.plainLyrics || entry.syncedLyrics || null;
    } catch (_) {
        return null;
    }
}

module.exports = {
    key: 'music-lyrics',

    data: new SlashCommandBuilder()
        .setName('lyrics')
        .setDescription('Zeigt den Songtext des aktuell spielenden Songs.')
        .addStringOption((o) =>
            o.setName('title')
                .setDescription('Anderen Song suchen (optional)')
        ),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'lyrics')) {
            await interaction.reply({ content: 'Der `/lyrics` Befehl ist deaktiviert.', ephemeral: true });
            return;
        }

        await interaction.deferReply();

        const customTitle = interaction.options.getString('title');
        let searchTitle   = customTitle;

        if (!searchTitle) {
            const queue = getQueue(botId, interaction.guildId);
            const track = queue ? queue.getCurrentTrack() : null;
            if (!track) {
                await interaction.editReply('❌ Kein Song läuft gerade. Nutze `/lyrics title:...` für eine manuelle Suche.');
                return;
            }
            searchTitle = track.title;
        }

        const lyrics = await fetchLyrics(searchTitle);

        if (!lyrics) {
            await interaction.editReply(`❌ Kein Songtext gefunden für: **${searchTitle}**`);
            return;
        }

        // Discord embed limit: 4096 chars description
        const truncated = lyrics.length > 3800 ? lyrics.slice(0, 3800) + '\n…' : lyrics;

        const embed = new EmbedBuilder()
            .setColor('#6366f1')
            .setTitle(`📄 Songtext: ${searchTitle}`)
            .setDescription(truncated)
            .setFooter({ text: 'via LrcLib' });

        await interaction.editReply({ embeds: [embed] });
    },
};
