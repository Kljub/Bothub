// PFAD: /core/installer/src/commands/buy.js
const { SlashCommandBuilder } = require('discord.js');
const {
    isCommandEnabled, getEcoSettings, buyItem, formatMoney,
} = require('../../services/economy-service');

module.exports = {
    key: 'buy',

    data: new SlashCommandBuilder()
        .setName('buy')
        .setDescription('Item aus dem Shop kaufen.')
        .addIntegerOption(o =>
            o.setName('item_id').setDescription('ID des Items aus /shop').setRequired(true).setMinValue(1)
        ),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'buy')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const userId   = interaction.user.id;
        const guildId  = interaction.guildId;
        const itemId   = interaction.options.getInteger('item_id');
        const settings = await getEcoSettings(botId, guildId);

        try {
            const item = await buyItem(botId, guildId, userId, itemId);
            await interaction.reply({
                content: `✅ Du hast **${item.emoji} ${item.name}** für **${formatMoney(item.price, settings)}** gekauft!`,
                ephemeral: true,
            });
        } catch (err) {
            await interaction.reply({ content: `❌ ${err.message}`, ephemeral: true });
        }
    },
};
