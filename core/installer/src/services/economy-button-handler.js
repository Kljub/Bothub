// PFAD: /core/installer/src/services/economy-button-handler.js
// Handles button interactions for Blackjack, Mines, Hangman and Crash minigames.

const { ActionRowBuilder, ButtonBuilder, ButtonStyle, ModalBuilder, TextInputBuilder, TextInputStyle } = require('discord.js');
const {
    getBlackjackGame, endBlackjack, handValue, formatHand,
    getOrCreateWallet, adjustWallet, formatMoney, getEcoSettings,
    getMinesGame, endMines, calcMinesMultiplier, MINES_SIZE,
    getHangmanGame, endHangman, hangmanDisplay,
    getCrashGame, endCrash,
    setCooldown,
} = require('./economy-service');

// ── Blackjack ─────────────────────────────────────────────────────────────────

function bjComponents(gameKey, disabled = false) {
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

function bjContent(state, showDealer, resultLine) {
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

async function handleBlackjackButton(interaction, botId) {
    // customId: bj_hit_<botId>_<userId>  or  bj_stand_<botId>_<userId>
    const parts    = interaction.customId.split('_');
    const action   = parts[1];              // 'hit' or 'stand'
    const targetId = parts.slice(3).join('_'); // skip 'bj', action, botId → only userId

    // Verify it's the game owner
    if (interaction.user.id !== targetId) {
        return interaction.reply({ content: '❌ Das ist nicht dein Spiel.', ephemeral: true });
    }

    const gameKey = `${botId}_${targetId}`;
    const state   = getBlackjackGame(gameKey);

    if (!state) {
        return interaction.update({ content: '⏰ Dieses Spiel ist abgelaufen.', components: [] });
    }

    const settings = state.settings || await getEcoSettings(botId, state.guildId || interaction.guildId);

    if (action === 'hit') {
        state.playerHand.push(state.deck.pop());
        const pVal = handValue(state.playerHand);

        if (pVal > 21) {
            // Bust
            endBlackjack(gameKey);
            return interaction.update({
                content:    bjContent(state, true, `💥 **Bust! (${pVal})** Du verlierst **${formatMoney(state.bet, settings)}**.`),
                components: bjComponents(gameKey, true),
            });
        }

        if (pVal === 21) {
            // Auto-stand on 21
            return resolveBlackjack(interaction, gameKey, state, settings);
        }

        return interaction.update({
            content:    bjContent(state, false),
            components: bjComponents(gameKey),
        });
    }

    if (action === 'stand') {
        return resolveBlackjack(interaction, gameKey, state, settings);
    }
}

async function resolveBlackjack(interaction, gameKey, state, settings) {
    // Dealer draws until 17+
    while (handValue(state.dealerHand) < 17) {
        state.dealerHand.push(state.deck.pop());
    }

    const pVal = handValue(state.playerHand);
    const dVal = handValue(state.dealerHand);
    const bet  = state.bet;

    let resultLine;
    let payout = 0;

    if (dVal > 21 || pVal > dVal) {
        payout     = bet * 2; // return bet + win
        resultLine = `🎉 **Gewonnen!** Dealer: **${dVal}** | Du: **${pVal}** → +**${formatMoney(bet, settings)}**`;
    } else if (pVal === dVal) {
        payout     = bet; // push — return original bet
        resultLine = `🤝 **Unentschieden!** Einsatz zurück.`;
    } else {
        payout     = 0;
        resultLine = `😞 **Verloren.** Dealer: **${dVal}** | Du: **${pVal}** → -**${formatMoney(bet, settings)}**`;
    }

    endBlackjack(gameKey);
    const bjGuildId = state.guildId || interaction.guildId;
    const bjUserId  = state.userId  || interaction.user.id;
    if (payout > 0) await adjustWallet(state.botId || 0, bjGuildId, bjUserId, payout);
    if (state.cooldownSecs > 0) await setCooldown(state.botId || 0, bjGuildId, bjUserId, 'blackjack', state.cooldownSecs).catch(() => {});

    return interaction.update({
        content:    bjContent(state, true, resultLine),
        components: bjComponents(gameKey, true),
    });
}

// ── Mines ─────────────────────────────────────────────────────────────────────

function minesGrid(state, userId, gameOver = false) {
    const rows = [];
    for (let r = 0; r < 4; r++) {
        const buttons = [];
        for (let c = 0; c < 4; c++) {
            const pos      = r * 4 + c;
            const revealed = state.revealed[pos];
            const isMine   = state.grid[pos];

            let label    = '⬜';
            let style    = ButtonStyle.Secondary;
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

    const mult    = calcMinesMultiplier(state.safeRevealed, state.mineCount);
    const cashout = Math.floor(state.bet * mult);
    const canCash = !gameOver && state.safeRevealed > 0;

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

function minesContent(state, settings, resultLine) {
    const mult  = calcMinesMultiplier(state.safeRevealed, state.mineCount);
    const lines = [
        `**💣 Minesweeper** | Einsatz: ${formatMoney(state.bet, settings)} | Minen: ${state.mineCount}`,
        `Aufgedeckt: **${state.safeRevealed}** | Multiplikator: **×${mult.toFixed(2)}** | Cashout: **${formatMoney(Math.floor(state.bet * mult), settings)}**`,
    ];
    if (resultLine) lines.push('', resultLine);
    return lines.join('\n');
}

async function handleMinesButton(interaction, botId) {
    // customId formats:
    //   mines_<pos>_<userId>       — reveal cell
    //   mines_cash_<userId>        — cashout
    const raw = interaction.customId; // e.g. "mines_3_123456789"
    const withoutPrefix = raw.slice('mines_'.length); // "3_123456789" or "cash_123456789"

    const firstSep  = withoutPrefix.indexOf('_');
    const actionStr = withoutPrefix.slice(0, firstSep);        // "3" or "cash"
    const targetId  = withoutPrefix.slice(firstSep + 1);       // userId

    if (interaction.user.id !== targetId) {
        return interaction.reply({ content: '❌ Das ist nicht dein Spiel.', ephemeral: true });
    }

    const gameKey = `${botId}_${targetId}`;
    const state   = getMinesGame(gameKey);

    if (!state) {
        return interaction.update({ content: '⏰ Dieses Spiel ist abgelaufen.', components: [] });
    }

    const settings = state.settings || await getEcoSettings(botId, state.guildId || interaction.guildId);
    const userId   = state.userId || targetId;
    const guildId  = state.guildId || interaction.guildId;

    if (actionStr === 'cash') {
        if (state.safeRevealed === 0) {
            return interaction.reply({ content: '❌ Decke zuerst ein Feld auf.', ephemeral: true });
        }
        const mult    = calcMinesMultiplier(state.safeRevealed, state.mineCount);
        const winAmt  = Math.floor(state.bet * mult);
        endMines(gameKey);
        await adjustWallet(botId, guildId, userId, winAmt);
        if (state.cooldownSecs > 0) await setCooldown(botId, guildId, userId, 'mines', state.cooldownSecs).catch(() => {});
        return interaction.update({
            content:    minesContent(state, settings, `💰 **Cashout!** Du nimmst **${formatMoney(winAmt, settings)}** mit! (×${mult.toFixed(2)})`),
            components: minesGrid(state, targetId, true),
        });
    }

    // Reveal cell
    const pos = parseInt(actionStr, 10);
    if (!Number.isFinite(pos) || pos < 0 || pos >= MINES_SIZE) {
        return interaction.reply({ content: '❌ Ungültige Zelle.', ephemeral: true });
    }

    if (state.revealed[pos]) {
        return interaction.reply({ content: '❌ Dieses Feld ist bereits aufgedeckt.', ephemeral: true });
    }

    state.revealed[pos] = true;

    if (state.grid[pos]) {
        // Hit mine
        endMines(gameKey);
        if (state.cooldownSecs > 0) await setCooldown(botId, guildId, userId, 'mines', state.cooldownSecs).catch(() => {});
        return interaction.update({
            content:    minesContent(state, settings, `💥 **Mine getroffen!** Du verlierst **${formatMoney(state.bet, settings)}**.`),
            components: minesGrid(state, targetId, true),
        });
    }

    // Safe cell
    state.safeRevealed++;
    const safeLeft = MINES_SIZE - state.mineCount - state.safeRevealed;

    if (safeLeft === 0) {
        // All safe cells revealed — auto cashout
        const mult   = calcMinesMultiplier(state.safeRevealed, state.mineCount);
        const winAmt = Math.floor(state.bet * mult);
        endMines(gameKey);
        await adjustWallet(botId, guildId, userId, winAmt);
        if (state.cooldownSecs > 0) await setCooldown(botId, guildId, userId, 'mines', state.cooldownSecs).catch(() => {});
        return interaction.update({
            content:    minesContent(state, settings, `🏆 **Alle Felder aufgedeckt!** Du gewinnst **${formatMoney(winAmt, settings)}**!`),
            components: minesGrid(state, targetId, true),
        });
    }

    return interaction.update({
        content:    minesContent(state, settings),
        components: minesGrid(state, targetId),
    });
}

// ── Hangman ────────────────────────────────────────────────────────────────────

function hmComponents(userId, gameOver = false) {
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

function hmContent(state, settings, result = null) {
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

async function handleHangmanButton(interaction, botId) {
    // customId: hm_guess_<userId>  or  hm_quit_<userId>
    const parts  = interaction.customId.split('_');
    const action = parts[1];                    // 'guess' or 'quit'
    const userId = parts.slice(2).join('_');    // userId (may contain underscores)

    if (interaction.user.id !== userId) {
        return interaction.reply({ content: '❌ Das ist nicht dein Spiel.', ephemeral: true });
    }

    const gameKey = `${botId}_${userId}`;
    const state   = getHangmanGame(gameKey);

    if (!state) {
        return interaction.update({ content: '⏰ Dieses Spiel ist abgelaufen.', components: [] });
    }

    const settings = state.settings || await getEcoSettings(botId, state.guildId || interaction.guildId);

    if (action === 'quit') {
        endHangman(gameKey);
        const guildId = state.guildId || interaction.guildId;
        if (state.cooldownSecs > 0) await setCooldown(botId, guildId, userId, 'hangman', state.cooldownSecs).catch(() => {});
        return interaction.update({
            content:    hmContent(state, settings, `🏳️ **Aufgegeben.** Das Wort war: **${state.word}** — Du verlierst **${formatMoney(state.bet, settings)}**.`),
            components: hmComponents(userId, true),
        });
    }

    if (action === 'guess') {
        const modal = new ModalBuilder()
            .setCustomId(`hm_modal_${userId}`)
            .setTitle('Hangman — Buchstabe raten');

        modal.addComponents(
            new ActionRowBuilder().addComponents(
                new TextInputBuilder()
                    .setCustomId('hm_letter')
                    .setLabel('Buchstabe eingeben (A – Z)')
                    .setStyle(TextInputStyle.Short)
                    .setMinLength(1)
                    .setMaxLength(1)
                    .setPlaceholder('z.B. E')
                    .setRequired(true)
            )
        );

        return interaction.showModal(modal);
    }
}

async function handleHangmanModal(interaction, botId) {
    // customId: hm_modal_<userId>
    const userId  = interaction.customId.split('_').slice(2).join('_');

    if (interaction.user.id !== userId) {
        return interaction.reply({ content: '❌ Das ist nicht dein Spiel.', ephemeral: true });
    }

    const gameKey = `${botId}_${userId}`;
    const state   = getHangmanGame(gameKey);

    if (!state) {
        return interaction.reply({ content: '⏰ Dieses Spiel ist abgelaufen.', ephemeral: true });
    }

    const settings = state.settings || await getEcoSettings(botId, state.guildId || interaction.guildId);
    const raw      = interaction.fields.getTextInputValue('hm_letter').trim().toUpperCase();
    const letter   = raw[0];

    if (!letter || !/^[A-ZÄÖÜ]$/.test(letter)) {
        return interaction.reply({ content: '❌ Bitte gib einen einzelnen Buchstaben (A–Z) ein.', ephemeral: true });
    }

    if (state.guessed.has(letter)) {
        return interaction.reply({ content: `⚠️ **${letter}** wurde bereits geraten.`, ephemeral: true });
    }

    state.guessed.add(letter);
    const isCorrect = state.word.includes(letter);
    if (!isCorrect) state.wrongGuesses++;

    const isWin = state.word.split('').every(c => state.guessed.has(c));

    const guildId = state.guildId || interaction.guildId;

    if (isWin) {
        const payout = state.bet * 2;
        endHangman(gameKey);
        await adjustWallet(state.botId || botId, guildId, userId, payout);
        if (state.cooldownSecs > 0) await setCooldown(botId, guildId, userId, 'hangman', state.cooldownSecs).catch(() => {});
        return interaction.update({
            content:    hmContent(state, settings, `🎉 **Gewonnen!** Das Wort war **${state.word}** → +**${formatMoney(state.bet, settings)}**`),
            components: hmComponents(userId, true),
        });
    }

    if (state.wrongGuesses >= state.maxWrong) {
        endHangman(gameKey);
        if (state.cooldownSecs > 0) await setCooldown(botId, guildId, userId, 'hangman', state.cooldownSecs).catch(() => {});
        return interaction.update({
            content:    hmContent(state, settings, `💀 **Verloren!** Das Wort war: **${state.word}** — Du verlierst **${formatMoney(state.bet, settings)}**.`),
            components: hmComponents(userId, true),
        });
    }

    const hint = isCorrect ? `✅ **${letter}** ist im Wort!` : `❌ **${letter}** ist nicht im Wort.`;
    return interaction.update({
        content:    hmContent(state, settings, hint),
        components: hmComponents(userId),
    });
}

// ── Crash ──────────────────────────────────────────────────────────────────────

async function handleCrashButton(interaction, botId, customId) {
    // customId: crash_stop_<userId>
    const userId   = customId.replace('crash_stop_', '');
    if (interaction.user.id !== userId) {
        return interaction.reply({ content: '❌ Das ist nicht dein Spiel.', ephemeral: true });
    }

    const gameKey = `${botId}_${userId}`;
    const state   = getCrashGame(gameKey);
    if (!state) {
        return interaction.reply({ content: '⚠️ Kein aktives Crash-Spiel gefunden.', ephemeral: true });
    }

    // Stop interval and cash out
    if (state.intervalId) clearInterval(state.intervalId);
    endCrash(gameKey);

    const guildId   = state.guildId || interaction.guildId;
    const settings  = state.settings || await getEcoSettings(botId, guildId);
    const cashout   = Math.floor(state.bet * state.multiplier);
    await adjustWallet(botId, guildId, userId, cashout);

    if (state.cooldownSecs > 0) {
        await setCooldown(botId, guildId, userId, 'crash', state.cooldownSecs).catch(() => {});
    }

    const wallet = await getOrCreateWallet(botId, guildId, userId);
    const profit = cashout - state.bet;
    const profitLine = profit >= 0
        ? `+**${formatMoney(profit, settings)}** Gewinn`
        : `-**${formatMoney(Math.abs(profit), settings)}** Verlust`;

    try {
        await interaction.update({
            content: `💰 **Cashout bei ${state.multiplier.toFixed(2)}x!**\nRückzahlung: **${formatMoney(cashout, settings)}** (${profitLine})\nGuthaben: **${formatMoney(wallet.wallet, settings)}**`,
            components: [
                new ActionRowBuilder().addComponents(
                    new ButtonBuilder()
                        .setCustomId(`crash_stop_${userId}`)
                        .setLabel('🛑 Stop & Cashout')
                        .setStyle(ButtonStyle.Danger)
                        .setDisabled(true),
                ),
            ],
        });
    } catch (_) {}
}

module.exports = {
    handleBlackjackButton,
    handleMinesButton,
    handleHangmanButton,
    handleHangmanModal,
    handleCrashButton,
};
