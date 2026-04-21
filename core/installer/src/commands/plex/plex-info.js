const { SlashCommandBuilder } = require('discord.js');
const {
    assertPlexCommandEnabled,
    getPlexInfo
} = require('../../services/plex-service');

module.exports = {
    key: 'plex-info',

    data: new SlashCommandBuilder()
        .setName('plex-info')
        .setDescription('Zeigt Infos über den verbundenen Plex Server.'),

    async execute(interaction, botId) {
        await assertPlexCommandEnabled(botId, 'plex-info');

        await interaction.deferReply();

        const info = await getPlexInfo(botId);

        if (!info || !Array.isArray(info.servers) || info.servers.length === 0) {
            await interaction.editReply('Für diesen Bot sind keine Plex Server mit erlaubten Libraries verfügbar.');
            return;
        }

        const lines = info.servers.map((server, index) => {
            const platform = [server.platform, server.platform_version].filter(Boolean).join(' ');
            const version = server.product_version ? ` v${server.product_version}` : '';
            const online = server.presence ? 'Online' : 'Offline';

            return [
                `${index + 1}. **${server.server_name}** • ${online}`,
                `   Produkt: ${server.product}${version}`,
                `   Plattform: ${platform || '—'}`,
                `   Device: ${server.device || '—'}`,
                `   Freigegebene Libraries: ${server.libraries}`
            ].join('\n');
        });

        const content = [
            '**Plex Info**',
            `Gesamt freigegebene Libraries: **${info.allowedLibraries}**`,
            '',
            lines.join('\n\n')
        ].join('\n');

        await interaction.editReply(content);
    }
};