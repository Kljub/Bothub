const { SlashCommandBuilder } = require('discord.js');
const {
    isCommandEnabled, getCommandSetting, getEcoSettings, getOrCreateWallet, adjustWallet,
    getCooldownExpiry, setCooldown, formatMoney, formatRemaining,
} = require('../../services/economy-service');

const CRIMES = [
    'Hacking', 'Einbruch', 'Raub', 'Taschendiebstahl',
    'Drogenhandel', 'Waffenschmuggel', 'Straßenraub', 'Betrug',
];

module.exports = {
    key: 'crime',

    data: new SlashCommandBuilder()
        .setName('crime')
        .setDescription('Begehe ein Verbrechen und verdiene Geld — aber riskiere erwischt zu werden.'),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'crime')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const userId       = interaction.user.id;
        const guildId      = interaction.guildId;
        const settings     = await getEcoSettings(botId, guildId);
        const cooldownSecs = await getCommandSetting(botId, 'crime', 'cooldown', 600);

        if (cooldownSecs > 0) {
            const cd = await getCooldownExpiry(botId, guildId, userId, 'crime');
            if (cd) {
                return interaction.reply({
                    content: `⏳ Du musst noch **${formatRemaining(cd)}** warten bevor du wieder ein Verbrechen begehen kannst.`,
                    ephemeral: true,
                });
            }
        }

        const crime    = CRIMES[Math.floor(Math.random() * CRIMES.length)];
        const success  = Math.random() < 0.4; // 40 % Erfolgsrate
        const amount   = Math.floor(Math.random() * 80) + 1;

        if (cooldownSecs > 0) await setCooldown(botId, guildId, userId, 'crime', cooldownSecs);

        if (success) {
            await adjustWallet(botId, guildId, userId, amount);
            const wallet = await getOrCreateWallet(botId, guildId, userId);
            return interaction.reply({
                content: `🦹 **${crime}** — Du bist entkommen!\n+**${formatMoney(amount, settings)}** | Guthaben: **${formatMoney(wallet.wallet, settings)}**`,
            });
        } else {
            return interaction.reply({
                content: `🚔 **${crime}** — Du wurdest erwischt! Kein Gewinn.`,
            });
        }
    },
};
