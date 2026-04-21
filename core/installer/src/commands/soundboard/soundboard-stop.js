const { SlashCommandBuilder } = require('discord.js');
const { dbQuery } = require('../../db');
const { stopSound } = require('../../services/soundboard-service');

async function isCommandEnabled(botId, key) {
    const rows = await dbQuery(
        'SELECT is_enabled FROM commands WHERE bot_id = ? AND command_key = ? LIMIT 1',
        [Number(botId), key]
    );
    if (!rows || rows.length === 0) return true;
    return Number(rows[0].is_enabled) === 1;
}

module.exports = {
    key: 'soundboard-stop',

    data: new SlashCommandBuilder()
        .setName('soundboard-stop')
        .setDescription('Stoppt die aktuelle Soundboard-Wiedergabe.'),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'soundboard-stop')) {
            await interaction.reply({ content: 'Der `/soundboard-stop` Befehl ist deaktiviert.', ephemeral: true });
            return;
        }

        const result = stopSound(interaction.guildId);
        await interaction.reply({
            content:   result.ok ? '⏹ Wiedergabe gestoppt.' : `❌ ${result.message}`,
            ephemeral: !result.ok,
        });
    },
};
