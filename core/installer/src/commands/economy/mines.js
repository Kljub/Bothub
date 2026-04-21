// PFAD: /core/installer/src/commands/mines.js
const { SlashCommandBuilder, ActionRowBuilder, ButtonBuilder, ButtonStyle } = require('discord.js');
const {
    isCommandEnabled, getCommandSetting, getEcoSettings, getOrCreateWallet, adjustWallet,
    getCooldownExpiry, formatMoney, formatRemaining,
    startMines, getMinesGame, calcMinesMultiplier, MINES_SIZE,
} = require('../../services/economy-service');

function buildGrid(state, userId, gameOver = false) {
    const rows = [];
    for (let r = 0; r < 4; r++) {
        const buttons = [];
        for (let c = 0; c < 4; c++) {
            const pos      = r * 4 + c;
            const revealed = state.revealed[pos];
            const isMine   = state.grid[pos];

            let label = '⬜';
            let style = ButtonStyle.Secondary;
            let disabled = gameOver || revealed;

            if (revealed) {
                label    = isMine ? '💣' : '💎';
                style    = isMine ? ButtonStyle.Danger : ButtonStyle.Success;
                disabled = true;
            } else if (gameOver && isMine) {
                label    = '💣';
                style    = ButtonStyle.Danger;
                disabled = true;
            }

            buttons.push(
                new ButtonBuilder()
                    .setCustomId(`mines_${pos}_${userId}`)
                    .setLabel(label)
                    .setStyle(style)
                    .setDisabled(disabled)
            );
        }
        rows.push(new ActionRowBuilder().addComponents(...buttons));
    }

    // Cashout row
    const mult      = calcMinesMultiplier(state.safeRevealed, state.mineCount);
    const cashout   = Math.floor(state.bet * mult);
    const canCash   = !gameOver && state.safeRevealed > 0;
    rows.push(
        new ActionRowBuilder().addComponents(
            new ButtonBuilder()
                .setCustomId(`mines_cash_${userId}`)
                .setLabel(canCash ? `💰 Cashout — ${cashout} 🪙` : '💰 Cashout')
                .setStyle(ButtonStyle.Success)
                .setDisabled(!canCash)
        )
    );

    return rows;
}

function buildContent(state, settings, resultLine = null) {
    const mult  = calcMinesMultiplier(state.safeRevealed, state.mineCount);
    const lines = [
        `**💣 Minesweeper** | Einsatz: ${formatMoney(state.bet, settings)} | Minen: ${state.mineCount}`,
        `Aufgedeckt: **${state.safeRevealed}** | Multiplikator: **×${mult.toFixed(2)}** | Cashout: **${formatMoney(Math.floor(state.bet * mult), settings)}**`,
    ];
    if (resultLine) lines.push('', resultLine);
    return lines.join('\n');
}

module.exports = {
    key: 'mines',

    data: new SlashCommandBuilder()
        .setName('mines')
        .setDescription('Minesweeper Minigame — decke Felder auf ohne Mine zu treffen.')
        .addIntegerOption(o =>
            o.setName('bet').setDescription('Einsatz').setRequired(true).setMinValue(1)
        )
        .addIntegerOption(o =>
            o.setName('mines').setDescription('Anzahl Minen (1–8, Standard: 4)').setRequired(false).setMinValue(1).setMaxValue(8)
        ),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'mines')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const userId    = interaction.user.id;
        const guildId   = interaction.guildId;
        const bet       = interaction.options.getInteger('bet');
        const mineCount = interaction.options.getInteger('mines') || 4;
        const settings  = await getEcoSettings(botId, guildId);
        const gameKey   = `${botId}_${userId}`;

        if (getMinesGame(gameKey)) {
            return interaction.reply({ content: '⚠️ Du hast bereits ein laufendes Minesweeper-Spiel.', ephemeral: true });
        }

        const cooldownSecs = await getCommandSetting(botId, 'mines', 'cooldown', 0);
        if (cooldownSecs > 0) {
            const cd = await getCooldownExpiry(botId, guildId, userId, 'mines');
            if (cd) {
                return interaction.reply({
                    content: `⏳ Warte noch **${formatRemaining(cd)}** bis zum nächsten Minesweeper-Spiel.`,
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

        const state        = startMines(gameKey, bet, mineCount);
        state.botId        = botId;
        state.guildId      = guildId;
        state.userId       = userId;
        state.settings     = settings;
        state.cooldownSecs = cooldownSecs;

        await interaction.reply({
            content:    buildContent(state, settings),
            components: buildGrid(state, userId),
        });
    },
};
