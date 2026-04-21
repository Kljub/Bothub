// PFAD: /core/installer/src/commands/balance.js
const { SlashCommandBuilder } = require('discord.js');
const {
    isCommandEnabled, getEcoSettings, getOrCreateWallet, formatMoney
} = require('../../services/economy-service');

module.exports = {
    key: 'balance',

    data: new SlashCommandBuilder()
        .setName('balance')
        .setDescription('Zeigt dein aktuelles Guthaben an.')
        .addUserOption(o =>
            o.setName('user').setDescription('Anderen User anzeigen (optional)').setRequired(false)
        ),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'balance')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const target   = interaction.options.getUser('user') || interaction.user;
        const guildId  = interaction.guildId;
        const settings = await getEcoSettings(botId, guildId);
        const wallet   = await getOrCreateWallet(botId, guildId, target.id);

        await interaction.reply({
            content: [
                `**💰 Guthaben von ${target.username}**`,
                `Wallet: **${formatMoney(wallet.wallet, settings)}**`,
                `Bank:   **${formatMoney(wallet.bank,   settings)}**`,
                `Gesamt: **${formatMoney(wallet.wallet + wallet.bank, settings)}**`,
            ].join('\n'),
            ephemeral: true,
        });
    },
};
