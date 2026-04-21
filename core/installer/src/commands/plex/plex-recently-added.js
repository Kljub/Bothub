const { SlashCommandBuilder, EmbedBuilder } = require('discord.js');
const {
    assertPlexCommandEnabled,
    getRecentlyAdded,
    getPlexContext,
} = require('../../services/plex-service');

const PLEX_COLOR = 0xE5A00D;

const TYPE_LABELS = {
    movie:  'Film',
    show:   'Serie',
    season: 'Staffel',
    episode:'Episode',
    artist: 'Künstler',
    album:  'Album',
    track:  'Titel',
    photo:  'Foto',
};

module.exports = {
    key: 'plex-recently-added',

    data: new SlashCommandBuilder()
        .setName('plex-recently-added')
        .setDescription('Zeigt die zuletzt zu Plex hinzugefügten Inhalte.')
        .addStringOption((o) =>
            o.setName('library')
                .setDescription('Optional: Nur eine bestimmte Library durchsuchen')
                .setRequired(false)
                .setAutocomplete(true)
        )
        .addIntegerOption((o) =>
            o.setName('anzahl')
                .setDescription('Wie viele Einträge anzeigen? (1–10, Standard: 5)')
                .setMinValue(1)
                .setMaxValue(10)
                .setRequired(false)
        ),

    async autocomplete(interaction, botId) {
        try {
            const focused = interaction.options.getFocused();
            const context = await getPlexContext(botId);
            const choices = context.libraries.map((lib) => ({
                name: lib.server_name !== lib.library_title
                    ? `${lib.library_title} (${lib.server_name})`
                    : lib.library_title,
                value: lib.library_title,
            }));
            const filtered = focused
                ? choices.filter((c) => c.name.toLowerCase().includes(String(focused).toLowerCase()))
                : choices;
            await interaction.respond(filtered.slice(0, 25));
        } catch (_) {
            await interaction.respond([]);
        }
    },

    async execute(interaction, botId) {
        await assertPlexCommandEnabled(botId, 'plex-recently-added');

        await interaction.deferReply();

        const libraryFilter = interaction.options.getString('library') || null;
        const limit         = interaction.options.getInteger('anzahl') || 5;

        const items = await getRecentlyAdded(botId, libraryFilter, limit);

        if (!items || items.length === 0) {
            const msg = libraryFilter
                ? `Keine kürzlich hinzugefügten Inhalte in Library **${libraryFilter}** gefunden.`
                : 'Keine kürzlich hinzugefügten Plex-Inhalte gefunden.';
            await interaction.editReply(msg);
            return;
        }

        const embeds = items.slice(0, 10).map((item, i) => {
            const typeLabel = TYPE_LABELS[String(item.type || '').toLowerCase()] || item.type || '';
            const title     = item.title + (item.year ? ` (${item.year})` : '');
            const footer    = [item.library_title, typeLabel].filter(Boolean).join(' · ');

            const summary = String(item.summary || '').trim();
            const description = summary.length > 250
                ? summary.slice(0, 247) + '…'
                : summary || null;

            const embed = new EmbedBuilder()
                .setColor(PLEX_COLOR)
                .setAuthor({ name: `Plex · Zuletzt hinzugefügt #${i + 1}` })
                .setTitle(title)
                .setFooter({ text: footer });

            if (description) embed.setDescription(description);
            if (item.thumbnailUrl) embed.setThumbnail(item.thumbnailUrl);

            return embed;
        });

        await interaction.editReply({ embeds });
    },
};
