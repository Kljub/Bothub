const { SlashCommandBuilder, ActionRowBuilder, ButtonBuilder, ButtonStyle } = require('discord.js');
const {
    isCommandEnabled, getCommandSetting, getEcoSettings, getOrCreateWallet, adjustWallet,
    getCooldownExpiry, setCooldown, formatMoney, formatRemaining,
    startCrash, getCrashGame, endCrash,
} = require('../../services/economy-service');

function buildCrashRow(userId, disabled = false) {
    return new ActionRowBuilder().addComponents(
        new ButtonBuilder()
            .setCustomId(`crash_stop_${userId}`)
            .setLabel('🛑 Stop & Cashout')
            .setStyle(ButtonStyle.Danger)
            .setDisabled(disabled),
    );
}

module.exports = {
    key: 'crash',

    data: new SlashCommandBuilder()
        .setName('crash')
        .setDescription('Crash — der Multiplikator steigt. Stoppe bevor er crasht!')
        .addIntegerOption(o =>
            o.setName('bet').setDescription('Einsatz').setRequired(true).setMinValue(1)
        ),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'crash')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const userId       = interaction.user.id;
        const guildId      = interaction.guildId;
        const bet          = interaction.options.getInteger('bet');
        const settings     = await getEcoSettings(botId, guildId);
        const gameKey      = `${botId}_${userId}`;
        const cooldownSecs = await getCommandSetting(botId, 'crash', 'cooldown', 0);

        if (getCrashGame(gameKey)) {
            return interaction.reply({ content: '⚠️ Du hast bereits ein laufendes Crash-Spiel.', ephemeral: true });
        }

        if (cooldownSecs > 0) {
            const cd = await getCooldownExpiry(botId, guildId, userId, 'crash');
            if (cd) {
                return interaction.reply({
                    content: `⏳ Warte noch **${formatRemaining(cd)}** bis zum nächsten Crash.`,
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

        // Deduct bet immediately
        await adjustWallet(botId, guildId, userId, -bet);

        const crashAt  = Math.ceil(Math.random() * 12); // 1–12 ticks before crash
        const state    = startCrash(gameKey, bet, crashAt);
        state.botId        = botId;
        state.guildId      = guildId;
        state.userId       = userId;
        state.settings     = settings;
        state.cooldownSecs = cooldownSecs;

        const content = () =>
            `💥 **Crash** | Einsatz: ${formatMoney(bet, settings)}\n📈 Multiplikator: **${state.multiplier.toFixed(2)}x** | Cashout: **${formatMoney(Math.floor(bet * state.multiplier), settings)}**`;

        await interaction.reply({
            content: content(),
            components: [buildCrashRow(userId)],
        });

        const intervalId = setInterval(async () => {
            const current = getCrashGame(gameKey);
            if (!current) { clearInterval(intervalId); return; }

            current.tick++;
            current.multiplier = parseFloat((1 + current.tick * 0.2).toFixed(2));

            if (current.tick >= current.crashAt) {
                // Crashed
                clearInterval(intervalId);
                endCrash(gameKey);
                if (cooldownSecs > 0) await setCooldown(botId, guildId, userId, 'crash', cooldownSecs);
                try {
                    await interaction.editReply({
                        content: `💥 **CRASH!** bei **${current.multiplier.toFixed(2)}x**\n💸 Du verlierst **${formatMoney(bet, settings)}**.`,
                        components: [buildCrashRow(userId, true)],
                    });
                } catch (_) {}
            } else {
                try {
                    await interaction.editReply({
                        content: content(),
                        components: [buildCrashRow(userId)],
                    });
                } catch (_) {}
            }
        }, 2000);

        state.intervalId = intervalId;
    },
};
