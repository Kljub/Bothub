const { SlashCommandBuilder } = require('discord.js');
const {
    isCommandEnabled, getCommandSetting, getEcoSettings, getOrCreateWallet, adjustWallet,
    getCooldownExpiry, setCooldown, formatMoney, formatRemaining,
} = require('../../services/economy-service');

// Roulette: 0 = green (1/37 chance, ×15), odd = red (×1.5), even≠0 = black (×2)
module.exports = {
    key: 'roulette',

    data: new SlashCommandBuilder()
        .setName('roulette')
        .setDescription('Roulette spielen — setze auf eine Farbe.')
        .addStringOption(o =>
            o.setName('color')
             .setDescription('Farbe wählen')
             .setRequired(true)
             .addChoices(
                 { name: '⬛ Schwarz  (×2)',  value: 'black' },
                 { name: '🟥 Rot     (×1.5)', value: 'red'   },
                 { name: '🟩 Grün    (×15)',  value: 'green'  },
             )
        )
        .addIntegerOption(o =>
            o.setName('bet').setDescription('Einsatz').setRequired(true).setMinValue(1)
        ),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'roulette')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const userId       = interaction.user.id;
        const guildId      = interaction.guildId;
        const color        = interaction.options.getString('color');
        const bet          = interaction.options.getInteger('bet');
        const settings     = await getEcoSettings(botId, guildId);
        const cooldownSecs = await getCommandSetting(botId, 'roulette', 'cooldown', 0);

        if (cooldownSecs > 0) {
            const cd = await getCooldownExpiry(botId, guildId, userId, 'roulette');
            if (cd) {
                return interaction.reply({
                    content: `⏳ Warte noch **${formatRemaining(cd)}** bis zum nächsten Roulette.`,
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

        const spin = Math.floor(Math.random() * 37); // 0–36

        let spinColor, spinEmoji;
        if (spin === 0)            { spinColor = 'green'; spinEmoji = '🟩'; }
        else if (spin % 2 === 1)   { spinColor = 'red';   spinEmoji = '🟥'; }
        else                       { spinColor = 'black'; spinEmoji = '⬛'; }

        const colorNames = { black: 'Schwarz', red: 'Rot', green: 'Grün' };

        if (cooldownSecs > 0) await setCooldown(botId, guildId, userId, 'roulette', cooldownSecs);

        if (spinColor === color) {
            const multipliers = { green: 15, red: 1.5, black: 2 };
            const winAmount   = Math.floor(bet * multipliers[color]);
            await adjustWallet(botId, guildId, userId, winAmount);
            const updated = await getOrCreateWallet(botId, guildId, userId);
            return interaction.reply({
                content: `🎡 Die Kugel landet auf **${spin}** ${spinEmoji} **${colorNames[spinColor]}**!\n🎉 Du gewinnst **${formatMoney(winAmount, settings)}** (×${multipliers[color]})!\nGuthaben: **${formatMoney(updated.wallet, settings)}**`,
            });
        } else {
            await adjustWallet(botId, guildId, userId, -bet);
            const updated = await getOrCreateWallet(botId, guildId, userId);
            return interaction.reply({
                content: `🎡 Die Kugel landet auf **${spin}** ${spinEmoji} **${colorNames[spinColor]}**.\n💸 Du verlierst **${formatMoney(bet, settings)}**.\nGuthaben: **${formatMoney(updated.wallet, settings)}**`,
            });
        }
    },
};
