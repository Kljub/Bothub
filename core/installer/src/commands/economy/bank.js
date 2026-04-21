// PFAD: /core/installer/src/commands/bank.js
const { SlashCommandBuilder } = require('discord.js');
const {
    isCommandEnabled, getEcoSettings, getOrCreateWallet, formatMoney,
} = require('../../services/economy-service');

module.exports = {
    key: 'bank',

    data: new SlashCommandBuilder()
        .setName('bank')
        .setDescription('Zeigt dein Bankkonto und Zinsinformationen an.'),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'bank')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const userId   = interaction.user.id;
        const guildId  = interaction.guildId;
        const settings = await getEcoSettings(botId, guildId);
        const wallet   = await getOrCreateWallet(botId, guildId, userId);

        const rate     = Number(settings.bank_interest_rate || 0);
        const interest = rate > 0 ? Math.floor(wallet.bank * (rate / 100)) : 0;

        const lines = [
            `**🏦 Bank von ${interaction.user.username}**`,
            `Kontostand: **${formatMoney(wallet.bank, settings)}**`,
            rate > 0
                ? `Zinssatz: **${rate}%** → nächste Zinsen ca. **${formatMoney(interest, settings)}**`
                : `Zinssatz: **Nicht konfiguriert**`,
            '',
            `Wallet: **${formatMoney(wallet.wallet, settings)}**`,
        ];

        await interaction.reply({ content: lines.join('\n'), ephemeral: true });
    },
};
