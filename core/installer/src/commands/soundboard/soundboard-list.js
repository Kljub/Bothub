const { SlashCommandBuilder, EmbedBuilder } = require('discord.js');
const { dbQuery } = require('../../db');

async function isCommandEnabled(botId, key) {
    const rows = await dbQuery(
        'SELECT is_enabled FROM commands WHERE bot_id = ? AND command_key = ? LIMIT 1',
        [Number(botId), key]
    );
    if (!rows || rows.length === 0) return true;
    return Number(rows[0].is_enabled) === 1;
}

module.exports = {
    key: 'soundboard-list',

    data: new SlashCommandBuilder()
        .setName('soundboard-list')
        .setDescription('Zeigt alle verfügbaren Sounds des Soundboards.'),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'soundboard-list')) {
            await interaction.reply({ content: 'Der `/soundboard-list` Befehl ist deaktiviert.', ephemeral: true });
            return;
        }

        const rows = await dbQuery(
            'SELECT name, emoji, volume FROM bot_soundboard_sounds WHERE bot_id = ? ORDER BY name ASC',
            [Number(botId)]
        );

        if (!rows || rows.length === 0) {
            await interaction.reply({ content: '📭 Noch keine Sounds hochgeladen.', ephemeral: true });
            return;
        }

        const lines = rows.map((r, i) =>
            `${i + 1}. ${String(r.emoji || '🔊')} **${r.name}** — Vol: ${r.volume}%`
        );

        // Split into pages of max 4000 chars each
        const pages  = [];
        let   chunk  = '';
        for (const line of lines) {
            if ((chunk + '\n' + line).length > 3900) {
                pages.push(chunk);
                chunk = line;
            } else {
                chunk = chunk ? chunk + '\n' + line : line;
            }
        }
        if (chunk) pages.push(chunk);

        const embeds = pages.slice(0, 10).map((desc, i) =>
            new EmbedBuilder()
                .setColor(0x8B5CF6)
                .setTitle(i === 0 ? '🔊 Soundboard' : null)
                .setDescription(desc)
                .setFooter(i === pages.length - 1
                    ? { text: `${rows.length} Sound${rows.length !== 1 ? 's' : ''} · Benutze /soundboard-play <name>` }
                    : null
                )
        );

        await interaction.reply({ embeds });
    },
};
