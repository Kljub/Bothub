// PFAD: /core/installer/src/commands/work.js
const { SlashCommandBuilder } = require('discord.js');
const {
    isCommandEnabled, getEcoSettings, getOrCreateWallet, adjustWallet,
    getCooldownExpiry, setCooldown, formatMoney, formatRemaining, randInt,
} = require('../../services/economy-service');

const WORK_MESSAGES = [
    'Du hast Pakete ausgeliefert',
    'Du hast Nachhilfe gegeben',
    'Du hast Code geschrieben',
    'Du hast Rasen gemäht',
    'Du hast in einem Restaurant gearbeitet',
    'Du hast Artikel übersetzt',
    'Du hast Fotos bearbeitet',
    'Du hast Werbeplakate aufgehängt',
    'Du hast als Kassierer gearbeitet',
    'Du hast Freelancer-Aufträge erledigt',
];

module.exports = {
    key: 'work',

    data: new SlashCommandBuilder()
        .setName('work')
        .setDescription('Arbeite und verdiene Geld.'),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'work')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const userId   = interaction.user.id;
        const guildId  = interaction.guildId;
        const settings = await getEcoSettings(botId, guildId);

        const cooldown = await getCooldownExpiry(botId, guildId, userId, 'work');
        if (cooldown) {
            return interaction.reply({
                content: `⏳ Du kannst erst in **${formatRemaining(cooldown)}** wieder arbeiten.`,
                ephemeral: true,
            });
        }

        const earned = randInt(Number(settings.work_min || 50), Number(settings.work_max || 150));
        const msg    = WORK_MESSAGES[Math.floor(Math.random() * WORK_MESSAGES.length)];

        await getOrCreateWallet(botId, guildId, userId);
        await adjustWallet(botId, guildId, userId, earned);
        await setCooldown(botId, guildId, userId, 'work', 3600);

        await interaction.reply({
            content: `💼 **${msg}** und **${formatMoney(earned, settings)}** verdient!`,
        });
    },
};
