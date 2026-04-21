const { SlashCommandBuilder, EmbedBuilder } = require('discord.js');
const {
    assertPlexCommandEnabled,
    getOnDeck,
} = require('../../services/plex-service');

const PLEX_COLOR = 0xE5A00D;

const TYPE_LABELS = {
    movie:   'Film',
    show:    'Serie',
    episode: 'Episode',
};

module.exports = {
    key: 'plex-on-deck',

    data: new SlashCommandBuilder()
        .setName('plex-on-deck')
        .setDescription('Zeigt deine Plex "On Deck" Liste — Inhalte die du weiterschauen kannst.')
        .addIntegerOption((o) =>
            o.setName('anzahl')
                .setDescription('Wie viele Einträge anzeigen? (1–10, Standard: 5)')
                .setMinValue(1)
                .setMaxValue(10)
                .setRequired(false)
        ),

    async execute(interaction, botId) {
        await assertPlexCommandEnabled(botId, 'plex-on-deck');

        await interaction.deferReply();

        const limit = interaction.options.getInteger('anzahl') || 5;
        const items = await getOnDeck(botId, limit);

        if (!items || items.length === 0) {
            await interaction.editReply(
                'Keine On-Deck-Einträge gefunden. Starte etwas auf Plex und schau etwas an – ' +
                'dann erscheinen die Einträge hier.'
            );
            return;
        }

        const embeds = items.slice(0, 10).map((item, i) => {
            const typeLabel = TYPE_LABELS[String(item.type || '').toLowerCase()] || item.type || '';
            const title     = item.title + (item.year ? ` (${item.year})` : '');
            const footer    = [item.server_name, typeLabel].filter(Boolean).join(' · ');

            const summary = String(item.summary || '').trim();
            const description = summary.length > 250
                ? summary.slice(0, 247) + '…'
                : summary || null;

            const embed = new EmbedBuilder()
                .setColor(PLEX_COLOR)
                .setAuthor({ name: `Plex · On Deck #${i + 1}` })
                .setTitle(title)
                .setFooter({ text: footer });

            if (description) embed.setDescription(description);
            if (item.thumbnailUrl) embed.setThumbnail(item.thumbnailUrl);

            return embed;
        });

        await interaction.editReply({ embeds });
    },
};
