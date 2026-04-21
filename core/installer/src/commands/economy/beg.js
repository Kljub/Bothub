const { SlashCommandBuilder } = require('discord.js');
const {
    isCommandEnabled, getCommandSetting, getEcoSettings, getOrCreateWallet, adjustWallet,
    getCooldownExpiry, setCooldown, formatMoney, formatRemaining,
} = require('../../services/economy-service');

module.exports = {
    key: 'beg',

    data: new SlashCommandBuilder()
        .setName('beg')
        .setDescription('Um Geld betteln.'),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'beg')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const userId       = interaction.user.id;
        const guildId      = interaction.guildId;
        const settings     = await getEcoSettings(botId, guildId);
        const cooldownSecs = await getCommandSetting(botId, 'beg', 'cooldown', 180);
        const reward       = await getCommandSetting(botId, 'beg', 'reward', 5);

        if (cooldownSecs > 0) {
            const cd = await getCooldownExpiry(botId, guildId, userId, 'beg');
            if (cd) {
                return interaction.reply({
                    content: `⏳ Du musst noch **${formatRemaining(cd)}** warten bevor du wieder betteln kannst.`,
                    ephemeral: true,
                });
            }
        }

        await adjustWallet(botId, guildId, userId, reward);
        if (cooldownSecs > 0) await setCooldown(botId, guildId, userId, 'beg', cooldownSecs);

        const wallet = await getOrCreateWallet(botId, guildId, userId);

        await interaction.reply({
            content: `🙏 Du hast gebettelt und **${formatMoney(reward, settings)}** erhalten!\nGuthaben: **${formatMoney(wallet.wallet, settings)}**`,
        });
    },
};
