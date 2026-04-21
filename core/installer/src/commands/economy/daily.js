// PFAD: /core/installer/src/commands/daily.js
const { SlashCommandBuilder } = require('discord.js');
const {
    isCommandEnabled, getEcoSettings, getOrCreateWallet, adjustWallet,
    getCooldownExpiry, setCooldown, formatMoney, formatRemaining,
} = require('../../services/economy-service');

module.exports = {
    key: 'daily',

    data: new SlashCommandBuilder()
        .setName('daily')
        .setDescription('Hole deinen täglichen Bonus ab.'),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'daily')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const userId   = interaction.user.id;
        const guildId  = interaction.guildId;
        const settings = await getEcoSettings(botId, guildId);

        const cooldown = await getCooldownExpiry(botId, guildId, userId, 'daily');
        if (cooldown) {
            return interaction.reply({
                content: `⏳ Du hast deinen Daily bereits abgeholt. Komm in **${formatRemaining(cooldown)}** wieder.`,
                ephemeral: true,
            });
        }

        const amount = Number(settings.daily_amount || 200);
        await getOrCreateWallet(botId, guildId, userId);
        await adjustWallet(botId, guildId, userId, amount);
        await setCooldown(botId, guildId, userId, 'daily', 86400);

        await interaction.reply({
            content: `🎁 **Daily Bonus!** Du hast **${formatMoney(amount, settings)}** erhalten!`,
        });
    },
};
