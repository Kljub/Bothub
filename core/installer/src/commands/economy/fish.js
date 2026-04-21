// PFAD: /core/installer/src/commands/fish.js
const { SlashCommandBuilder } = require('discord.js');
const {
    isCommandEnabled, getCommandSetting, getEcoSettings, getOrCreateWallet, adjustWallet,
    getCooldownExpiry, setCooldown, formatMoney, formatRemaining, randomFish,
} = require('../../services/economy-service');

module.exports = {
    key: 'fish',

    data: new SlashCommandBuilder()
        .setName('fish')
        .setDescription('Angel und hoffe auf guten Fang! (30 Min Cooldown)'),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'fish')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const userId   = interaction.user.id;
        const guildId  = interaction.guildId;
        const settings = await getEcoSettings(botId, guildId);

        const cooldownSecs = await getCommandSetting(botId, 'fish', 'cooldown', 1800);
        if (cooldownSecs > 0) {
            const cooldown = await getCooldownExpiry(botId, guildId, userId, 'fish');
            if (cooldown) {
                return interaction.reply({
                    content: `⏳ Deine Angel ist noch nicht bereit. Warte noch **${formatRemaining(cooldown)}**.`,
                    ephemeral: true,
                });
            }
        }

        const catch_  = randomFish();
        await getOrCreateWallet(botId, guildId, userId);
        if (catch_.value > 0) {
            await adjustWallet(botId, guildId, userId, catch_.value);
        }
        if (cooldownSecs > 0) {
            await setCooldown(botId, guildId, userId, 'fish', cooldownSecs);
        }

        const content = catch_.value > 0
            ? `🎣 Du hast **${catch_.emoji} ${catch_.name}** gefangen! (+**${formatMoney(catch_.value, settings)}**)`
            : `🎣 Du hast **${catch_.emoji} ${catch_.name}** gefangen... Das war wohl nichts.`;

        await interaction.reply({ content });
    },
};
