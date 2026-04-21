const { SlashCommandBuilder } = require('discord.js');
const {
    isCommandEnabled, getCommandSetting, getEcoSettings, getOrCreateWallet, adjustWallet,
    getCooldownExpiry, setCooldown, formatMoney, formatRemaining,
} = require('../../services/economy-service');

module.exports = {
    key: 'rob',

    data: new SlashCommandBuilder()
        .setName('rob')
        .setDescription('Versuche einen anderen User auszurauben.')
        .addUserOption(o =>
            o.setName('user').setDescription('Opfer').setRequired(true)
        ),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'rob')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const userId   = interaction.user.id;
        const guildId  = interaction.guildId;
        const target   = interaction.options.getUser('user');
        const settings = await getEcoSettings(botId, guildId);

        if (target.id === userId) {
            return interaction.reply({ content: '❌ Du kannst dich nicht selbst ausrauben.', ephemeral: true });
        }
        if (target.bot) {
            return interaction.reply({ content: '❌ Du kannst keine Bots ausrauben.', ephemeral: true });
        }

        const cooldownSecs = await getCommandSetting(botId, 'rob', 'cooldown', 600);
        if (cooldownSecs > 0) {
            const cd = await getCooldownExpiry(botId, guildId, userId, 'rob');
            if (cd) {
                return interaction.reply({
                    content: `⏳ Du musst noch **${formatRemaining(cd)}** warten bevor du wieder rauben kannst.`,
                    ephemeral: true,
                });
            }
        }

        const minRequired = await getCommandSetting(botId, 'rob', 'min_wallet', 200);
        const myWallet    = await getOrCreateWallet(botId, guildId, userId);
        if (myWallet.wallet < minRequired) {
            return interaction.reply({
                content: `❌ Du benötigst mindestens **${formatMoney(minRequired, settings)}** in deiner Wallet um jemanden auszurauben.`,
                ephemeral: true,
            });
        }

        const targetWallet = await getOrCreateWallet(botId, guildId, target.id);
        if (targetWallet.wallet <= 0) {
            return interaction.reply({
                content: `❌ **${target.username}** hat nichts zu stehlen.`,
                ephemeral: true,
            });
        }

        if (cooldownSecs > 0) await setCooldown(botId, guildId, userId, 'rob', cooldownSecs);

        const success = Math.random() < 0.5;
        if (success) {
            const stolen = Math.min(
                Math.floor(Math.random() * targetWallet.wallet) + 1,
                targetWallet.wallet,
            );
            await adjustWallet(botId, guildId, userId, stolen);
            await adjustWallet(botId, guildId, target.id, -stolen);
            const updatedWallet = await getOrCreateWallet(botId, guildId, userId);
            return interaction.reply({
                content: `💰 Du hast **${target.username}** ausgeraubt und **${formatMoney(stolen, settings)}** gestohlen!\nGuthaben: **${formatMoney(updatedWallet.wallet, settings)}**`,
            });
        } else {
            return interaction.reply({
                content: `🚔 Du wurdest beim Versuch **${target.username}** auszurauben erwischt! Du entkamst ohne Beute.`,
            });
        }
    },
};
