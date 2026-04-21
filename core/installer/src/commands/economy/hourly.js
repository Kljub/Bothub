const { SlashCommandBuilder } = require('discord.js');
const {
    isCommandEnabled, getCommandSetting, getEcoSettings, getOrCreateWallet, adjustWallet,
    getCooldownExpiry, setCooldown, formatMoney, formatRemaining,
} = require('../../services/economy-service');

module.exports = {
    key: 'hourly',

    data: new SlashCommandBuilder()
        .setName('hourly')
        .setDescription('Stündliche Belohnung abholen.'),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'hourly')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const userId       = interaction.user.id;
        const guildId      = interaction.guildId;
        const settings     = await getEcoSettings(botId, guildId);
        const cooldownSecs = await getCommandSetting(botId, 'hourly', 'cooldown', 3600);
        const reward       = await getCommandSetting(botId, 'hourly', 'reward', 10);

        if (cooldownSecs > 0) {
            const cd = await getCooldownExpiry(botId, guildId, userId, 'hourly');
            if (cd) {
                return interaction.reply({
                    content: `⏳ Deine nächste stündliche Belohnung ist in **${formatRemaining(cd)}** verfügbar.`,
                    ephemeral: true,
                });
            }
        }

        await adjustWallet(botId, guildId, userId, reward);
        if (cooldownSecs > 0) await setCooldown(botId, guildId, userId, 'hourly', cooldownSecs);

        const wallet = await getOrCreateWallet(botId, guildId, userId);
        await interaction.reply({
            content: `⏰ Stündliche Belohnung erhalten! +**${formatMoney(reward, settings)}**\nGuthaben: **${formatMoney(wallet.wallet, settings)}**`,
        });
    },
};
