const { SlashCommandBuilder } = require('discord.js');
const {
    isCommandEnabled, getCommandSetting, getEcoSettings, getOrCreateWallet, adjustWallet,
    getCooldownExpiry, setCooldown, formatMoney, formatRemaining,
} = require('../../services/economy-service');

module.exports = {
    key: 'weekly',

    data: new SlashCommandBuilder()
        .setName('weekly')
        .setDescription('Wöchentliche Belohnung abholen.'),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'weekly')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const userId       = interaction.user.id;
        const guildId      = interaction.guildId;
        const settings     = await getEcoSettings(botId, guildId);
        const cooldownSecs = await getCommandSetting(botId, 'weekly', 'cooldown', 604800);
        const reward       = await getCommandSetting(botId, 'weekly', 'reward', 500);

        if (cooldownSecs > 0) {
            const cd = await getCooldownExpiry(botId, guildId, userId, 'weekly');
            if (cd) {
                return interaction.reply({
                    content: `⏳ Deine nächste wöchentliche Belohnung ist in **${formatRemaining(cd)}** verfügbar.`,
                    ephemeral: true,
                });
            }
        }

        await adjustWallet(botId, guildId, userId, reward);
        if (cooldownSecs > 0) await setCooldown(botId, guildId, userId, 'weekly', cooldownSecs);

        const wallet = await getOrCreateWallet(botId, guildId, userId);
        await interaction.reply({
            content: `📅 Wöchentliche Belohnung erhalten! +**${formatMoney(reward, settings)}**\nGuthaben: **${formatMoney(wallet.wallet, settings)}**`,
        });
    },
};
