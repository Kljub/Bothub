const { SlashCommandBuilder, EmbedBuilder } = require('discord.js');
const {
    assertPlexCommandEnabled,
    searchAllowedLibraries
} = require('../../services/plex-service');

const PLEX_COLOR = 0xE5A00D;

const TYPE_LABELS = {
    movie: 'Film',
    show: 'Serie',
    artist: 'Künstler',
    album: 'Album',
    track: 'Titel',
    photo: 'Foto'
};

module.exports = {
    key: 'plex-search',

    data: new SlashCommandBuilder()
        .setName('plex-search')
        .setDescription('Sucht in den erlaubten Plex Libraries.')
        .addStringOption((option) =>
            option
                .setName('name')
                .setDescription('Titel oder Suchbegriff')
                .setRequired(true)
        ),

    async execute(interaction, botId) {
        await assertPlexCommandEnabled(botId, 'plex-search');

        const query = interaction.options.getString('name', true).trim();

        await interaction.deferReply();

        const results = await searchAllowedLibraries(botId, query, 5);

        if (!Array.isArray(results) || results.length === 0) {
            await interaction.editReply(`Keine Treffer für **${query}** in den erlaubten Plex Libraries gefunden.`);
            return;
        }

        const embeds = results.map((item) => {
            const title = item.year ? `${item.title} (${item.year})` : item.title;
            const typeLabel = TYPE_LABELS[String(item.type || '').toLowerCase()] || item.type || '';
            const footer = [item.library_title, typeLabel].filter(Boolean).join(' · ');

            const summary = String(item.summary || '').trim();
            const description = summary.length > 300
                ? summary.slice(0, 297) + '…'
                : summary || null;

            const embed = new EmbedBuilder()
                .setColor(PLEX_COLOR)
                .setAuthor({ name: 'Plex' })
                .setTitle(title)
                .setFooter({ text: footer });

            if (description) {
                embed.setDescription(description);
            }

            if (item.thumbnailUrl) {
                embed.setThumbnail(item.thumbnailUrl);
            }

            return embed;
        });

        await interaction.editReply({ embeds });
    }
};
