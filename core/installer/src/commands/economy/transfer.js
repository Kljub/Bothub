// PFAD: /core/installer/src/commands/transfer.js
const { SlashCommandBuilder } = require('discord.js');
const {
    isCommandEnabled, getEcoSettings, getOrCreateWallet, adjustWallet, formatMoney,
} = require('../../services/economy-service');

module.exports = {
    key: 'transfer',

    data: new SlashCommandBuilder()
        .setName('transfer')
        .setDescription('Geld an einen anderen User senden.')
        .addUserOption(o =>
            o.setName('user').setDescription('Empfänger').setRequired(true)
        )
        .addIntegerOption(o =>
            o.setName('amount').setDescription('Betrag').setRequired(true).setMinValue(1)
        ),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'transfer')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const userId   = interaction.user.id;
        const guildId  = interaction.guildId;
        const target   = interaction.options.getUser('user');
        const amount   = interaction.options.getInteger('amount');
        const settings = await getEcoSettings(botId, guildId);

        if (target.id === userId) {
            return interaction.reply({ content: '❌ Du kannst nicht an dich selbst überweisen.', ephemeral: true });
        }
        if (target.bot) {
            return interaction.reply({ content: '❌ Du kannst nicht an Bots überweisen.', ephemeral: true });
        }

        const senderWallet = await getOrCreateWallet(botId, guildId, userId);
        if (senderWallet.wallet < amount) {
            return interaction.reply({
                content: `❌ Nicht genug Geld. Du hast **${formatMoney(senderWallet.wallet, settings)}** in der Wallet.`,
                ephemeral: true,
            });
        }

        await adjustWallet(botId, guildId, userId, -amount);
        await getOrCreateWallet(botId, guildId, target.id);
        await adjustWallet(botId, guildId, target.id, amount);

        await interaction.reply({
            content: `✅ **${formatMoney(amount, settings)}** an **${target.username}** gesendet.`,
        });
    },
};
