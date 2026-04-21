const { SlashCommandBuilder, EmbedBuilder } = require('discord.js');
const {
    assertPlexCommandEnabled,
    searchPlexForPlay,
} = require('../../services/plex-service');
const {
    getQueue,
    createQueue,
    loadMusicSettings,
    ensureVoiceConnection,
} = require('../../services/music-service');

const PLEX_COLOR = 0xE5A00D;

module.exports = {
    key: 'plex-play',

    data: new SlashCommandBuilder()
        .setName('plex-play')
        .setDescription('Sucht einen Song in Plex und spielt ihn im Voice-Channel ab.')
        .addStringOption((o) =>
            o.setName('query')
                .setDescription('Titel, Künstler oder Album')
                .setRequired(true)
        ),

    async execute(interaction, botId) {
        await assertPlexCommandEnabled(botId, 'plex-play');

        const query = interaction.options.getString('query', true).trim();

        await interaction.deferReply();

        // Search Plex music libraries for the query
        const plexResults = await searchPlexForPlay(botId, query, 1);

        if (!plexResults || plexResults.length === 0) {
            await interaction.editReply(
                `❌ Keine Plex-Musiktreffer für **${query}** gefunden. ` +
                `Stelle sicher, dass eine Musik-Library (Künstler/Album) freigegeben ist.`
            );
            return;
        }

        const pr = plexResults[0];
        const guildId = interaction.guildId;

        const settings = await loadMusicSettings(botId);

        // Channel restriction (shared with music module)
        if (settings && settings.music_channel_id) {
            if (interaction.channelId !== String(settings.music_channel_id)) {
                await interaction.editReply({
                    content: `Musikbefehle sind nur in <#${settings.music_channel_id}> erlaubt.`,
                });
                return;
            }
        }

        // DJ Role check (shared with music module)
        if (settings && settings.dj_role_id) {
            const hasDj = interaction.member?.roles?.cache?.has(String(settings.dj_role_id));
            if (!hasDj) {
                await interaction.editReply({
                    content: 'Du benötigst die DJ-Rolle um Musikbefehle zu nutzen.',
                });
                return;
            }
        }

        let queue = getQueue(botId, guildId);
        if (!queue) {
            queue = createQueue(botId, guildId);
        }

        queue.textChannel = interaction.channel;
        queue.volume      = settings ? Number(settings.default_volume || 50) : 50;

        try {
            await ensureVoiceConnection(interaction.member, interaction.client, queue);
        } catch (err) {
            await interaction.editReply(`❌ ${err.message}`);
            return;
        }

        const track = {
            title:       pr.title,
            url:         pr.streamUrl,
            duration:    0,
            thumbnail:   pr.thumbnailUrl || null,
            requestedBy: interaction.user.tag,
            source:      'plex',
        };

        const wasEmpty = queue.tracks.length === 0;
        await queue.addTrack(track);

        const embed = new EmbedBuilder()
            .setColor(PLEX_COLOR)
            .setAuthor({ name: 'Plex' })
            .setTitle(wasEmpty ? '🎵 Wird abgespielt' : '✅ Zur Queue hinzugefügt')
            .setDescription(pr.title + (pr.year ? ` (${pr.year})` : ''))
            .addFields({
                name: 'Position',
                value: wasEmpty ? 'Jetzt' : String(queue.tracks.length),
                inline: true,
            })
            .setFooter({ text: `Angefragt von ${interaction.user.tag}` });

        if (pr.thumbnailUrl) embed.setThumbnail(pr.thumbnailUrl);

        await interaction.editReply({ embeds: [embed] });
    },
};
