// PFAD: /core/installer/src/commands/music-play.js

const { SlashCommandBuilder, EmbedBuilder } = require('discord.js');
const {
    getQueue,
    createQueue,
    loadMusicSettings,
    isCommandEnabled,
    resolveQuery,
    ensureVoiceConnection,
} = require('../../services/music-service');

module.exports = {
    key: 'music-play',

    data: new SlashCommandBuilder()
        .setName('play')
        .setDescription('Spielt einen Song oder eine Playlist ab.')
        .addStringOption((o) =>
            o.setName('query')
                .setDescription('Suche oder URL (YouTube, Spotify, SoundCloud, Deezer)')
                .setRequired(true)
        ),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'play')) {
            await interaction.reply({ content: 'Der `/play` Befehl ist deaktiviert.', ephemeral: true });
            return;
        }

        const settings = await loadMusicSettings(botId);

        // Channel restriction
        if (settings && settings.music_channel_id) {
            if (interaction.channelId !== String(settings.music_channel_id)) {
                await interaction.reply({
                    content: `Musikbefehle sind nur in <#${settings.music_channel_id}> erlaubt.`,
                    ephemeral: true,
                });
                return;
            }
        }

        // DJ Role check
        if (settings && settings.dj_role_id) {
            const hasDj = interaction.member?.roles?.cache?.has(String(settings.dj_role_id));
            if (!hasDj) {
                await interaction.reply({
                    content: 'Du benötigst die DJ-Rolle um Musikbefehle zu nutzen.',
                    ephemeral: true,
                });
                return;
            }
        }

        await interaction.deferReply();

        const query = interaction.options.getString('query', true).trim();
        const guildId = interaction.guildId;

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

        let tracks;
        try {
            tracks = await resolveQuery(query, botId);
        } catch (err) {
            await interaction.editReply(`❌ Fehler beim Laden: ${err.message}`);
            return;
        }

        if (!tracks || tracks.length === 0) {
            await interaction.editReply(`❌ Keine Ergebnisse für: **${query}**`);
            return;
        }

        const user = interaction.user.tag;
        tracks.forEach((t) => { t.requestedBy = user; });

        if (tracks.length === 1) {
            const track = tracks[0];
            const wasEmpty = queue.tracks.length === 0;
            await queue.addTrack(track);

            const embed = new EmbedBuilder()
                .setColor('#6366f1')
                .setTitle(wasEmpty ? '🎵 Wird abgespielt' : '✅ Zur Queue hinzugefügt')
                .setDescription(`[${track.title}](${track.url})`)
                .addFields({
                    name: 'Position',
                    value: wasEmpty ? 'Jetzt' : String(queue.tracks.length),
                    inline: true,
                })
                .setFooter({ text: `Angefragt von ${user}` });

            if (track.thumbnail) embed.setThumbnail(track.thumbnail);
            await interaction.editReply({ embeds: [embed] });
        } else {
            await queue.addTracks(tracks);
            await interaction.editReply({
                embeds: [
                    new EmbedBuilder()
                        .setColor('#6366f1')
                        .setTitle('📋 Playlist zur Queue hinzugefügt')
                        .setDescription(`**${tracks.length}** Tracks hinzugefügt`)
                        .setFooter({ text: `Angefragt von ${user}` }),
                ],
            });
        }
    },
};
