const { SlashCommandBuilder } = require('discord.js');
const {
    assertPlexCommandEnabled,
    getPlexStats
} = require('../../services/plex-service');

module.exports = {
    key: 'plex-stats',

    data: new SlashCommandBuilder()
        .setName('plex-stats')
        .setDescription('Zeigt Zahlen zu den erlaubten Plex Libraries.'),

    async execute(interaction, botId) {
        await assertPlexCommandEnabled(botId, 'plex-stats');

        await interaction.deferReply();

        const stats = await getPlexStats(botId);
        const typeEntries = Object.entries(stats.totalsByType || {});
        const libraryEntries = Array.isArray(stats.totalsByLibrary) ? stats.totalsByLibrary : [];

        if (typeEntries.length === 0 && libraryEntries.length === 0) {
            await interaction.editReply('Für diesen Bot konnten keine Plex Statistiken geladen werden.');
            return;
        }

        const typeLines = typeEntries
            .sort((a, b) => String(a[0]).localeCompare(String(b[0])))
            .map(([type, total]) => `- **${type}**: ${total}`);

        const libraryLines = libraryEntries
            .sort((a, b) => String(a.library_title).localeCompare(String(b.library_title)))
            .map((entry) => `- **${entry.library_title}** (${entry.library_type}): ${entry.total}`);

        const parts = ['**Plex Stats**'];

        if (typeLines.length > 0) {
            parts.push('', '**Nach Typ**', ...typeLines);
        }

        if (libraryLines.length > 0) {
            parts.push('', '**Nach Library**', ...libraryLines);
        }

        await interaction.editReply(parts.join('\n'));
    }
};