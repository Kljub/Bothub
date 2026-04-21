const { SlashCommandBuilder } = require('discord.js');
const {
    isCommandEnabled, getCommandSetting, getEcoSettings, getOrCreateWallet, adjustWallet,
    getCooldownExpiry, setCooldown, formatMoney, formatRemaining,
} = require('../../services/economy-service');

module.exports = {
    key: 'present',

    data: new SlashCommandBuilder()
        .setName('present')
        .setDescription('Ein wöchentliches Geschenk auspacken — zufälliger Gewinn.'),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'present')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const userId       = interaction.user.id;
        const guildId      = interaction.guildId;
        const settings     = await getEcoSettings(botId, guildId);
        const cooldownSecs = await getCommandSetting(botId, 'present', 'cooldown', 604800);

        if (cooldownSecs > 0) {
            const cd = await getCooldownExpiry(botId, guildId, userId, 'present');
            if (cd) {
                return interaction.reply({
                    content: `⏳ Dein nächstes Geschenk ist in **${formatRemaining(cd)}** verfügbar.`,
                    ephemeral: true,
                });
            }
        }

        const reward = Math.floor(Math.random() * 1000) + 1;
        await adjustWallet(botId, guildId, userId, reward);
        if (cooldownSecs > 0) await setCooldown(botId, guildId, userId, 'present', cooldownSecs);

        const wallet = await getOrCreateWallet(botId, guildId, userId);
        await interaction.reply({
            content: `🎁 Du hast dein Geschenk ausgepackt und **${formatMoney(reward, settings)}** gefunden!\nGuthaben: **${formatMoney(wallet.wallet, settings)}**`,
        });
    },
};
