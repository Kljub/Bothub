// PFAD: /core/installer/src/commands/withdraw.js
const { SlashCommandBuilder } = require('discord.js');
const {
    isCommandEnabled, getEcoSettings, getOrCreateWallet, adjustWallet, formatMoney,
} = require('../../services/economy-service');

module.exports = {
    key: 'withdraw',

    data: new SlashCommandBuilder()
        .setName('withdraw')
        .setDescription('Geld von der Bank abheben.')
        .addStringOption(o =>
            o.setName('amount')
             .setDescription('Betrag oder "all"')
             .setRequired(true)
        ),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'withdraw')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const userId   = interaction.user.id;
        const guildId  = interaction.guildId;
        const settings = await getEcoSettings(botId, guildId);
        const wallet   = await getOrCreateWallet(botId, guildId, userId);

        if (wallet.bank <= 0) {
            return interaction.reply({ content: '❌ Deine Bank ist leer.', ephemeral: true });
        }

        const raw    = String(interaction.options.getString('amount') || '').toLowerCase().trim();
        const amount = raw === 'all' ? wallet.bank : Math.min(Math.max(0, parseInt(raw, 10) || 0), wallet.bank);

        if (amount <= 0) {
            return interaction.reply({ content: '❌ Ungültiger Betrag.', ephemeral: true });
        }

        await adjustWallet(botId, guildId, userId, amount, -amount);
        await interaction.reply({
            content: `💳 **${formatMoney(amount, settings)}** von der Bank abgehoben.`,
            ephemeral: true,
        });
    },
};
