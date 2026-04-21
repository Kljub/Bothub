// PFAD: /core/installer/src/commands/deposit.js
const { SlashCommandBuilder } = require('discord.js');
const {
    isCommandEnabled, getEcoSettings, getOrCreateWallet, adjustWallet, formatMoney,
} = require('../../services/economy-service');

module.exports = {
    key: 'deposit',

    data: new SlashCommandBuilder()
        .setName('deposit')
        .setDescription('Geld auf die Bank einzahlen.')
        .addStringOption(o =>
            o.setName('amount')
             .setDescription('Betrag oder "all"')
             .setRequired(true)
        ),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'deposit')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const userId   = interaction.user.id;
        const guildId  = interaction.guildId;
        const settings = await getEcoSettings(botId, guildId);
        const wallet   = await getOrCreateWallet(botId, guildId, userId);

        if (wallet.wallet <= 0) {
            return interaction.reply({ content: '❌ Deine Wallet ist leer.', ephemeral: true });
        }

        const raw    = String(interaction.options.getString('amount') || '').toLowerCase().trim();
        const amount = raw === 'all' ? wallet.wallet : Math.min(Math.max(0, parseInt(raw, 10) || 0), wallet.wallet);

        if (amount <= 0) {
            return interaction.reply({ content: '❌ Ungültiger Betrag.', ephemeral: true });
        }

        await adjustWallet(botId, guildId, userId, -amount, amount);
        await interaction.reply({
            content: `🏦 **${formatMoney(amount, settings)}** auf die Bank eingezahlt.`,
            ephemeral: true,
        });
    },
};
