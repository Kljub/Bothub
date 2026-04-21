const { SlashCommandBuilder } = require('discord.js');
const {
    isCommandEnabled, getCommandSetting, getEcoSettings, getOrCreateWallet, adjustWallet,
    getCooldownExpiry, setCooldown, formatMoney, formatRemaining,
} = require('../../services/economy-service');

const ANIMALS = [
    { name: 'Hase 🐰',       min: 5,  max: 15 },
    { name: 'Frosch 🐸',      min: 3,  max: 10 },
    { name: 'Affe 🐒',        min: 8,  max: 20 },
    { name: 'Huhn 🐔',        min: 4,  max: 12 },
    { name: 'Wolf 🐺',        min: 15, max: 35 },
    { name: 'Truthahn 🦃',    min: 6,  max: 18 },
    { name: 'Eichhörnchen 🐿️', min: 5, max: 14 },
    { name: 'Wildschwein 🐗', min: 10, max: 28 },
    { name: 'Hirsch 🦌',      min: 12, max: 30 },
    { name: 'Ente 🦆',        min: 3,  max: 8  },
];

module.exports = {
    key: 'hunt',

    data: new SlashCommandBuilder()
        .setName('hunt')
        .setDescription('Auf die Jagd gehen und Tiere fangen.'),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'hunt')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const userId       = interaction.user.id;
        const guildId      = interaction.guildId;
        const settings     = await getEcoSettings(botId, guildId);
        const cooldownSecs = await getCommandSetting(botId, 'hunt', 'cooldown', 60);

        if (cooldownSecs > 0) {
            const cd = await getCooldownExpiry(botId, guildId, userId, 'hunt');
            if (cd) {
                return interaction.reply({
                    content: `⏳ Du musst noch **${formatRemaining(cd)}** warten bevor du wieder jagen kannst.`,
                    ephemeral: true,
                });
            }
        }

        const animal  = ANIMALS[Math.floor(Math.random() * ANIMALS.length)];
        const reward  = Math.floor(Math.random() * (animal.max - animal.min + 1)) + animal.min;

        await adjustWallet(botId, guildId, userId, reward);
        if (cooldownSecs > 0) await setCooldown(botId, guildId, userId, 'hunt', cooldownSecs);

        const wallet = await getOrCreateWallet(botId, guildId, userId);
        await interaction.reply({
            content: `🏹 Du hast einen **${animal.name}** gefangen und **${formatMoney(reward, settings)}** verdient!\nGuthaben: **${formatMoney(wallet.wallet, settings)}**`,
        });
    },
};
