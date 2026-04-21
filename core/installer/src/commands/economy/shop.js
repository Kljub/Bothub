// PFAD: /core/installer/src/commands/shop.js
const { SlashCommandBuilder } = require('discord.js');
const {
    isCommandEnabled, getEcoSettings, getShopItems, formatMoney,
} = require('../../services/economy-service');

module.exports = {
    key: 'shop',

    data: new SlashCommandBuilder()
        .setName('shop')
        .setDescription('Zeigt den Shop an.'),

    async execute(interaction, botId) {
        if (!await isCommandEnabled(botId, 'shop')) {
            return interaction.reply({ content: '❌ Dieser Command ist nicht aktiviert.', ephemeral: true });
        }
        if (!interaction.inGuild()) {
            return interaction.reply({ content: '❌ Nur auf Servern verfügbar.', ephemeral: true });
        }

        const guildId  = interaction.guildId;
        const settings = await getEcoSettings(botId, guildId);
        const items    = await getShopItems(botId, guildId);

        if (items.length === 0) {
            return interaction.reply({
                content: '🛒 Der Shop ist leer. Ein Admin kann Items im Dashboard hinzufügen.',
                ephemeral: true,
            });
        }

        const lines = ['**🛒 Shop**', ''];
        for (const item of items) {
            const stock = Number(item.stock) < 0 ? '∞' : String(item.stock);
            lines.push(`${item.emoji} **${item.name}** — ${formatMoney(item.price, settings)} *(ID: ${item.id} | Bestand: ${stock})*`);
            if (item.description) lines.push(`   *${item.description}*`);
        }
        lines.push('', 'Kaufe mit `/buy item_id:<ID>`');

        await interaction.reply({ content: lines.join('\n'), ephemeral: true });
    },
};
