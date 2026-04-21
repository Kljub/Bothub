// PFAD: /core/installer/src/commands/leaderboard.js
const { SlashCommandBuilder } = require('discord.js');
const {
    isCommandEnabled, getEcoSettings, getLeaderboard, formatMoney,
} = require('../../services/economy-service');

module.exports = {
    key: 'leaderboard',

    data: new SlashCommandBuilder()
        .setName('leaderboard')
        .setDescription('Top Nutzer nach Gesamtvermögen.'),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'leaderboard')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        await interaction.deferReply();

        const guildId  = interaction.guildId;
        const settings = await getEcoSettings(botId, guildId);
        const rows     = await getLeaderboard(botId, guildId, 10);

        if (rows.length === 0) {
            return interaction.editReply('📊 Noch keine Economy-Daten vorhanden.');
        }

        const medals = ['🥇', '🥈', '🥉'];
        const lines  = ['**💰 Economy Leaderboard**', ''];

        for (let i = 0; i < rows.length; i++) {
            const row    = rows[i];
            const prefix = medals[i] || `**${i + 1}.**`;
            let   name   = `<@${row.user_id}>`;

            try {
                const member = await interaction.guild.members.fetch(String(row.user_id)).catch(() => null);
                if (member) name = member.user.username;
            } catch (_) {}

            lines.push(`${prefix} ${name} — ${formatMoney(row.total, settings)}`);
        }

        await interaction.editReply(lines.join('\n'));
    },
};
