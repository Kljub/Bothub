// PFAD: /core/installer/src/commands/economy/hangman.js
const { SlashCommandBuilder, ActionRowBuilder, ButtonBuilder, ButtonStyle } = require('discord.js');
const {
    isCommandEnabled, getCommandSetting, getEcoSettings, getOrCreateWallet, adjustWallet,
    getCooldownExpiry, setCooldown, formatMoney, formatRemaining,
    getHangmanWord, startHangman, getHangmanGame, hangmanDisplay,
} = require('../../services/economy-service');

function buildComponents(userId, gameOver = false) {
    return [
        new ActionRowBuilder().addComponents(
            new ButtonBuilder()
                .setCustomId(`hm_guess_${userId}`)
                .setLabel('🔤 Buchstabe raten')
                .setStyle(ButtonStyle.Primary)
                .setDisabled(gameOver),
            new ButtonBuilder()
                .setCustomId(`hm_quit_${userId}`)
                .setLabel('❌ Aufgeben')
                .setStyle(ButtonStyle.Danger)
                .setDisabled(gameOver),
        ),
    ];
}

function buildContent(state, settings, result = null) {
    const { visual, maskedWord, wrongLetters } = hangmanDisplay(state);
    const lines = [
        `**🪢 Hangman** | Einsatz: ${formatMoney(state.bet, settings)} | Gewinn bei Sieg: ${formatMoney(state.bet * 2, settings)}`,
        visual,
        `Wort: \`${maskedWord}\`  (${state.word.length} Buchstaben)`,
        `Falsch (${state.wrongGuesses}/${state.maxWrong}): ${wrongLetters.length > 0 ? wrongLetters.join(' ') : '—'}`,
    ];
    if (result) lines.push('', result);
    return lines.join('\n');
}

module.exports = {
    key: 'hangman',

    data: new SlashCommandBuilder()
        .setName('hangman')
        .setDescription('Errate das Wort und gewinne das Doppelte deines Einsatzes.')
        .addIntegerOption(o =>
            o.setName('bet').setDescription('Einsatz').setRequired(true).setMinValue(1)
        ),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'hangman')) {
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

        if (getHangmanGame(gameKey)) {
            return interaction.reply({ content: '⚠️ Du hast bereits ein laufendes Hangman-Spiel.', ephemeral: true });
        }

        const cooldownSecs = await getCommandSetting(botId, 'hangman', 'cooldown', 0);
        if (cooldownSecs > 0) {
            const cd = await getCooldownExpiry(botId, guildId, userId, 'hangman');
            if (cd) {
                return interaction.reply({
                    content: `⏳ Du musst noch **${formatRemaining(cd)}** warten bevor du wieder Hangman spielen kannst.`,
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

        await adjustWallet(botId, guildId, userId, -bet);

        const word  = await getHangmanWord(botId);
        const state = startHangman(gameKey, word, bet);
        state.botId        = botId;
        state.guildId      = guildId;
        state.userId       = userId;
        state.settings     = settings;
        state.cooldownSecs = cooldownSecs;

        await interaction.reply({
            content:    buildContent(state, settings),
            components: buildComponents(userId),
        });
    },
};
