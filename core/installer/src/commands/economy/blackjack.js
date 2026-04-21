// PFAD: /core/installer/src/commands/blackjack.js
const { SlashCommandBuilder, ActionRowBuilder, ButtonBuilder, ButtonStyle } = require('discord.js');
const {
    isCommandEnabled, getCommandSetting, getEcoSettings, getOrCreateWallet, adjustWallet,
    getCooldownExpiry, setCooldown, formatMoney, formatRemaining,
    startBlackjack, getBlackjackGame, handValue, formatHand,
} = require('../../services/economy-service');

function buildComponents(gameKey, disabled = false) {
    return [
        new ActionRowBuilder().addComponents(
            new ButtonBuilder()
                .setCustomId(`bj_hit_${gameKey}`)
                .setLabel('Hit')
                .setStyle(ButtonStyle.Primary)
                .setDisabled(disabled),
            new ButtonBuilder()
                .setCustomId(`bj_stand_${gameKey}`)
                .setLabel('Stand')
                .setStyle(ButtonStyle.Danger)
                .setDisabled(disabled),
        ),
    ];
}

function buildContent(state, showDealer = false, resultLine = null) {
    const pVal = handValue(state.playerHand);
    const dVal = handValue(state.dealerHand);
    const lines = [
        `**🃏 Blackjack** | Einsatz: ${state.bet}`,
        `Deine Hand: ${formatHand(state.playerHand)} — **${pVal}**`,
        showDealer
            ? `Dealer:     ${formatHand(state.dealerHand)} — **${dVal}**`
            : `Dealer:     ${formatHand(state.dealerHand, true)} — **?**`,
    ];
    if (resultLine) lines.push('', resultLine);
    return lines.join('\n');
}

module.exports = {
    key: 'blackjack',

    data: new SlashCommandBuilder()
        .setName('blackjack')
        .setDescription('Blackjack spielen.')
        .addIntegerOption(o =>
            o.setName('bet').setDescription('Einsatz').setRequired(true).setMinValue(1)
        ),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'blackjack')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const userId   = interaction.user.id;
        const guildId  = interaction.guildId;
        const bet      = interaction.options.getInteger('bet');
        const settings = await getEcoSettings(botId, guildId);
        const gameKey  = `${botId}_${userId}`;

        if (getBlackjackGame(gameKey)) {
            return interaction.reply({ content: '⚠️ Du hast bereits ein laufendes Blackjack-Spiel.', ephemeral: true });
        }

        const cooldownSecs = await getCommandSetting(botId, 'blackjack', 'cooldown', 0);
        if (cooldownSecs > 0) {
            const cd = await getCooldownExpiry(botId, guildId, userId, 'blackjack');
            if (cd) {
                return interaction.reply({
                    content: `⏳ Warte noch **${formatRemaining(cd)}** bis zum nächsten Blackjack-Spiel.`,
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

        // Reserve the bet immediately
        await adjustWallet(botId, guildId, userId, -bet);

        const state        = startBlackjack(gameKey, bet);
        state.botId        = botId;
        state.guildId      = guildId;
        state.userId       = userId;
        state.settings     = settings;
        state.cooldownSecs = cooldownSecs;

        const pVal = handValue(state.playerHand);

        // Natural blackjack
        if (pVal === 21) {
            const { endBlackjack } = require('../../services/economy-service');
            endBlackjack(gameKey);
            const winAmount = Math.floor(bet * 1.5);
            await adjustWallet(botId, guildId, userId, bet + winAmount);
            if (cooldownSecs > 0) await setCooldown(botId, guildId, userId, 'blackjack', cooldownSecs);
            return interaction.reply({
                content: buildContent(state, true, `🎉 **Blackjack!** Du gewinnst **${formatMoney(winAmount, settings)}**!`),
            });
        }

        await interaction.reply({
            content:    buildContent(state),
            components: buildComponents(gameKey),
        });
    },
};
