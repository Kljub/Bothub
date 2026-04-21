const { SlashCommandBuilder, ActionRowBuilder, ButtonBuilder, ButtonStyle } = require('discord.js');
const {
    isCommandEnabled, getCommandSetting, getEcoSettings, getOrCreateWallet, adjustWallet,
    getCooldownExpiry, setCooldown, formatMoney, formatRemaining,
} = require('../../services/economy-service');

const SYMBOLS = ['🍇', '🍉', '🍊', '🍎', '🍓', '🍒'];

module.exports = {
    key: 'slots',

    data: new SlashCommandBuilder()
        .setName('slots')
        .setDescription('Spielautomat — drehe die Walzen.')
        .addIntegerOption(o =>
            o.setName('bet').setDescription('Einsatz').setRequired(true).setMinValue(1)
        ),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'slots')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const userId       = interaction.user.id;
        const guildId      = interaction.guildId;
        const bet          = interaction.options.getInteger('bet');
        const settings     = await getEcoSettings(botId, guildId);
        const cooldownSecs = await getCommandSetting(botId, 'slots', 'cooldown', 0);

        if (cooldownSecs > 0) {
            const cd = await getCooldownExpiry(botId, guildId, userId, 'slots');
            if (cd) {
                return interaction.reply({
                    content: `⏳ Warte noch **${formatRemaining(cd)}** bis zum nächsten Spin.`,
                    ephemeral: true,
                });
            }
        }

        const wallet = await getOrCreateWallet(botId, guildId, userId);
        if (wallet.wallet < bet) {
            return interaction.reply({
                content: `❌ Nicht genug Geld. Du hast **${formatMoney(wallet.wallet, settings)}**.`,
                ephemeral: true,
            });
        }

        const reels   = [0, 1, 2].map(() => Math.floor(Math.random() * SYMBOLS.length));
        const [a, b, c] = reels;

        let multiplier = 0;
        if (a === b && b === c) multiplier = 9;       // Jackpot: alle gleich
        else if (a === b || a === c || b === c) multiplier = 2; // zwei gleich

        if (cooldownSecs > 0) await setCooldown(botId, guildId, userId, 'slots', cooldownSecs);

        // Disabled display buttons to show the result
        const row = new ActionRowBuilder().addComponents(
            new ButtonBuilder().setCustomId('slots_r0').setLabel(SYMBOLS[a]).setStyle(ButtonStyle.Primary).setDisabled(true),
            new ButtonBuilder().setCustomId('slots_r1').setLabel(SYMBOLS[b]).setStyle(ButtonStyle.Primary).setDisabled(true),
            new ButtonBuilder().setCustomId('slots_r2').setLabel(SYMBOLS[c]).setStyle(ButtonStyle.Primary).setDisabled(true),
        );

        if (multiplier > 0) {
            const winAmount = Math.floor(bet * multiplier);
            await adjustWallet(botId, guildId, userId, winAmount);
            const updated = await getOrCreateWallet(botId, guildId, userId);
            return interaction.reply({
                content: `🎰 **Slots** | Einsatz: ${formatMoney(bet, settings)}\n🎉 **Gewinn! ×${multiplier}** → +**${formatMoney(winAmount, settings)}**\nGuthaben: **${formatMoney(updated.wallet, settings)}**`,
                components: [row],
            });
        } else {
            await adjustWallet(botId, guildId, userId, -bet);
            const updated = await getOrCreateWallet(botId, guildId, userId);
            return interaction.reply({
                content: `🎰 **Slots** | Einsatz: ${formatMoney(bet, settings)}\n💸 **Kein Gewinn.** -**${formatMoney(bet, settings)}**\nGuthaben: **${formatMoney(updated.wallet, settings)}**`,
                components: [row],
            });
        }
    },
};
