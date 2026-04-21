const { SlashCommandBuilder } = require('discord.js');
const {
    isCommandEnabled, getCommandSetting, getEcoSettings, getOrCreateWallet, adjustWallet,
    getCooldownExpiry, setCooldown, formatMoney, formatRemaining,
} = require('../../services/economy-service');

module.exports = {
    key: 'monthly',

    data: new SlashCommandBuilder()
        .setName('monthly')
        .setDescription('Monatliche Belohnung abholen.'),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'monthly')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const userId       = interaction.user.id;
        const guildId      = interaction.guildId;
        const settings     = await getEcoSettings(botId, guildId);
        const cooldownSecs = await getCommandSetting(botId, 'monthly', 'cooldown', 2592000);
        const reward       = await getCommandSetting(botId, 'monthly', 'reward', 1000);

        if (cooldownSecs > 0) {
            const cd = await getCooldownExpiry(botId, guildId, userId, 'monthly');
            if (cd) {
                return interaction.reply({
                    content: `⏳ Deine nächste monatliche Belohnung ist in **${formatRemaining(cd)}** verfügbar.`,
                    ephemeral: true,
                });
            }
        }

        await adjustWallet(botId, guildId, userId, reward);
        if (cooldownSecs > 0) await setCooldown(botId, guildId, userId, 'monthly', cooldownSecs);

        const wallet = await getOrCreateWallet(botId, guildId, userId);
        await interaction.reply({
            content: `🗓️ Monatliche Belohnung erhalten! +**${formatMoney(reward, settings)}**\nGuthaben: **${formatMoney(wallet.wallet, settings)}**`,
        });
    },
};
