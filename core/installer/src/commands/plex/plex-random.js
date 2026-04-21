const { SlashCommandBuilder, EmbedBuilder } = require('discord.js');
const {
    assertPlexCommandEnabled,
    getRandomAllowedItem,
    getPlexContext
} = require('../../services/plex-service');

module.exports = {
    key: 'plex-random',

    data: new SlashCommandBuilder()
        .setName('plex-random')
        .setDescription('Zieht zufällig einen Eintrag aus erlaubten Plex Libraries.')
        .addStringOption((option) =>
            option
                .setName('library')
                .setDescription('Optional: Library auswählen')
                .setRequired(false)
                .setAutocomplete(true)
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
        await assertPlexCommandEnabled(botId, 'plex-random');

        await interaction.deferReply();

        const libraryFilter = interaction.options.getString('library') || null;
        const item = await getRandomAllowedItem(botId, libraryFilter);

        if (!item) {
            const noResultMsg = libraryFilter
                ? `Kein zufälliger Plex Eintrag für Library **${libraryFilter}** gefunden.`
                : 'Kein zufälliger Plex Eintrag konnte geladen werden.';
            await interaction.editReply(noResultMsg);
            return;
        }

        const year    = item.year ? ` (${item.year})` : '';
        const typeStr = item.type
            ? item.type.charAt(0).toUpperCase() + item.type.slice(1)
            : null;

        const embed = new EmbedBuilder()
            .setTitle(`${item.title}${year}`)
            .setColor(0xE5A00D); // Plex orange

        if (item.summary) {
            const summary = item.summary.length > 300
                ? item.summary.slice(0, 297) + '…'
                : item.summary;
            embed.setDescription(summary);
        }

        if (item.thumbnailUrl) {
            embed.setThumbnail(item.thumbnailUrl);
        }

        const fields = [];
        if (typeStr)            fields.push({ name: 'Typ',     value: typeStr,              inline: true });
        if (item.library_title) fields.push({ name: 'Library', value: item.library_title,  inline: true });
        if (item.server_name)   fields.push({ name: 'Server',  value: item.server_name,    inline: true });
        if (fields.length > 0)  embed.addFields(fields);

        embed.setFooter({ text: 'Zufälliger Plex Treffer' });

        await interaction.editReply({ embeds: [embed] });
    }
};