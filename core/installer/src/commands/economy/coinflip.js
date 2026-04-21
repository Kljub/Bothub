// PFAD: /core/installer/src/commands/coinflip.js
const { SlashCommandBuilder } = require('discord.js');
const {
    isCommandEnabled, getCommandSetting, getEcoSettings, getOrCreateWallet, adjustWallet,
    getCooldownExpiry, setCooldown, formatMoney, formatRemaining,
} = require('../../services/economy-service');

module.exports = {
    key: 'coinflip',

    data: new SlashCommandBuilder()
        .setName('coinflip')
        .setDescription('Setze Geld auf Kopf oder Zahl.')
        .addStringOption(o =>
            o.setName('side')
             .setDescription('Kopf oder Zahl')
             .setRequired(true)
             .addChoices(
                 { name: 'Kopf', value: 'heads' },
                 { name: 'Zahl', value: 'tails' },
             )
        )
        .addIntegerOption(o =>
            o.setName('bet').setDescription('Einsatz').setRequired(true).setMinValue(1)
        ),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'coinflip')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const userId   = interaction.user.id;
        const guildId  = interaction.guildId;
        const side     = interaction.options.getString('side');
        const bet      = interaction.options.getInteger('bet');
        const settings = await getEcoSettings(botId, guildId);

        const cooldownSecs = await getCommandSetting(botId, 'coinflip', 'cooldown', 0);
        if (cooldownSecs > 0) {
            const cd = await getCooldownExpiry(botId, guildId, userId, 'coinflip');
            if (cd) {
                return interaction.reply({
                    content: `⏳ Warte noch **${formatRemaining(cd)}** bis zum nächsten Münzwurf.`,
                    ephemeral: true,
                });
            }
        }

        const wallet = await getOrCreateWallet(botId, guildId, userId);

        if (wallet.wallet < bet) {
            return interaction.reply({
                content: `❌ Nicht genug Geld. Du hast **${formatMoney(wallet.wallet, settings)}** in der Wallet.`,
                ephemeral: true,
            });
        }

        const result     = Math.random() < 0.5 ? 'heads' : 'tails';
        const win        = result === side;
        const change     = win ? bet : -bet;
        await adjustWallet(botId, guildId, userId, change);
        if (cooldownSecs > 0) await setCooldown(botId, guildId, userId, 'coinflip', cooldownSecs);

        const emoji      = result === 'heads' ? '👑' : '🪙';
        const resultName = result === 'heads' ? 'Kopf' : 'Zahl';
        const sideName   = side   === 'heads' ? 'Kopf' : 'Zahl';

        await interaction.reply({
            content: win
                ? `${emoji} **${resultName}!** Du hast **${sideName}** gewählt — 🎉 +**${formatMoney(bet, settings)}**!`
                : `${emoji} **${resultName}!** Du hast **${sideName}** gewählt — 💸 -**${formatMoney(bet, settings)}**.`,
        });
    },
};
